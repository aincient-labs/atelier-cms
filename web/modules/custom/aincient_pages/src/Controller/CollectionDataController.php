<?php

declare(strict_types=1);

namespace Drupal\aincient_pages\Controller;

use Drupal\aincient_pages\CollectionInventory;
use Drupal\aincient_pages\CollectionResolver;
use Drupal\Component\Utility\Html;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableResponse;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves a collection's content-addressed JSON index and its flat archive page
 * (DECISIONS 0329; plans/collection-listings.md).
 *
 * ONE code path, live and exported: on a live appliance these routes render at
 * request time; the static exporter replays the SAME routes through the kernel
 * and writes the bodies to disk ({@see \Drupal\aincient_export\Exporter}). The
 * `{hash}` is the query's content address ({@see CollectionInventory::specHash});
 * an unknown hash is a 404, never an empty listing, so the export inventory and
 * the route agree on exactly which files exist.
 *
 * Both responses are CACHEABLE (invariant 3): the JSON MUST be a
 * {@see CacheableJsonResponse} or Drupal's internal page cache silently refuses
 * to store it — a permanent miss with no error — and on the CDN-less appliance
 * that is the only shield under AI-crawler load.
 */
final class CollectionDataController implements ContainerInjectionInterface {

  /**
   * Hard ceiling on records in one JSON/archive file. One file is fine to
   * ~1–2k items (plans/collection-listings.md); the cap is the backstop that
   * keeps a runaway site from paging its whole corpus into a single response.
   */
  private const CAP = 2000;

  public function __construct(
    private readonly CollectionInventory $inventory,
    private readonly CollectionResolver $resolver,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('aincient_pages.collection_inventory'),
      $container->get('aincient_pages.collection_resolver'),
    );
  }

  /**
   * GET /_data/collections/{hash}.json — the flat, pre-resolved index the island
   * re-renders from. The `.json` suffix is load-bearing for the export
   * ({@see \Drupal\aincient_export\PathUtil::destination} preserves it) and rides
   * inside the {file} placeholder value (see the route); strip it to the hash.
   */
  public function data(string $file): CacheableJsonResponse {
    $hash = substr($file, 0, -strlen('.json'));
    $resolved = $this->resolve($hash);
    $response = new CacheableJsonResponse([
      'hash' => $hash,
      'total' => $resolved['total'],
      'items' => array_map(
        static fn (array $r) => CollectionResolver::jsonRecord($r),
        $resolved['records'],
      ),
    ]);
    $response->addCacheableDependency($resolved['cacheability']);
    return $response;
  }

  /**
   * GET /collections/{hash}/archive — every post as a title + link, no
   * pagination. The link graph's completeness guarantee for crawlers that
   * follow links rather than the sitemap, and the no-JS "see everything" page.
   *
   * Deliberately self-contained HTML (one tiny inline style, no external asset):
   * it stays crawlable and export-clean with nothing for the post-export link
   * check to resolve but the post links themselves.
   */
  public function archive(string $hash): Response {
    $resolved = $this->resolve($hash);
    $items = '';
    foreach ($resolved['records'] as $record) {
      $items .= sprintf(
        '<li><a href="%s">%s</a> <time>%s</time></li>',
        Html::escape($record['url']),
        Html::escape($record['title']),
        Html::escape($record['date']),
      );
    }
    $body = $items === '' ? '<p>No posts yet.</p>' : "<ul class=\"archive\">$items</ul>";
    $html = <<<HTML
    <!DOCTYPE html>
    <html lang="en">
    <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Archive</title>
    <style>body{font-family:system-ui,sans-serif;max-width:48rem;margin:3rem auto;padding:0 1rem}.archive{list-style:none;padding:0}.archive li{margin:.5rem 0;display:flex;justify-content:space-between;gap:1rem}time{color:#666;white-space:nowrap}</style>
    </head>
    <body>
    <main>
    <h1>Archive</h1>
    $body
    </main>
    </body>
    </html>
    HTML;
    $response = new CacheableResponse($html);
    $response->addCacheableDependency($resolved['cacheability']);
    return $response;
  }

  /**
   * Resolve a hash to its records, or 404 on an unknown hash.
   *
   * @return array{records: array, total: int, cacheability: \Drupal\Core\Cache\CacheableMetadata}
   */
  private function resolve(string $hash): array {
    $collections = $this->inventory->indexCollections();
    if (!isset($collections[$hash])) {
      throw new NotFoundHttpException();
    }
    return $this->resolver->resolve($collections[$hash], NULL, self::CAP);
  }

}
