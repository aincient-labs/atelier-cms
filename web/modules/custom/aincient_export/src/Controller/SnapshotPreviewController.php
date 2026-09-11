<?php

declare(strict_types=1);

namespace Drupal\aincient_export\Controller;

use Drupal\aincient_export\Drush\Commands\ExportCommands;
use Drupal\aincient_export\PathProcessor\SnapshotPathProcessor;
use Drupal\aincient_export\SnapshotStore;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Snapshots under the console path: the list + actions the Settings studio
 * uses, and every snapshot's files for preview.
 *
 * Logged-in owners see the live site on the public URLs (the web server's
 * cookie bypass), so this is how they look at what visitors see, or at an
 * older version before serving it. Files only — never PHP.
 */
final class SnapshotPreviewController extends ControllerBase {

  public function __construct(
    private readonly SnapshotStore $store,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('aincient_export.snapshot_store'));
  }

  /**
   * JSON: the snapshot list plus what is served. The console pane reads this.
   */
  public function list(): CacheableJsonResponse {
    $response = new CacheableJsonResponse($this->snapshotState());
    // The list changes on disk without any Drupal write: never cache it.
    $response->addCacheableDependency((new CacheableMetadata())->setCacheMaxAge(0));
    return $response;
  }

  /**
   * POST {label?, keep?, serve?}: freeze the published site into a new snapshot.
   *
   * A snapshot whose export reported failures or broken links is taken but
   * NOT served (unless the caller passes force: true); the response carries
   * the problems so the pane can show them and offer "serve anyway".
   */
  public function freeze(Request $request): JsonResponse {
    $body = self::body($request);
    $label = trim((string) ($body['label'] ?? ''));
    $serve = (bool) ($body['serve'] ?? TRUE);
    $force = (bool) ($body['force'] ?? FALSE);
    try {
      [$snapshot, $result] = $this->store->freeze(
        $request->getSchemeAndHttpHost(),
        [
          'label' => $label,
          'keep' => (bool) ($body['keep'] ?? FALSE),
          'frozen_by' => $this->currentUser()->getAccountName(),
          'atelier_version' => (string) (getenv('ATELIER_VERSION') ?: 'dev'),
        ],
        ExportCommands::DEFAULT_CHECK_IGNORE,
      );
    }
    catch (\RuntimeException $e) {
      return new JsonResponse(['ok' => FALSE, 'error' => $e->getMessage()] + $this->snapshotState(), 500);
    }
    $problems = [];
    foreach ($result->failures as $path => $error) {
      $problems[] = sprintf('%s failed: %s', $path, $error);
    }
    foreach ($result->brokenLinks as $broken) {
      $problems[] = sprintf('%s links to %s, which does not exist', $broken['file'], $broken['ref']);
    }
    foreach ($result->missingAssets as $path => $status) {
      $problems[] = sprintf('%s is referenced but could not be produced (HTTP %d)', $path, $status);
    }
    $served = FALSE;
    if ($serve && ($result->ok() || $force)) {
      $this->store->use($snapshot->id);
      $served = TRUE;
    }
    return new JsonResponse([
      'ok' => $result->ok(),
      'snapshot' => $snapshot->toArray(),
      'served' => $served,
      'problems' => $problems,
    ] + $this->snapshotState());
  }

  /**
   * POST {id}: serve a snapshot, or "live".
   */
  public function use(Request $request): JsonResponse {
    $id = (string) (self::body($request)['id'] ?? '');
    try {
      $this->store->use($id);
    }
    catch (\InvalidArgumentException $e) {
      return new JsonResponse(['ok' => FALSE, 'error' => $e->getMessage()] + $this->snapshotState(), 400);
    }
    return new JsonResponse(['ok' => TRUE] + $this->snapshotState());
  }

  /**
   * POST {id, keep}: flip a snapshot's keep flag.
   */
  public function keep(Request $request): JsonResponse {
    $body = self::body($request);
    try {
      $this->store->setKeep((string) ($body['id'] ?? ''), (bool) ($body['keep'] ?? TRUE));
    }
    catch (\InvalidArgumentException $e) {
      return new JsonResponse(['ok' => FALSE, 'error' => $e->getMessage()] + $this->snapshotState(), 400);
    }
    return new JsonResponse(['ok' => TRUE] + $this->snapshotState());
  }

  /**
   * POST {id}: delete a snapshot that is not being served.
   */
  public function delete(Request $request): JsonResponse {
    $id = (string) (self::body($request)['id'] ?? '');
    try {
      $this->store->delete($id);
    }
    catch (\InvalidArgumentException | \LogicException $e) {
      return new JsonResponse(['ok' => FALSE, 'error' => $e->getMessage()] + $this->snapshotState(), 400);
    }
    return new JsonResponse(['ok' => TRUE] + $this->snapshotState());
  }

  /**
   * @return array{serving: string, snapshots: array<int, array<string, mixed>>}
   */
  private function snapshotState(): array {
    return [
      'serving' => $this->store->current(),
      'snapshots' => array_map(static fn ($s) => $s->toArray(), $this->store->list()),
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private static function body(Request $request): array {
    $decoded = json_decode((string) $request->getContent(), TRUE);
    return is_array($decoded) ? $decoded : [];
  }

  /**
   * One file out of a snapshot; a directory path resolves to its index.html.
   *
   * The file path arrives in `?file=` (SnapshotPathProcessor folds the URL's
   * trailing segments into it); no query = the snapshot's home page.
   */
  public function file(string $id, Request $request): Response {
    $snapshot = $this->store->get($id);
    if ($snapshot === NULL) {
      throw new NotFoundHttpException();
    }
    $relative = trim((string) $request->query->get(SnapshotPathProcessor::QUERY_KEY, ''), '/');
    $real_root = realpath($snapshot->dir);
    $candidate = $real_root . ($relative === '' ? '' : '/' . $relative);
    if (is_dir($candidate)) {
      $candidate .= '/index.html';
    }
    $real = realpath($candidate);
    // Inside the snapshot, and never the marker itself.
    if ($real === FALSE || !is_file($real) || !str_starts_with($real, $real_root . '/') || basename($real) === '.aincient-export.json') {
      throw new NotFoundHttpException();
    }
    $response = new BinaryFileResponse($real, 200, [
      'X-Atelier-Snapshot' => $snapshot->id,
      'Cache-Control' => 'private, no-store',
    ], FALSE);
    $response->headers->set('Content-Type', self::mime($real));
    return $response;
  }

  private static function mime(string $file): string {
    return match (strtolower(pathinfo($file, PATHINFO_EXTENSION))) {
      'html', 'htm' => 'text/html; charset=UTF-8',
      'css' => 'text/css; charset=UTF-8',
      'js', 'mjs' => 'text/javascript; charset=UTF-8',
      'json' => 'application/json',
      'xml' => 'application/xml',
      'txt' => 'text/plain; charset=UTF-8',
      'svg' => 'image/svg+xml',
      'png' => 'image/png',
      'jpg', 'jpeg' => 'image/jpeg',
      'gif' => 'image/gif',
      'webp' => 'image/webp',
      'avif' => 'image/avif',
      'ico' => 'image/x-icon',
      'woff' => 'font/woff',
      'woff2' => 'font/woff2',
      'ttf' => 'font/ttf',
      'mp4' => 'video/mp4',
      'webm' => 'video/webm',
      'pdf' => 'application/pdf',
      default => 'application/octet-stream',
    };
  }

}
