<?php

declare(strict_types=1);

namespace Drupal\aincient_pages\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\node\NodeInterface;
use Drupal\revision_graph\RevisionGraph\ColorSettings;
use Drupal\revision_graph\RevisionGraph\RevisionGraphBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * GET /atelier/page/{node}/revisions — the console's READ-ONLY revision rail.
 *
 * Decorates contrib `revision_graph`'s builder (the same derivation behind
 * `/node/{node}/revision_graph_items`) into the shape the renderer consumes,
 * so the console's React wrapper translates nothing
 * (plans/revision-graph-console.md "The endpoint"):
 *
 * - `refs: [branch]` on every commit — the one field contrib's `init.js`
 *   `formatCommits()` maps.
 * - **`urls` stripped at every depth.** This is the read-only gate and it is
 *   server-side: the renderer draws no action strip and no revision link when a
 *   commit carries no `urls`, so an operator cannot act from this payload —
 *   there is no route in it to act with. Phase 2 puts CONSOLE urls back here.
 * - `palette` + `branchColors` from `revision_graph.settings` — the channel the
 *   Drupal page gets through drupalSettings and the console otherwise lacks.
 *
 * `revision_graph` is a SOFT dependency: the builder is resolved from the
 * container, and a site without the module answers 404 rather than failing
 * container compile for every kernel test that enables aincient_pages.
 */
final class RevisionGraphController implements ContainerInjectionInterface {

  /**
   * Page size cap, in revision IDs — contrib's RevisionGraphController::PAGE_LIMIT.
   */
  public const PAGE_LIMIT = 50;

  public function __construct(
    private readonly ?RevisionGraphBuilder $builder,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->has('revision_graph.builder') ? $container->get('revision_graph.builder') : NULL,
      $container->get('config.factory'),
    );
  }

  /**
   * One page of the page's revision graph, renderer-shaped and action-free.
   *
   * Query: `offset` (revision IDs, ≥ 0) and `limit` (clamped to 1..50; absent,
   * zero or oversized → 50 — never an unbounded load of the whole history).
   * Page with `next_offset` (NULL when exhausted), never by counting commits:
   * one revision yields one commit per translation it affected.
   */
  public function revisions(NodeInterface $node, Request $request): JsonResponse {
    if ($node->bundle() !== 'aincient_page') {
      return new JsonResponse(['error' => 'Not an AIncient page.'], 404);
    }
    if ($this->builder === NULL) {
      return new JsonResponse(['error' => 'Revision history is not available on this site.'], 404);
    }
    // The route gates the console; the entity floor is the node's own revision
    // access, exactly what contrib's /node/{node}/revision_graph_items demands.
    if (!$node->access('view all revisions')) {
      return new JsonResponse(['error' => "You don’t have access to this page’s history."], 403);
    }

    $offset = max(0, (int) $request->query->get('offset', 0));
    $limit = self::clampLimit($request->query->get('limit'));

    $page = $this->builder->build($node, $offset, $limit)->toArray();
    $page['commits'] = array_map(static function (array $commit): array {
      $commit['refs'] = isset($commit['branch']) && $commit['branch'] !== '' ? [(string) $commit['branch']] : [];
      return $commit;
    }, $page['commits']);
    $page = self::stripUrls($page);

    $colors = $this->configFactory->get(ColorSettings::CONFIG_NAME);
    // As contrib's revisionOverview(): an emptied palette means "no
    // preference" → the shipped one, never the renderer's one-flat-colour
    // fallback. branchColors = the site's overrides over the shipped
    // per-language table.
    $page['palette'] = array_values($colors->get('palette') ?: ColorSettings::DEFAULT_PALETTE);
    $page['branchColors'] = ColorSettings::branchColors($colors->get('branch_colors') ?: []);
    $page['limit'] = $limit;

    return new JsonResponse($page);
  }

  /**
   * Contrib's clamp: a non-positive or absent limit → the cap; never above it.
   */
  public static function clampLimit(mixed $raw): int {
    $limit = $raw === NULL ? self::PAGE_LIMIT : (int) $raw;
    return $limit > 0 ? min($limit, self::PAGE_LIMIT) : self::PAGE_LIMIT;
  }

  /**
   * Removes every `urls` key, at any depth — the read-only gate.
   */
  public static function stripUrls(array $data): array {
    unset($data['urls']);
    foreach ($data as $key => $value) {
      if (is_array($value)) {
        $data[$key] = self::stripUrls($value);
      }
    }
    return $data;
  }

}
