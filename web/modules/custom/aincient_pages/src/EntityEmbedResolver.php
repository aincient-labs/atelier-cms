<?php

declare(strict_types=1);

namespace Drupal\aincient_pages;

use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;

/**
 * Resolves an embed-reference TOKEN to a URL, alt text, or a render array.
 *
 * The page schema stores references to Drupal entities as opaque string tokens
 * inside the (translatable) content layer — never inlined URLs. A token names
 * an entity and, optionally, a view mode:
 *
 *   entity:<type>:<id>            e.g. entity:media:42        (default view mode)
 *   entity:<type>:<id>@<viewmode> e.g. entity:node:15@teaser
 *   media:<id>                    sugar for entity:media:<id>
 *   block:<id>                    a global block (aincient_block node), expanded
 *                                 inline — NOT view-built (see below)
 *
 * `block:<id>` is a LOGICAL reference type, not a Drupal entity type: it names an
 * `aincient_block` node whose sections are spliced into the host page
 * ({@see PageSpikeController}), so {@see render()}/{@see url()} deliberately do not
 * resolve it. It is parseable here only so the reference layer (the descriptor
 * catalog, the page-schema clamp) can reason over all three token shapes uniformly.
 *
 * Resolution mirrors the brand-logo precedent (BrandRepository::logoUrl), but
 * generalised: the universal Drupal primitive is the entity VIEW BUILDER, which
 * already renders a media item as a proper <img> (image style + alt) and a node
 * as its teaser. Phase 4a consumes url()/alt() to feed the existing string-prop
 * twigs; render() is the entity-general path for Phase 4b (the `embed` section).
 *
 * This service is deliberately page-agnostic: it knows tokens and entities, not
 * which page prop wants which image style — that mapping lives in the caller.
 */
final class EntityEmbedResolver {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
    private readonly EntityRepositoryInterface $entityRepository,
    private readonly ?PluginManagerInterface $riftViewModes,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Parse a token into ['type' => …, 'id' => …, 'view_mode' => ?string].
   *
   * Returns NULL for anything that isn't a well-formed token, so callers can
   * pass arbitrary prop values through unchanged (back-compat with raw URLs).
   */
  public function parse(string $token): ?array {
    $token = trim($token);
    // Sugar: media:42  ->  entity:media:42
    if (preg_match('/^media:(\d+)$/', $token, $m)) {
      return ['type' => 'media', 'id' => (int) $m[1], 'view_mode' => NULL];
    }
    // A global block (aincient_block node) — a logical ref type, expanded inline
    // rather than view-built. parse()-able so the reference layer treats it like
    // the others; url()/render() never resolve a 'block' type.
    if (preg_match('/^block:(\d+)$/', $token, $m)) {
      return ['type' => 'block', 'id' => (int) $m[1], 'view_mode' => NULL];
    }
    // entity:<type>:<id>[@<view_mode>]
    if (preg_match('/^entity:([a-z][a-z0-9_]*):(\d+)(?:@([a-z0-9_]+))?$/', $token, $m)) {
      return [
        'type' => $m[1],
        'id' => (int) $m[2],
        'view_mode' => ($m[3] ?? '') !== '' ? $m[3] : NULL,
      ];
    }
    return NULL;
  }

  /** TRUE if the value is a resolvable embed token (cheap, no entity load). */
  public function isToken(mixed $value): bool {
    return is_string($value) && $this->parse($value) !== NULL;
  }

  /**
   * Statically test whether a string is a well-formed embed token — no entity
   * load, no service needed. The grammar is the same as {@see parse()}; this is
   * the form {@see \Drupal\aincient_pages\PageStore} uses to clamp an `embed`
   * section's `entity` prop without depending on the resolver service.
   */
  public static function isWellFormed(string $token): bool {
    $token = trim($token);
    return (bool) (preg_match('/^media:\d+$/', $token)
      || preg_match('/^block:\d+$/', $token)
      || preg_match('/^entity:[a-z][a-z0-9_]*:\d+(?:@[a-z0-9_]+)?$/', $token));
  }

  /**
   * Flatten a token to a URL string, or NULL if unresolvable.
   *
   * For a media:image token: source file URL, optionally through an image style.
   * For any other entity: its canonical URL. Non-tokens / dangling refs → NULL.
   */
  public function url(string $token, ?string $imageStyle = NULL): ?string {
    $ref = $this->parse($token);
    if (!$ref) {
      return NULL;
    }
    $entity = $this->load($ref['type'], $ref['id']);
    if (!$entity) {
      return NULL;
    }

    if ($ref['type'] === 'media') {
      $uri = $this->mediaFileUri($entity);
      if ($uri === NULL) {
        return NULL;
      }
      if ($imageStyle !== NULL && $imageStyle !== '') {
        $style = $this->entityTypeManager->getStorage('image_style')->load($imageStyle);
        if ($style) {
          // Relative URL so it survives behind the appliance reverse proxy.
          return $this->fileUrlGenerator->transformRelative($style->buildUrl($uri));
        }
      }
      return $this->fileUrlGenerator->generateString($uri);
    }

    // Generic entity: its canonical link.
    return $entity->hasLinkTemplate('canonical')
      ? $entity->toUrl('canonical')->toString()
      : NULL;
  }

  /**
   * Flatten a reference token to a LINK target URL, or NULL if it must not be
   * linked.
   *
   * The link counterpart to {@see url()}, and deliberately STRICTER: a CTA target
   * is followed by a visitor, so it is access-checked in the render language. A
   * page that was deleted, unpublished, or is otherwise unviewable resolves to
   * NULL and the caller DROPS the affordance ({@see resolveLinks()}) rather than
   * shipping a button into a 403 or a `#`. `block:` tokens (fragments spliced
   * inline — no canonical URL of their own) never link either.
   *
   * A media token DOES resolve, to its canonical media page rather than the raw
   * file: a link prop is a navigation target, not an <img src>.
   */
  public function linkUrl(string $token, ?string $langcode = NULL): ?string {
    $ref = $this->parse($token);
    if (!$ref || $ref['type'] === 'block') {
      return NULL;
    }
    $entity = $this->load($ref['type'], $ref['id']);
    if (!$entity instanceof EntityInterface || !$entity->hasLinkTemplate('canonical')) {
      return NULL;
    }
    if ($langcode !== NULL && $entity->hasTranslation($langcode)) {
      $entity = $entity->getTranslation($langcode);
    }
    return $entity->access('view') ? $entity->toUrl('canonical')->toString() : NULL;
  }

  /**
   * Resolve every LINK-bearing prop in a prop tree, dropping dead affordances.
   *
   * The render-time seam for link tokens — the counterpart to the controller's
   * image-token pass, but with the opposite failure mode. An unresolvable IMAGE
   * collapses to '' and the twig's `{% if image %}` simply hides it; an
   * unresolvable LINK must take its LABEL with it, because a button whose target
   * vanished still renders (the twigs gate the anchor on `cta_label`, not on the
   * url) and would ship a live-looking `#` button. So a dangling target unsets
   * BOTH halves of the pair ({@see ComponentCatalog::LINK_LABELS}); a link prop
   * with no paired label (a logo's `url`) just loses its url and renders
   * unlinked.
   *
   * Recurses into arrays so tokens inside repeatables (grid cards, pricing
   * tiers, logos) resolve on the same terms as top-level props. Raw URLs and
   * plain paths pass through untouched.
   */
  public function resolveLinks(array $props, ?string $langcode = NULL): array {
    foreach ($props as $key => $value) {
      if (is_array($value)) {
        $props[$key] = $this->resolveLinks($value, $langcode);
        continue;
      }
      if (!is_string($key) || !is_string($value) || !ComponentCatalog::isLinkProp($key)) {
        continue;
      }
      $token = trim($value);
      if (!$this->isToken($token)) {
        continue;
      }
      $url = $this->linkUrl($token, $langcode);
      if ($url !== NULL) {
        $props[$key] = $url;
        continue;
      }
      unset($props[$key]);
      $label = ComponentCatalog::LINK_LABELS[$key] ?? NULL;
      if ($label !== NULL) {
        unset($props[$label]);
      }
    }
    return $props;
  }

  /**
   * Flatten a media:image token to an ABSOLUTE file URL, or NULL.
   *
   * The crawler-facing counterpart to {@see url()}: og:image and friends are read
   * straight out of the page HEAD by external services (LinkedIn/Slack/X), which
   * can neither resolve a token nor a reverse-proxy-relative path — they need a
   * fully-qualified, directly-fetchable URL. Only media tokens resolve; a non-media
   * token or a dangling/sourceless reference → NULL, so the caller can leave a raw
   * URL (or any non-token value) untouched. Serves the original file (no image
   * style) — crawlers want the full-size share image, and it sidesteps the derivative
   * itok query.
   */
  public function absoluteUrl(string $token): ?string {
    $ref = $this->parse($token);
    if (!$ref || $ref['type'] !== 'media') {
      return NULL;
    }
    $entity = $this->load('media', $ref['id']);
    if (!$entity) {
      return NULL;
    }
    $uri = $this->mediaFileUri($entity);
    return $uri === NULL ? NULL : $this->fileUrlGenerator->generateAbsoluteString($uri);
  }

  /**
   * The source File entity behind a media token, or NULL.
   *
   * For callers that need the raw file itself — its exact bytes, URI, or MIME —
   * rather than a styled derivative URL (e.g. a favicon `<link>`, which browsers
   * want served as uploaded, with its own `type`). Only media tokens resolve.
   */
  public function mediaFile(string $token): ?FileInterface {
    $ref = $this->parse($token);
    if (!$ref || $ref['type'] !== 'media') {
      return NULL;
    }
    $media = $this->load('media', $ref['id']);
    if (!$media instanceof MediaInterface) {
      return NULL;
    }
    $fid = $media->getSource()->getSourceFieldValue($media);
    if (!$fid) {
      return NULL;
    }
    $file = $this->entityTypeManager->getStorage('file')->load((int) $fid);
    return $file instanceof FileInterface ? $file : NULL;
  }

  /** Alt text for a media:image token (from the source field), or NULL. */
  public function alt(string $token): ?string {
    $ref = $this->parse($token);
    if (!$ref || $ref['type'] !== 'media') {
      return NULL;
    }
    $media = $this->load('media', $ref['id']);
    if (!$media) {
      return NULL;
    }
    $field = $media->getSource()->getConfiguration()['source_field'] ?? '';
    if ($field === '' || !$media->hasField($field) || $media->get($field)->isEmpty()) {
      return NULL;
    }
    $alt = $media->get($field)->first()->get('alt')->getValue();
    return is_string($alt) && $alt !== '' ? $alt : NULL;
  }

  /**
   * The entity-general path (Phase 4b): a render array via the view builder.
   *
   * Renders the entity in the requested view mode (default if none), in the
   * given language when a translation exists. Returns NULL if unresolvable.
   */
  public function render(string $token, ?string $langcode = NULL): ?array {
    $ref = $this->parse($token);
    if (!$ref) {
      return NULL;
    }
    $entity = $this->load($ref['type'], $ref['id']);
    if (!$entity) {
      return NULL;
    }
    if ($langcode !== NULL && $entity->hasTranslation($langcode)) {
      $entity = $entity->getTranslation($langcode);
    }
    return $this->entityTypeManager
      ->getViewBuilder($ref['type'])
      ->view($entity, $ref['view_mode'] ?? 'default', $langcode);
  }

  /**
   * Render a media:image token as a Rift responsive <picture> render array.
   *
   * The presentation counterpart to {@see url()}: instead of flattening a media
   * token to one image-style URL (a single fixed-size <img>), this resolves it
   * to a Rift picture in the named view mode — a real <picture> with srcset +
   * format negotiation, or (when $thirdPartySettings enables container queries)
   * a container-sized background element. The view mode names a config bundle in
   * rift.settings (aspect ratio, sizes, breakpoints); the (component, prop) →
   * view-mode choice is the caller's (see PageSpikeController), keeping this
   * service page-agnostic exactly as {@see url()}/{@see render()} are.
   *
   * Returns NULL for any non-media token or dangling/styleless reference, so the
   * caller can fall back to the URL path (raw URLs, other entity types).
   *
   * Mirrors RiftImagePictureFormatter::buildRenderable — the rift_picture twig
   * filter does the actual work, so the picture builds lazily at render with the
   * media entity's cache metadata bubbled up.
   */
  public function picture(string $token, string $viewMode, ?string $langcode = NULL, array $thirdPartySettings = []): ?array {
    // Soft integration: no rift → no responsive picture (caller falls back).
    if ($this->riftViewModes === NULL) {
      return NULL;
    }
    $ref = $this->parse($token);
    if (!$ref || $ref['type'] !== 'media') {
      return NULL;
    }
    $media = $this->load('media', $ref['id']);
    if (!$media instanceof MediaInterface) {
      return NULL;
    }
    if ($langcode !== NULL && $media->hasTranslation($langcode)) {
      $media = $media->getTranslation($langcode);
    }

    $definitions = $this->riftViewModes->getDefinitions();
    $config = $definitions[$viewMode] ?? [];
    if ($thirdPartySettings !== []) {
      $config['third_party_settings'] = $thirdPartySettings;
    }

    return [
      '#type' => 'inline_template',
      '#template' => '{{ media|rift_picture(config) }}',
      '#context' => ['media' => $media, 'config' => $config],
      '#cache' => [
        'tags' => Cache::mergeTags(
          $media->getCacheTags(),
          $this->configFactory->get('rift.settings')->getCacheTags()
        ),
        'contexts' => $media->getCacheContexts(),
        'max-age' => $media->getCacheMaxAge(),
        'keys' => ['entity_view', 'media', (string) $media->id(), $viewMode],
      ],
    ];
  }

  /**
   * Load an entity by type+id, resolving to the current-context translation.
   */
  private function load(string $type, int $id): ?object {
    if (!$this->entityTypeManager->hasDefinition($type)) {
      return NULL;
    }
    $entity = $this->entityTypeManager->getStorage($type)->load($id);
    return $entity ? $this->entityRepository->getTranslationFromContext($entity) : NULL;
  }

  /** The file URI behind a media:image entity, or NULL. */
  private function mediaFileUri(object $media): ?string {
    $fid = $media->getSource()->getSourceFieldValue($media);
    if (!$fid) {
      return NULL;
    }
    $file = $this->entityTypeManager->getStorage('file')->load((int) $fid);
    return $file ? $file->getFileUri() : NULL;
  }

}
