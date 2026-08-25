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
    else {
      // Unknown kind: landing semantics, exactly as the old literal clamp —
      // except the built-in 'blog' regime, whose recipe mode is a code floor
      // (a site with no kind entities must still treat a post as a post).
      $entity = $storage->load('landing');
      $entity = $entity instanceof PageKindInterface ? $entity : NULL;
      if ($entity === NULL && isset(self::BUILTIN_KINDS[$kind])) {
        $kindId = $kind;
      }
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
      ],
      $kindId,
      $entity === NULL ? $fallback : [],
    );

    $tags = [
      'config:aincient_pages.site_constraint',
      'config:aincient_pages.page_kind.' . ($entity?->id() ?? $kindId),
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
  public function kinds(): array {
    if ($this->kinds !== NULL) {
      return $this->kinds;
    }
    $kinds = [];
    foreach ($this->entityTypeManager->getStorage('page_kind')->loadMultiple() as $id => $entity) {
      if ($entity instanceof PageKindInterface && $entity->status()) {
        $kinds[$id] = [
          'label' => (string) $entity->label(),
          'hint' => $entity->hint(),
          'mode' => $entity->mode(),
        ];
      }
    }
    if ($kinds === []) {
      // No kind entities at all — the code floor (see BUILTIN_KINDS).
      $kinds = array_map(fn(array $k) => [
        'label' => $k['label'],
        'hint' => $k['hint'],
        'mode' => $k['mode'],
      ], self::BUILTIN_KINDS);
    }
    ksort($kinds);
    return $this->kinds = $kinds;
  }

  /**
   * {@inheritdoc}
   */
  public function reset(): void {
    $this->compiled = [];
    $this->kinds = NULL;
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
