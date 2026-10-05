<?php

declare(strict_types=1);

namespace Drupal\aincient_pages\Catalog;

use Drupal\aincient_pages\Entity\PageKindInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Theme\ComponentPluginManager;

/**
 * The catalog service: SDC discovery → {@see CatalogCompiler} → cached
 * {@see EffectiveCatalog} per kind.
 *
 * Discovery is the SDC plugin manager filtered on `thirdPartySettings.atelier`
 * — the same definitions a client pack's module contributes, so the 28
 * built-ins and a pack component travel one path (ratified decision #1).
 * Compiled catalogs cache in cache.discovery, tagged on the kind entity and
 * the site constraint so a console tune invalidates exactly its kind; a
 * component change requires a cache rebuild, exactly like any SDC change.
 */
final class AtelierComponentCatalog implements ComponentCatalogInterface {

  /**
   * The two shipped regimes as a code-level floor: a site (or a kernel test)
   * with no page_kind entities at all still validates and renders exactly the
   * pre-kind behaviour — never fatal, never silently composition-only.
   */
  private const BUILTIN_KINDS = [
    'landing' => ['label' => 'Landing page', 'hint' => '', 'mode' => 'composition', 'collection_source' => FALSE],
    'blog' => ['label' => 'Blog post', 'hint' => '', 'mode' => 'recipe', 'collection_source' => TRUE],
    // The reusable-block regime (DECISIONS 0455): a composition that is not a
    // page. Everything allowed by default (D7); never a page type.
    'block' => ['label' => 'Block', 'hint' => '', 'mode' => 'composition', 'collection_source' => FALSE, 'fragment' => TRUE],
  ];

  /**
   * Per-request memo of compiled catalogs, by kind id.
   *
   * @var array<string, EffectiveCatalog>
   */
  private array $compiled = [];

  /**
   * Per-request memo of the kind listing.
   */
  private ?array $kinds = NULL;

  public function __construct(
    private readonly ComponentPluginManager $componentManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly CacheBackendInterface $cache,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function for(string $kind): EffectiveCatalog {
    if (isset($this->compiled[$kind])) {
      return $this->compiled[$kind];
    }
    $cid = 'aincient_pages:catalog:' . $kind;
    $hit = $this->cache->get($cid);
    if ($hit !== FALSE && $hit->data instanceof EffectiveCatalog) {
      return $this->compiled[$kind] = $hit->data;
    }

    $storage = $this->entityTypeManager->getStorage('page_kind');
    $entity = $storage->load($kind);
    $kindId = 'landing';
    if ($entity instanceof PageKindInterface) {
      $kindId = $entity->id();
    }
    elseif (isset(self::BUILTIN_KINDS[$kind])) {
      // A built-in kind without its entity keeps its OWN floor — never
      // landing's rules. A site that stored only some of its built-ins (the
      // Components studio saves one kind at a time) must still treat a post as
      // a post and a block as a block (DECISIONS 0455).
      $entity = NULL;
      $kindId = $kind;
    }
    else {
      // Unknown kind: landing semantics, exactly as the old literal clamp.
      $entity = $storage->load('landing');
      $entity = $entity instanceof PageKindInterface ? $entity : NULL;
    }
    $fallback = self::BUILTIN_KINDS[$kindId] ?? [];
    $constraint = $this->configFactory->get('aincient_pages.site_constraint');
    $catalog = CatalogCompiler::compile(
      $this->componentManager->getDefinitions(),
      $entity,
      [
        'components' => $constraint->get('components') ?? [],
        'tones' => $constraint->get('tones') ?? [],
        'variants' => $constraint->get('variants') ?? [],
        'component_tones' => $constraint->get('component_tones') ?? [],
      ],
      $kindId,
      $entity === NULL ? $fallback : [],
    );

    $tags = [
      'config:aincient_pages.site_constraint',
      'config:aincient_pages.page_kind.' . ($entity?->id() ?? $kindId),
      // The list tag: CREATING a kind entity (first save of a built-in that
      // only existed as the code floor) invalidates only the list tag, never
      // the per-entity one (DECISIONS 0455).
      'config:page_kind_list',
      // The discovered set changes when the module set changes (a pack is
      // enabled/uninstalled) — core.extension's tag is the cross-process
      // invalidation that reaches an entry a converge-side drush flush might
      // otherwise leave behind (observed live on pack enablement, Phase 4).
      'config:core.extension',
    ];
    $this->cache->set($cid, $catalog, CacheBackendInterface::CACHE_PERMANENT, $tags);
    return $this->compiled[$kind] = $catalog;
  }

  /**
   * {@inheritdoc}
   */
  public function kinds(bool $includeFragments = FALSE): array {
    if ($this->kinds === NULL) {
      $kinds = [];
      $stored = $this->entityTypeManager->getStorage('page_kind')->loadMultiple();
      foreach ($stored as $id => $entity) {
        if ($entity instanceof PageKindInterface && $entity->status()) {
          $kinds[$id] = [
            'label' => (string) $entity->label(),
            'hint' => $entity->hint(),
            'mode' => $entity->mode(),
            'fragment' => $entity->isFragment(),
          ];
        }
      }
      // The code floor (see BUILTIN_KINDS): every built-in kind whose entity is
      // missing — a site that never stored them, or stored only the one the
      // Components studio saved first. Saving landing must not make blog
      // vanish (seen live, DECISIONS 0455). A disabled entity still wins.
      foreach (self::BUILTIN_KINDS as $id => $k) {
        if (!isset($kinds[$id]) && !isset($stored[$id])) {
          $kinds[$id] = [
            'label' => $k['label'],
            'hint' => $k['hint'],
            'mode' => $k['mode'],
            'fragment' => !empty($k['fragment']),
          ];
        }
      }
      ksort($kinds);
      $this->kinds = $kinds;
    }
    // Page kinds by default: a fragment kind is never a page type (New page,
    // the PAGE KINDS prompt, a page's type clamp).
    return $includeFragments ? $this->kinds : array_filter($this->kinds, static fn(array $k): bool => empty($k['fragment']));
  }

  /**
   * {@inheritdoc}
   */
  public function reset(): void {
    $this->compiled = [];
    $this->kinds = NULL;
  }

  /**
   * Compile a catalog for UNSAVED changes — the Components studio's dry run
   * (DECISIONS 0455): the kind's stored values overlaid with $kindValues, under
   * $constraint (NULL = the stored site constraint). Never cached, never saved.
   *
   * @param string $kind
   *   The kind id.
   * @param array $kindValues
   *   Kind entity values to overlay (components, removed, opener, limits…).
   * @param array|null $constraint
   *   A full site-constraint payload, or NULL for the stored one.
   */
  public function compileDraft(string $kind, array $kindValues = [], ?array $constraint = NULL): EffectiveCatalog {
    $storage = $this->entityTypeManager->getStorage('page_kind');
    $entity = $storage->load($kind);
    if (!$entity instanceof PageKindInterface && isset(self::BUILTIN_KINDS[$kind])) {
      $floor = self::BUILTIN_KINDS[$kind];
      $entity = $storage->create([
        'id' => $kind,
        'label' => $floor['label'],
        'mode' => $floor['mode'],
        'collection_source' => $floor['collection_source'],
        'fragment' => !empty($floor['fragment']),
      ]);
    }
    if ($entity instanceof PageKindInterface) {
      $entity = clone $entity;
      foreach ($kindValues as $key => $value) {
        $entity->set($key, $value);
      }
    }
    if ($constraint === NULL) {
      $stored = $this->configFactory->get('aincient_pages.site_constraint');
      $constraint = [
        'components' => $stored->get('components') ?? [],
        'tones' => $stored->get('tones') ?? [],
        'variants' => $stored->get('variants') ?? [],
        'component_tones' => $stored->get('component_tones') ?? [],
      ];
    }
    return CatalogCompiler::compile(
      $this->componentManager->getDefinitions(),
      $entity instanceof PageKindInterface ? $entity : NULL,
      $constraint,
      $kind,
      self::BUILTIN_KINDS[$kind] ?? [],
    );
  }

  /**
   * {@inheritdoc}
   */
  public function discovered(): EffectiveCatalog {
    return $this->compiled['\xFFdiscovered'] ??= CatalogCompiler::compile($this->componentManager->getDefinitions(), NULL);
  }

  /**
   * {@inheritdoc}
   */
  public function collectionSources(): array {
    $sources = [];
    foreach (array_keys($this->kinds()) as $id) {
      if ($this->for($id)->isCollectionSource()) {
        $sources[] = $id;
      }
    }
    return $sources === [] ? ['blog'] : $sources;
  }

}
