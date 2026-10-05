<?php

declare(strict_types=1);

namespace Drupal\aincient_studio_components\Controller;

use Drupal\aincient_pages\Catalog\ComponentCatalogInterface;
use Drupal\aincient_pages\Catalog\KindCheck;
use Drupal\aincient_pages\ComponentCatalog;
use Drupal\aincient_pages\Entity\PageKindInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The Components studio's per-kind scope (DECISIONS 0455, P1b).
 *
 * One "Applies to" entry per kind from the registry — no kind is named here.
 * A kind narrows the site's palette further: an allow/deny choice for
 * components ("New components from packs: allowed automatically / off until I
 * allow them"), per-component variants + tones, a required opener and
 * per-component limits. Recipe kinds (blog) have a fixed layout and refuse
 * edits. Kinds are site-owned config, config_ignore-fenced, so a save survives
 * the import every appliance upgrade runs.
 *
 * `check` is the dry run: KindCheck over UNSAVED changes (a kind draft or a
 * site-constraint draft), so the studio lists affected pages BEFORE Publish.
 */
final class KindController implements ContainerInjectionInterface {

  public function __construct(
    private readonly ComponentCatalogInterface $catalog,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly KindCheck $kindCheck,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('aincient_pages.catalog'),
      $container->get('entity_type.manager'),
      $container->get('config.factory'),
      $container->get('aincient_pages.kind_check'),
    );
  }

  /**
   * The "Applies to" menu: every kind (pages + fragments) with its regime and
   * how many pages it has.
   *
   * @return list<array{id: string, label: string, mode: string, fragment: bool, count: int}>
   */
  public function scopes(): array {
    $counts = $this->kindCounts();
    $out = [];
    foreach ($this->catalog->kinds(TRUE) as $id => $kind) {
      $out[] = [
        'id' => (string) $id,
        'label' => (string) $kind['label'],
        'mode' => (string) $kind['mode'],
        'fragment' => !empty($kind['fragment']),
        'count' => $counts[$id] ?? 0,
      ];
    }
    return $out;
  }

  /**
   * GET /atelier/constraint/kind/{kind} — one kind's stored settings + its
   * compiled palette.
   */
  public function state(string $kind): JsonResponse {
    if (!isset($this->catalog->kinds(TRUE)[$kind])) {
      return new JsonResponse(['error' => sprintf('No page kind "%s".', $kind)], 404);
    }
    return new JsonResponse($this->kindState($kind));
  }

  /**
   * POST /atelier/constraint/kind/{kind}/save.
   *
   * Body: `{ include_new?: bool, components?: {name: {variants?, tones?}},
   * removed?: string[], opener?: string, limits?: {name: int} }` — each
   * provided key replaces the stored one. A recipe kind refuses (422).
   */
  public function save(string $kind, Request $request): JsonResponse {
    $data = json_decode((string) $request->getContent(), TRUE);
    if (!is_array($data)) {
      return new JsonResponse(['error' => 'Body must be a JSON object.'], 400);
    }
    if (!isset($this->catalog->kinds(TRUE)[$kind])) {
      return new JsonResponse(['error' => sprintf('No page kind "%s".', $kind)], 404);
    }
    $values = $this->cleanKindValues($data);
    if (is_string($values)) {
      return new JsonResponse(['error' => $values], 422);
    }
    $entity = $this->kindEntity($kind);
    if (!$entity->isComposition()) {
      return new JsonResponse(['error' => 'This kind has a fixed layout — there is nothing to place.'], 422);
    }
    foreach ($values as $key => $value) {
      $entity->set($key, $value);
    }
    $entity->save();
    $this->catalog->reset();
    return new JsonResponse(['applied' => array_keys($values)] + $this->kindState($kind));
  }

  /**
   * POST /atelier/constraint/check — the dry run over unsaved changes.
   *
   * Body: `{ scope: 'site' | <kind id>, draft: {...} }` — for `site` the draft
   * is a full site-constraint payload (every kind is checked under it); for a
   * kind it is that kind's values (only that kind is checked). Returns the
   * affected pages and blocks, one row per impact.
   */
  public function check(Request $request): JsonResponse {
    $data = json_decode((string) $request->getContent(), TRUE);
    $scope = is_string($data['scope'] ?? NULL) ? $data['scope'] : '';
    $draft = is_array($data['draft'] ?? NULL) ? $data['draft'] : [];
    if ($scope === 'site') {
      $constraint = [];
      foreach (['components', 'tones'] as $key) {
        $constraint[$key] = array_values(array_map('strval', is_array($draft[$key] ?? NULL) ? $draft[$key] : []));
      }
      foreach (['variants', 'component_tones'] as $key) {
        $constraint[$key] = is_array($draft[$key] ?? NULL) ? $draft[$key] : [];
      }
      $report = $this->kindCheck->run(fn(string $k) => $this->catalog->compileDraft($k, [], $constraint));
    }
    elseif (isset($this->catalog->kinds(TRUE)[$scope])) {
      $values = $this->cleanKindValues($draft);
      if (is_string($values)) {
        return new JsonResponse(['error' => $values], 422);
      }
      $report = $this->kindCheck->run(fn(string $k) => $k === $scope ? $this->catalog->compileDraft($k, $values) : $this->catalog->for($k), [$scope]);
    }
    else {
      return new JsonResponse(['error' => 'Pass scope: "site" or a page kind id.'], 400);
    }
    $rows = array_values(array_filter($report['rows'], static fn(array $r): bool => $r['nid'] !== '-'));
    return new JsonResponse([
      'scope' => $scope,
      'impacts' => count($rows),
      'rows' => $rows,
    ]);
  }

  /**
   * One kind's state for the rail — and for the agent's context node.
   */
  public function kindState(string $kind): array {
    $entity = $this->kindEntity($kind);
    $effective = $this->catalog->for($kind);
    $site = $this->configFactory->get('aincient_pages.site_constraint');
    return [
      'kind' => [
        'id' => $kind,
        'label' => (string) $entity->label(),
        'mode' => $entity->mode(),
        'fragment' => $entity->isFragment(),
        'include_new' => $entity->includesNew(),
        'components' => (object) $entity->components(),
        'removed' => $entity->removed(),
        'opener' => $entity->opener() ?? '',
        'limits' => (object) $entity->limits(),
      ],
      'effective' => [
        'placeable' => $effective->placeableNames(),
        'variants' => (object) $effective->variants(),
        'tones' => (object) array_combine($effective->placeableNames(), array_map(static fn(string $n) => $effective->tonesFor($n), $effective->placeableNames())),
        'opener' => $effective->opener(),
        'limits' => (object) $effective->limits(),
        'warnings' => $effective->warnings(),
      ],
      // What the site layer has already turned off — locked in a kind scope.
      'site_off' => [
        'components' => $site->get('components') ?? [],
        'tones' => $site->get('tones') ?? [],
      ],
      'scopes' => $this->scopes(),
    ];
  }

  /**
   * Validate + normalise posted kind values; a string is the 422 reason.
   *
   * @return array<string, mixed>|string
   */
  private function cleanKindValues(array $data): array|string {
    $values = [];
    $known = $this->catalog->discovered()->placeableNames();
    if (array_key_exists('include_new', $data)) {
      $values['include_new'] = (bool) $data['include_new'];
    }
    if (is_array($data['components'] ?? NULL)) {
      $components = [];
      $discovered = $this->catalog->discovered();
      foreach ($data['components'] as $name => $narrow) {
        if (!is_string($name) || $name === '') {
          continue;
        }
        $entry = [];
        foreach (['variants' => $discovered->variantsFor($name) ?? [], 'tones' => ComponentCatalog::TONES] as $key => $vocab) {
          $list = is_array($narrow[$key] ?? NULL) ? array_values(array_intersect($vocab, array_map('strval', $narrow[$key]))) : [];
          if (is_array($narrow[$key] ?? NULL) && $narrow[$key] !== [] && $list === []) {
            return sprintf('At least one %s of "%s" must remain available.', rtrim($key, 's'), $name);
          }
          if ($list !== []) {
            $entry[$key] = $list;
          }
        }
        $components[$name] = $entry;
      }
      $values['components'] = $components;
    }
    if (is_array($data['removed'] ?? NULL)) {
      $values['removed'] = array_values(array_unique(array_map('strval', $data['removed'])));
    }
    if (array_key_exists('opener', $data)) {
      $opener = is_string($data['opener']) ? trim($data['opener']) : '';
      if ($opener !== '' && !in_array($opener, $known, TRUE)) {
        return sprintf('Unknown opener "%s".', $opener);
      }
      $values['opener'] = $opener;
    }
    if (is_array($data['limits'] ?? NULL)) {
      $limits = [];
      foreach ($data['limits'] as $name => $max) {
        if (is_string($name) && in_array($name, $known, TRUE) && is_numeric($max) && (int) $max > 0) {
          $limits[$name] = (int) $max;
        }
      }
      $values['limits'] = $limits;
    }
    return $values;
  }

  /**
   * The stored kind entity, or a new one from the built-in floor (a site that
   * never stored its built-in kinds still gets them on first save).
   */
  private function kindEntity(string $kind): PageKindInterface {
    $storage = $this->entityTypeManager->getStorage('page_kind');
    $entity = $storage->load($kind);
    if ($entity instanceof PageKindInterface) {
      return $entity;
    }
    $listed = $this->catalog->kinds(TRUE)[$kind];
    /** @var \Drupal\aincient_pages\Entity\PageKindInterface $entity */
    $entity = $storage->create([
      'id' => $kind,
      'label' => $listed['label'],
      'mode' => $listed['mode'],
      'fragment' => !empty($listed['fragment']),
      'collection_source' => $kind === 'blog',
      'components' => [],
      'limits' => [],
      'opener' => '',
    ]);
    return $entity;
  }

  /**
   * Pages per kind (the derived field_page_type), blocks under `block`.
   *
   * @return array<string, int>
   */
  private function kindCounts(): array {
    $counts = [];
    try {
      $result = $this->entityTypeManager->getStorage('node')->getAggregateQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'aincient_page')
        ->groupBy('field_page_type')
        ->aggregate('nid', 'COUNT')
        ->execute();
      foreach ($result as $row) {
        $counts[(string) ($row['field_page_type'] ?? '')] = (int) $row['nid_count'];
      }
    }
    catch (\Throwable) {
      // No field_page_type (a bare test site) — counts stay empty.
    }
    if ($this->entityTypeManager->hasDefinition('media')) {
      try {
        $counts['block'] = (int) $this->entityTypeManager->getStorage('media')->getQuery()
          ->accessCheck(FALSE)->condition('bundle', 'block')->count()->execute();
      }
      catch (\Throwable) {
      }
    }
    return $counts;
  }

}
