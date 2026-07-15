<?php

declare(strict_types=1);

namespace Drupal\aincient_pages\Controller;

use Drupal\aincient_pages\BlockStore;
use Drupal\aincient_pages\BrandRepository;
use Drupal\aincient_pages\ComponentCatalog;
use Drupal\aincient_pages\ConsentSettings;
use Drupal\aincient_pages\EntityEmbedResolver;
use Drupal\aincient_pages\MarkdownRenderer;
use Drupal\aincient_pages\PageStore;
use Drupal\aincient_pages\SiteIdentity;
use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\aincient_pages\SiteChrome;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Render\RendererInterface;
use Drupal\node\Controller\NodeViewController;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Renders an agent page-schema into a composed page (SPIKE).
 *
 * This is the moat: a page-schema (JSON) → SDC render pipeline, with two
 * regimes the agent must respect:
 *
 *  - BLOG    a LOCKED recipe. The agent supplies content only; layout is fixed
 *            (header → prose → byline). Consistency by construction.
 *  - LANDING a composition GRAMMAR. The agent arranges a list of section
 *            components, each constrained to enumerated variants/tones (see the
 *            .component.yml schemas). Expressive but never ugly.
 *
 * The page-schema is exactly what the LLM will emit; here we hand-author briefs
 * (briefs/*.json) to prove the rendering + guardrails without LLM variance.
 */
final class PageSpikeController implements ContainerInjectionInterface {

  public function __construct(
    private readonly RendererInterface $renderer,
    private readonly ModuleExtensionList $moduleList,
    private readonly BrandRepository $brand,
    private readonly SiteChrome $chrome,
    private readonly ClassResolverInterface $classResolver,
    private readonly PageStore $store,
    private readonly EntityEmbedResolver $embed,
    private readonly BlockStore $blocks,
    private readonly MarkdownRenderer $markdown,
    private readonly ConsentSettings $consent,
    private readonly SiteIdentity $identity,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('renderer'),
      $container->get('extension.list.module'),
      $container->get('aincient_pages.brand'),
      $container->get('aincient_pages.chrome'),
      $container->get('class_resolver'),
      $container->get('aincient_pages.store'),
      $container->get('aincient_pages.embed_resolver'),
      $container->get('aincient_pages.block_store'),
      $container->get('aincient_pages.markdown'),
      $container->get('aincient_pages.consent'),
      $container->get('aincient_pages.site_identity'),
    );
  }

  /**
   * Render an in-memory page-schema to a chrome-less response, WITHOUT persisting.
   *
   * The page studio's live-preview seam: the studio POSTs its working draft and
   * gets back the same chrome-less HTML a saved page would render, so the iframe
   * shows exactly what Publish will produce. The caller (PageController) clamps
   * the schema through PageStore first; this just composes + renders it.
   */
  public function renderSchema(array $schema): Response {
    return $this->respond($schema);
  }

  /**
   * Render the site chrome (header + footer) around a placeholder body, WITHOUT
   * persisting — the Header/Footer studios' live-preview seam.
   *
   * The caller (ChromeController) builds the header/footer props from the studio
   * DRAFT (draft chrome layout + draft identity + draft menus), so the iframe
   * shows exactly what Publish will produce. Reuses the same shell + brand CSS +
   * web fonts as a real page, so the chrome reskins with the live Foundations.
   */
  public function renderChrome(array $headerProps, array $footerProps): Response {
    $build = [
      $this->component('site-header', $headerProps),
      $this->chromePreviewBody(),
      $this->component('site-footer', $footerProps),
    ];
    $content = (string) $this->renderer->renderInIsolation($build);
    $path = $this->moduleList->getPath('aincient_pages');
    $css = base_path() . $path . '/assets/aincient-pages.css?v=' . @filemtime("$path/assets/aincient-pages.css");
    // Chrome preview is always a studio surface — never show the consent banner.
    return new Response($this->shell($content, $css, 'Chrome preview', $this->brand->cssVariables(), '', FALSE));
  }

  /** A neutral placeholder page body so chrome is previewed in real context. */
  private function chromePreviewBody(): array {
    return [
      '#type' => 'inline_template',
      '#template' => <<<TWIG
        <main class="mx-auto max-w-7xl px-6 py-20">
          <p class="text-sm font-semibold uppercase tracking-wide text-primary">Preview</p>
          <h1 class="mt-3 font-display text-4xl font-bold tracking-tight text-foreground">Your site, framed by its chrome</h1>
          <p class="mt-4 max-w-2xl text-lg text-muted-foreground">Placeholder page content, so you can see how the header and footer wrap a real page. Edit them on the left — this preview updates live.</p>
          <div class="mt-12 grid gap-4 sm:grid-cols-3">
            {% for i in 1..3 %}
              <div class="rounded-[var(--card-radius)] border border-[color:var(--card-border)] bg-card p-6 text-card-foreground shadow-[var(--card-shadow)]">
                <p class="font-display text-lg font-semibold">Section {{ i }}</p>
                <p class="mt-2 text-sm text-muted-foreground">A card of body content to give the page some weight.</p>
              </div>
            {% endfor %}
          </div>
        </main>
      TWIG,
    ];
  }

  /** Render a hand-authored brief file (the spike demos). */
  public function render(string $brief): Response {
    $path = $this->moduleList->getPath('aincient_pages');
    $data = json_decode((string) file_get_contents("$path/briefs/$brief.json"), TRUE);
    return $this->respond($data);
  }

  /**
   * The node canonical controller (installed via PageRouteSubscriber).
   *
   * aincient_page nodes render chrome-less from their page-schema; every other
   * bundle is delegated to the stock NodeViewController, unchanged.
   */
  public function nodeCanonical(NodeInterface $node, string $view_mode = 'full', ?string $langcode = NULL): mixed {
    if ($node->bundle() === 'aincient_page' && $node->hasField('field_page_structure')) {
      // $node arrives in the active content language; resolve() merges its
      // structure + content layers (inheriting the source layout / copy where
      // this translation hasn't diverged) back into the renderable schema.
      return $this->respond($this->store->resolve($node), $node);
    }
    return $this->classResolver
      ->getInstanceFromDefinition(NodeViewController::class)
      ->view($node, $view_mode, $langcode);
  }

  /** Shared: compose a page-schema → SDC → chrome-less HTML response. */
  private function respond(array $data, ?NodeInterface $node = NULL): Response {
    // The language to resolve embeds + global blocks in (the page's own, or NULL
    // for the spike briefs / stateless preview → current content language).
    $langcode = $node?->language()->getId();
    // Stamp each placed section with its stable slot id for the studio's
    // click-to-focus, but ONLY on the stateless preview seam ($node === NULL);
    // the canonical published render stays byte-identical (no editor hooks leak
    // onto the live site).
    $inner = ($data['type'] ?? '') === 'blog'
      ? $this->composeBlog($data)
      : $this->composeLanding($data, $langcode, $node === NULL);

    // Wrap every page in the brand header + footer (the site chrome).
    $build = array_merge([$this->siteHeader()], $inner, [$this->siteFooter()]);

    $content = (string) $this->renderer->renderInIsolation($build);

    // Operator chrome (the editor pill) — only on a REAL canonical render, never
    // the studio's stateless preview / spike briefs ($node === NULL, which live
    // inside the console already). hook_aincient_pages_shell_bottom() lets
    // sibling modules (aincient_chat) contribute a render array; because this
    // bespoke shell hand-builds its <head> and never runs the #attached
    // pipeline, each contribution must be SELF-CONTAINED (it links its own CSS).
    if ($node !== NULL) {
      // invokeAllWith (not invokeAll) so each module's render array stays whole —
      // invokeAll would array_merge them and collide on shared keys like #markup.
      \Drupal::moduleHandler()->invokeAllWith(
        'aincient_pages_shell_bottom',
        function (callable $hook) use ($node, &$content): void {
          $bottom = $hook($node);
          if (is_array($bottom) && $bottom !== []) {
            $content .= (string) $this->renderer->renderInIsolation($bottom);
          }
        },
      );
    }

    $path = $this->moduleList->getPath('aincient_pages');
    $css = base_path() . $path . '/assets/aincient-pages.css?v=' . @filemtime("$path/assets/aincient-pages.css");
    // The site brand: a :root override injected after the stylesheet, so the
    // design tokens reskin every component with no rebuild.
    $brandCss = $this->brand->cssVariables();
    // SEO: the bespoke shell bypasses Drupal's <head> pipeline, so render the
    // node's metatag tags (title/description/canonical/OG) into it directly.
    $metaHtml = $node ? $this->metatagHead($node) : '';
    // Consent banner only on a REAL canonical render; the stateless studio
    // preview ($node === NULL) suppresses it (see shell()).
    return new Response($this->shell($content, $css, $data['title'] ?? 'AIncient', $brandCss, $metaHtml, $node !== NULL));
  }

  /**
   * Render the node's metatag output as <head> HTML (empty if metatag absent).
   */
  private function metatagHead(NodeInterface $node): string {
    if (!\Drupal::hasService('metatag.manager')) {
      return '';
    }
    $manager = \Drupal::service('metatag.manager');
    $tags = $manager->tagsFromEntityWithDefaults($node);
    // Fire hook_metatags_alter() — the same override seam the standard route
    // render (metatag_get_tags_from_route) provides. The chrome-less page builds
    // its head straight off the manager, so without this an alter like the
    // og_image token → absolute-URL resolution (aincient_pages_metatags_alter)
    // would never reach it. Mirrors metatag.module's invocation shape.
    $entity = $node;
    $context = ['entity' => &$entity];
    \Drupal::service('module_handler')->alter('metatags', $tags, $context);
    $elements = $manager->generateRawElements($tags, $entity);
    $html = '';
    foreach ($elements as $element) {
      // metatag returns html_head-shaped arrays (#tag/#attributes/#value); they
      // need #type html_tag to render as standalone <head> markup.
      $element += ['#type' => 'html_tag'];
      $html .= (string) $this->renderer->renderInIsolation($element);
    }
    return $html;
  }

  /**
   * LANDING regime — compose the section list under the grammar guardrails.
   *
   * A `block` slot expands here: the referenced global block's OWN sections are
   * spliced inline in place of the slot (one level deep — a block can't contain
   * a block), so editing the block updates every page that references it.
   */
  private function composeLanding(array $data, ?string $langcode = NULL, bool $identify = FALSE): array {
    $build = [];
    // Tone rhythm: when a section doesn't pin a tone, alternate so stacked
    // sections don't blur together. (A guardrail the agent gets for free.)
    $rhythm = ['default', 'muted'];
    $r = 0;
    foreach ($data['sections'] ?? [] as $section) {
      $name = $section['component'] ?? '';
      // Guardrail: only known placeable components (sections + layout + refs).
      if (!in_array($name, ComponentCatalog::placeableNames(), TRUE)) {
        continue;
      }

      // Global block: splice the referenced block's resolved sections inline.
      // The ref is either a legacy `block:<id>` token (an aincient_block NODE) or,
      // as blocks migrate onto the media bundle (DECISIONS 0137), a `media:<id>`
      // token pointing at a `block` media entity — same scheme as media/embed.
      if ($name === 'block') {
        $ref = is_string($section['props']['ref'] ?? NULL) ? trim($section['props']['ref']) : '';
        $parsed = $ref !== '' ? $this->embed->parse($ref) : NULL;
        $inner = match ($parsed['type'] ?? '') {
          'block' => $this->blocks->resolveSections((string) $parsed['id'], $langcode),
          'media' => $this->blocks->resolveMediaSections((string) $parsed['id'], $langcode),
          default => NULL,
        };
        if ($inner === NULL) {
          continue;
        }
        foreach ($inner as $innerSection) {
          $b = $this->renderSection($innerSection, $langcode);
          if ($b !== NULL) {
            $build[] = $b;
          }
        }
        continue;
      }

      // Tone rhythm applies to the inline section palette only.
      if (!isset($section['props']['tone']) && in_array($name, ['features', 'stats'], TRUE)) {
        $section['props']['tone'] = $rhythm[$r++ % 2];
      }
      $b = $this->renderSection($section, $langcode);
      if ($b !== NULL) {
        $build[] = $identify ? $this->identifySection($b, (string) ($section['id'] ?? '')) : $b;
      }
    }
    return $build;
  }

  /**
   * Wrap a placed section's render array so the studio preview can map a click
   * in the canvas back to the section's editing card (click-to-focus).
   *
   * Only the stateless preview seam calls this ($identify in composeLanding) —
   * the canonical published render never does, so live pages carry no editor
   * hooks. The wrapper is rendered `display:contents` in the preview (a rule the
   * console injects into the iframe, {@see injectSelectionStyles}), so it emits
   * no box and cannot shift layout — it exists purely as a queryable, clickable
   * carrier for the stable slot id. A section with no id (shouldn't happen after
   * PageStore::validate) is left unwrapped rather than stamped with an empty id.
   */
  private function identifySection(array $build, string $id): array {
    if ($id === '') {
      return $build;
    }
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['ain-sec-wrap'], 'data-ain-sec' => $id],
      'section' => $build,
    ];
  }

  /**
   * Build ONE placed section to a render array (or NULL to skip it).
   *
   * Normal sections + layout containers render through the typed-prop SDC path
   * ({@see component()}). An `embed` resolves its `entity` token to a render array
   * via {@see EntityEmbedResolver::render()} and hands it to the component's `body`
   * SLOT — the entity-general path (Phase 4b), kept separate from the string-prop
   * path because a rendered entity is a render array, not a typed string. A `block`
   * is never passed here (it is expanded by the caller).
   */
  private function renderSection(array $section, ?string $langcode = NULL): ?array {
    $name = (string) ($section['component'] ?? '');
    $props = $section['props'] ?? [];

    if ($name === 'markdown') {
      // The authored `markdown` source becomes the SDC's sanitised `html` prop
      // (CommonMark + Xss). A render-string prop, so no slot is needed.
      $source = is_string($props['markdown'] ?? NULL) ? $props['markdown'] : '';
      $clean = $this->clean(array_diff_key($props, ['markdown' => TRUE]));
      $clean['html'] = $this->markdown->toSafeHtml($source);
      return $this->component('markdown', $clean, $langcode);
    }

    if ($name === 'accordion') {
      // Flatten each panel's bounded child blocks to a body_html string (an SDC
      // slot can't nest per array row — the ROW_PICTURES precedent), so the
      // renderer never nests SDC components and the flat model holds. The
      // <details name> exclusivity group is the section's stable slot id.
      $clean = $this->clean($props);
      $clean['panels'] = $this->renderPanels(is_array($props['panels'] ?? NULL) ? $props['panels'] : [], $langcode);
      $clean['name'] = 'acc-' . (string) ($section['id'] ?? substr(md5((string) json_encode($props)), 0, 8));
      // `exclusive` is a typed SDC boolean — cast defensively (PageStore already
      // coerces, but the SDC throws on a string, so never hand it one).
      if (isset($clean['exclusive']) && !is_bool($clean['exclusive'])) {
        $clean['exclusive'] = filter_var($clean['exclusive'], FILTER_VALIDATE_BOOLEAN);
      }
      return $this->component('accordion', $clean, $langcode);
    }

    if ($name === 'embed') {
      $token = is_string($props['entity'] ?? NULL) ? trim($props['entity']) : '';
      $rendered = $token !== '' ? $this->embed->render($token, $langcode) : NULL;
      // Nothing to show and no framing copy → skip the band entirely.
      $framing = $this->clean(array_diff_key($props, ['entity' => TRUE]));
      if ($rendered === NULL && $framing === []) {
        return NULL;
      }
      $build = $this->component('embed', $framing, $langcode);
      $build['#slots']['body'] = $rendered ?? ['#markup' => ''];
      return $build;
    }

    return $this->component($name, $this->clean($props), $langcode);
  }

  /**
   * Render an accordion's panels to the SDC shape `[{label, open, body_html}]`.
   *
   * Each panel's child blocks (gated again against ACCORDION_BLOCKS — defence in
   * depth behind PageStore) are rendered through the SAME section pipeline
   * ({@see renderSection}), so a child gets the identical prop validation, image
   * resolution and brand tokens as a top-level section, then flattened to one
   * HTML string. Blocks render in their `bare` variant (no section chrome —
   * every ACCORDION_BLOCKS member declares one) because the panel already owns
   * the surface band + spacing. This is the accordion analogue of
   * {@see rowPictures()}: a slot can't nest per array row, so we pre-render.
   */
  private function renderPanels(array $panels, ?string $langcode): array {
    $out = [];
    foreach ($panels as $panel) {
      if (!is_array($panel)) {
        continue;
      }
      $html = '';
      foreach (is_array($panel['blocks'] ?? NULL) ? $panel['blocks'] : [] as $block) {
        $name = is_array($block) ? (string) ($block['component'] ?? '') : '';
        if (!in_array($name, ComponentCatalog::ACCORDION_BLOCKS, TRUE)) {
          continue;
        }
        $childProps = is_array($block['props'] ?? NULL) ? $block['props'] : [];
        $childProps['variant'] = 'bare';
        $built = $this->renderSection(['component' => $name, 'props' => $childProps], $langcode);
        if ($built !== NULL) {
          $html .= (string) $this->renderer->renderInIsolation($built);
        }
      }
      $out[] = [
        'label' => (string) ($panel['label'] ?? ''),
        'open' => !empty($panel['open']),
        'body_html' => $html,
      ];
    }
    return $out;
  }

  /**
   * BLOG regime — a LOCKED recipe. Layout is fixed; only content varies.
   */
  private function composeBlog(array $data): array {
    $build = [];
    $build[] = $this->component('article-header', $this->clean([
      'eyebrow' => $data['category'] ?? NULL,
      'title' => $data['title'] ?? '',
      'lead' => $data['lead'] ?? NULL,
      'author' => $data['author'] ?? NULL,
      'date' => $data['date'] ?? NULL,
      'cover' => $data['cover'] ?? NULL,
    ]));
    // The blog body is authored as Markdown (body_md); compile it to sanitised
    // HTML here (CommonMark + Xss::filterAdmin) for the prose SDC. Render-cached
    // with the page, so the conversion is paid once per revision.
    $build[] = $this->component('prose', ['html' => $this->markdown->toSafeHtml((string) ($data['body_md'] ?? ''))]);
    if (!empty($data['author'])) {
      $build[] = $this->component('byline', $this->clean([
        'author' => $data['author'],
        'bio' => $data['author_bio'] ?? NULL,
      ]));
    }
    return $build;
  }

  /**
   * Per-(component, prop) IMAGE STYLE map — right-sizes each rendered image.
   *
   * The reference axis (Phase 4a) stored media as tokens but resolved them at
   * original size; this is the tuning pass that picks a fit-for-purpose core
   * image style per slot. Looked up `[component][prop]` first, then a by-prop-
   * name fallback under `'*'` (the component context is constant across a
   * section's whole prop tree, so a row field — `logos[].image`, an `avatar` —
   * resolves against the SAME component key as the section). Only media tokens
   * are styled; raw URLs still pass through untouched. Styles are all core
   * `image` module entities (shipped on every install).
   *
   * This is also the FINAL home for the images that DELIBERATELY don't go
   * through Rift (the responsive-image Phase 4 decision): `logos[].image` and
   * the people `avatar`s (`testimonials`, `team`). They're small, fixed-size,
   * decorative marks — a logo cloud is `h-8 w-auto object-contain`, an avatar a
   * 40–112px `rounded-full` — so a responsive <picture>/srcset or container
   * query buys nothing; a single right-sized core image style is correct and
   * cheaper. Not an oversight: they have no entry in VIEW_MODES / ROW_PICTURES
   * by design. See the module AGENTS.md (rift integration) + DECISIONS 2026-06-23.
   */
  private const IMAGE_STYLES = [
    // Full-bleed opener / blog lead → the widest style.
    'hero' => ['image' => 'wide'],
    // A quiet row of marks → the small-but-not-tiny style (decorative, NOT Rift).
    'logos' => ['image' => 'medium'],
    // By prop-name fallback (content/gallery/grid images use Rift now; `avatar`s
    // stay here on a plain thumbnail by design — decorative, fixed-size).
    '*' => ['cover' => 'wide', 'avatar' => 'thumbnail', 'image' => 'large'],
  ];

  /**
   * Per-(component, prop) RIFT VIEW MODE map — the responsive-image upgrade.
   *
   * Where {@see IMAGE_STYLES} flattens a media token to ONE fixed-size <img>,
   * a (component, prop) listed here renders that token through Rift as a
   * responsive <picture> (or container-query element) in the named view mode —
   * pulled out of the typed-string props into an SDC #slot ({@see component()}).
   * The view mode names a config bundle in rift.settings (aspect ratio, sizes,
   * breakpoints, formats). PRESENCE here is the switch: only components whose
   * twig has been converted to an image SLOT appear, so every other image prop
   * still flows through the URL path (IMAGE_STYLES) untouched. Migrating a
   * component = convert its twig + add its line here.
   */
  private const VIEW_MODES = [
    'hero' => ['image' => 'hero'],
    'content' => ['image' => 'content'],
    // Standalone figure — reuses the content view mode (4x3 responsive bundle).
    'image' => ['image' => 'content'],
    'article-header' => ['cover' => 'cover'],
  ];

  /**
   * (component, prop) SLOT pairs whose Rift picture uses CONTAINER-QUERY mode —
   * sized by the element's container, not the viewport. Empty: the only
   * container-query consumers are the repeatables (grid cards / gallery images),
   * whose per-cell image can't be a top-level slot — they go through
   * {@see ROW_PICTURES} instead. A converted single-image SLOT whose width ≈
   * viewport (hero/content/cover) stays on the default <picture> builder.
   */
  private const CONTAINER_QUERY = [];

  /**
   * Repeatables whose per-ROW image renders as a container-query Rift picture.
   *
   * SDC slots are flat top-level regions — they can't nest per array row, so a
   * grid card's / gallery cell's image can't be lifted to a slot like the
   * single-image components (VIEW_MODES). Instead {@see rowPictures()} resolves
   * each row's media token to a CQ picture and flattens it to an HTML string in
   * a sibling `<image>_html` field, which the twig emits with |raw (falling back
   * to the plain <img src> the URL path already produced). Keyed by the PLACED
   * component (grid renders cards in-twig; gallery renders images inline):
   * [component => ['rows' => <array prop>, 'image' => <token key>, 'view_mode']].
   */
  private const ROW_PICTURES = [
    'grid' => ['rows' => 'cards', 'image' => 'image', 'view_mode' => 'card'],
    'gallery' => ['rows' => 'images', 'image' => 'image', 'view_mode' => 'gallery'],
  ];

  private function component(string $name, array $props, ?string $langcode = NULL): array {
    // Renderer-internal `variant` (the chrome-light `bare` mode): the SDC
    // validates the enum BEFORE Twig's |default(), and PageStore strips the prop
    // (it is not a declared author prop), so an absent variant would reach the SDC
    // as "" and 500. Backfill the top-level default; renderPanels() has already
    // set 'bare' for a nested block. See ComponentCatalog::RENDERER_VARIANT_COMPONENTS.
    if (in_array($name, ComponentCatalog::RENDERER_VARIANT_COMPONENTS, TRUE) && ($props['variant'] ?? '') === '') {
      $props['variant'] = 'default';
    }
    // Image SLOTS: a (component, prop) mapped in VIEW_MODES renders through Rift
    // as a responsive <picture> render array in the named view mode, lifted OUT
    // of the validated string props into an SDC #slot (a render array can't live
    // in a typed string prop — same reason `embed` feeds its body via a slot).
    // Only converted components are mapped, so every other image prop still
    // flows through the URL path in resolveEmbeds() below (back-compat).
    $slots = [];
    foreach (self::VIEW_MODES[$name] ?? [] as $prop => $viewMode) {
      if (!array_key_exists($prop, $props)) {
        continue;
      }
      // The value is now a SLOT, never a prop — remove it so SDC's typed-prop
      // validation never sees the (no-longer-declared) prop, whatever happens
      // below (resolved picture, dangling token, or raw URL).
      $value = $props[$prop];
      unset($props[$prop]);
      if (!is_string($value) || $value === '') {
        continue;
      }
      if ($this->embed->isToken($value)) {
        $picture = $this->embed->picture($value, $viewMode, $langcode, $this->containerQuery($name, $prop));
        if ($picture !== NULL) {
          $slots[$prop] = $picture;
        }
        else {
          // No rift (or no buildable picture) → fall back to a plain <img> from
          // the token's image-style URL + alt, so the slot still shows the image.
          $url = $this->embed->url($value, $this->imageStyleFor($name, $prop));
          if (is_string($url) && $url !== '') {
            $slots[$prop] = $this->imgSlot($url, (string) $this->embed->alt($value));
          }
        }
      }
      else {
        // Back-compat: a raw image URL (spike briefs) → a plain <img> in the
        // slot, so non-token values still render after the prop→slot move.
        $slots[$prop] = $this->imgSlot($value, '');
      }
    }
    // Repeatable rows (grid cards, gallery images): pre-render each cell's image
    // to a container-query Rift picture (HTML), since a slot can't nest per row.
    $props = $this->rowPictures($name, $props, $langcode);

    $build = ['#type' => 'component', '#component' => "aincient_pages:$name", '#props' => $this->resolveEmbeds($props, $name)];
    if ($slots !== []) {
      $build['#slots'] = $slots;
    }
    return $build;
  }

  /**
   * Pre-render a repeatable's per-row image as a container-query Rift picture.
   *
   * For a component in {@see ROW_PICTURES}, walks its row array and adds an
   * `<image>_html` sibling to each row whose image is a resolvable media token —
   * a container-query Rift picture flattened to markup (the slot mechanism can't
   * nest per row). The raw token is LEFT in place so {@see resolveEmbeds()} still
   * flattens it to a URL for the twig's no-rift / raw-URL fallback branch.
   * Rendered in isolation: the per-image @container CSS rides inline in the
   * markup and the 2 base rules live in aincient-pages.css (the bespoke shell
   * doesn't auto-attach the rift_container_query library).
   */
  private function rowPictures(string $name, array $props, ?string $langcode): array {
    $map = self::ROW_PICTURES[$name] ?? NULL;
    if ($map === NULL || !is_array($props[$map['rows']] ?? NULL)) {
      return $props;
    }
    foreach ($props[$map['rows']] as $i => $row) {
      $token = is_array($row) ? ($row[$map['image']] ?? NULL) : NULL;
      if (!is_string($token) || !$this->embed->isToken($token)) {
        continue;
      }
      $picture = $this->embed->picture($token, $map['view_mode'], $langcode, [
        'rift_container_query' => ['enable_container_queries' => TRUE],
      ]);
      if ($picture !== NULL) {
        $props[$map['rows']][$i][$map['image'] . '_html'] = (string) $this->renderer->renderInIsolation($picture);
      }
    }
    return $props;
  }

  /** Rift third-party settings enabling container-query mode for a slot, or []. */
  private function containerQuery(string $component, string $prop): array {
    return !empty(self::CONTAINER_QUERY[$component][$prop])
      ? ['rift_container_query' => ['enable_container_queries' => TRUE]]
      : [];
  }

  /** A plain <img> render array for an image slot (rift-absent / raw-URL path). */
  private function imgSlot(string $src, string $alt): array {
    return [
      '#type' => 'inline_template',
      '#template' => '<img src="{{ src }}" alt="{{ alt }}" loading="lazy">',
      '#context' => ['src' => $src, 'alt' => $alt],
    ];
  }

  /**
   * Resolve embed tokens in a prop tree to URLs, adding `<key>_alt` siblings.
   *
   * The single render-time seam for the reference axis: any string prop holding
   * an embed token (media:ID / entity:…@viewmode — see EntityEmbedResolver) is
   * replaced with its resolved URL — through the per-prop image style from
   * {@see IMAGE_STYLES} for a media image — and for a media image we also surface
   * the entity's alt text as `<key>_alt`. Raw URLs and plain strings pass through
   * untouched (back-compat); a dangling reference collapses to '' so the twig's
   * `{% if image %}` simply hides it. Recurses into nested arrays so tokens
   * inside repeatables (gallery images, logos, avatars) resolve too — carrying
   * the section's `$component` down so a row field styles against its section.
   */
  private function resolveEmbeds(array $props, string $component): array {
    foreach ($props as $key => $value) {
      if (is_string($value) && $this->embed->isToken($value)) {
        $style = is_string($key) ? $this->imageStyleFor($component, $key) : NULL;
        $props[$key] = $this->embed->url($value, $style) ?? '';
        if (is_string($key)) {
          $alt = $this->embed->alt($value);
          if ($alt !== NULL) {
            $props[$key . '_alt'] = $alt;
          }
        }
      }
      elseif (is_array($value)) {
        $props[$key] = $this->resolveEmbeds($value, $component);
      }
    }
    return $props;
  }

  /**
   * The image style for a (component, prop) image slot, or NULL for original.
   */
  private function imageStyleFor(string $component, string $prop): ?string {
    return self::IMAGE_STYLES[$component][$prop]
      ?? self::IMAGE_STYLES['*'][$prop]
      ?? NULL;
  }

  /** The brand header, shown on every page (nav = core 'main' menu). */
  private function siteHeader(): array {
    return $this->component('site-header', $this->chrome->headerProps());
  }

  /** The brand footer, shown on every page (nav = core 'footer' menu). */
  private function siteFooter(): array {
    return $this->component('site-footer', $this->chrome->footerProps());
  }

  /** Drop null props so SDC's typed schema validation doesn't trip on them. */
  private function clean(array $props): array {
    return array_filter($props, static fn($v) => $v !== NULL);
  }

  private function shell(string $content, string $cssUrl, string $title, string $brandCss = '', string $metaHtml = '', bool $showConsent = TRUE): string {
    $title = htmlspecialchars($title, ENT_QUOTES);
    // cssVariables() only ever emits ":root{--token:#hex;…}" — safe to inline.
    $brandStyle = $brandCss !== '' ? "\n  <style>$brandCss</style>" : '';
    // Brand web fonts, per the operator's delivery choice (mirrors the themed
    // render path in aincient_theme.theme):
    //  - self-host → plain <link> to the woff2 vendored to our own origin;
    //  - Google    → CONSENT-GATED <link> (media="not all" so it does not fetch
    //                until consent.js flips it — no Google request, no IP leak,
    //                until the visitor opts in);
    //  - none      → nothing (system-font stack carries the page).
    $webFont = $this->brand->webFont();
    $fontLink = '';
    if ($webFont['mode'] === 'selfhost') {
      $href = htmlspecialchars($webFont['href'], ENT_QUOTES);
      $fontLink = "\n  <link rel=\"stylesheet\" href=\"$href\">";
    }
    elseif ($webFont['mode'] === 'google') {
      // No href — a link with no href can't fetch (a media-gated link is still
      // downloaded by Blink, leaking the IP). consent.js promotes data-href to
      // href once consent is given.
      $href = htmlspecialchars($webFont['href'], ENT_QUOTES);
      $fontLink = "\n  <link rel=\"stylesheet\" data-href=\"$href\" data-consent=\"fonts\">";
    }
    // The always-on, self-hosted emoji font (DECISIONS 0058). This shell emits
    // raw HTML (no render array), so the library can't be #attached — link its
    // CSS directly from the module's own origin, the same module-path pattern as
    // the main stylesheet. The font_sans/font_display stacks fall back to it.
    $modulePath = base_path() . $this->moduleList->getPath('aincient_pages');
    $emojiHref = htmlspecialchars("$modulePath/fonts/noto-emoji.css", ENT_QUOTES);
    $emojiLink = "\n  <link rel=\"stylesheet\" href=\"$emojiHref\">";
    // The out-of-box DEFAULT brand fonts (Schibsted Grotesk body + Fraunces
    // display), always self-hosted + always linked — offline-first, no Google
    // request, no consent gate. Mirrors the emoji-font posture; $fontLink above
    // remains for an operator's OWN chosen fonts (empty by default).
    $brandFontHref = htmlspecialchars("$modulePath/fonts/brand-fonts.css", ENT_QUOTES);
    $brandFontLink = "\n  <link rel=\"stylesheet\" href=\"$brandFontHref\">";
    // The operator-uploaded favicon (Globals studio). This bespoke shell bypasses
    // Drupal's <head> pipeline (so hook_page_attachments_alter never runs here),
    // so emit the <link rel="icon"> directly, mirroring the themed render path.
    $favicon = $this->identity->faviconLink();
    $faviconLink = '';
    if ($favicon !== NULL) {
      $href = htmlspecialchars($favicon['href'], ENT_QUOTES);
      $type = htmlspecialchars($favicon['type'], ENT_QUOTES);
      $faviconLink = "\n  <link rel=\"icon\" href=\"$href\" type=\"$type\">";
    }
    // The GDPR consent banner — same files as the themed library, linked
    // directly (raw HTML can't #attach a library). Emitted only when something
    // third-party is active (today: Google-delivered fonts); the config rides in
    // a JSON <script> that consent.js reads (no drupalSettings here).
    // The banner is a LIVE-SITE GDPR affordance; the studio preview iframes
    // (chrome preview + the stateless page preview) suppress it — the Globals
    // Privacy tab already shows the live consequence of the draft, and a banner
    // computed from the SAVED config would misrepresent an unsaved draft toggle.
    $consentBlock = '';
    if ($showConsent && $this->consent->isActive()) {
      $consentCss = htmlspecialchars("$modulePath/css/consent.css", ENT_QUOTES);
      $consentJs = htmlspecialchars("$modulePath/js/consent.js", ENT_QUOTES);
      // configJson() is server-built JSON (no user HTML); safe between <script>.
      $consentJson = $this->consent->configJson();
      $consentBlock = "\n  <link rel=\"stylesheet\" href=\"$consentCss\">"
        . "\n  <script type=\"application/json\" id=\"aincient-consent-config\">$consentJson</script>"
        . "\n  <script src=\"$consentJs\" defer></script>";
    }
    // metatag emits its own <title>; only fall back to the hand-written one when
    // there are no metatags (spike briefs, or metatag disabled).
    $metaBlock = $metaHtml !== '' ? "\n  $metaHtml" : '';
    $titleTag = str_contains($metaHtml, '<title') ? '' : "\n  <title>$title</title>";
    return <<<HTML
<!doctype html>
<html lang="en" dir="ltr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">$faviconLink$brandFontLink$fontLink$emojiLink
  <link rel="stylesheet" href="$cssUrl">$brandStyle$consentBlock$metaBlock$titleTag
</head>
<body class="min-h-screen bg-background text-foreground antialiased font-sans">
$content
</body>
</html>
HTML;
  }

}
