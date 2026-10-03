<?php

declare(strict_types=1);

namespace Drupal\aincient_export\Controller;

use Drupal\aincient_export\PathProcessor\SnapshotPathProcessor;
use Drupal\aincient_export\SnapshotStore;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Snapshots under the console path: the read-only list the Settings studio's
 * snapshots section renders from, and every snapshot's files for preview. The
 * ACTIONS (freeze / use / keep / delete) are the Settings studio's own routes
 * (`aincient_studio_site`, DECISIONS 0430): this module keeps what the site
 * needs with every studio switched off.
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
    $response = new CacheableJsonResponse($this->store->state());
    // The list changes on disk without any Drupal write: never cache it.
    $response->addCacheableDependency((new CacheableMetadata())->setCacheMaxAge(0));
    return $response;
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
