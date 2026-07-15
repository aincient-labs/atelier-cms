<?php

declare(strict_types=1);

namespace Drupal\aincient_export;

/**
 * Path string helpers shared by the exporter and the link checker.
 */
final class PathUtil {

  /**
   * Collapses "." and ".." segments of an absolute path.
   */
  public static function normalize(string $path): string {
    $segments = [];
    foreach (explode('/', $path) as $segment) {
      if ($segment === '' || $segment === '.') {
        continue;
      }
      if ($segment === '..') {
        array_pop($segments);
        continue;
      }
      $segments[] = $segment;
    }
    return '/' . implode('/', $segments);
  }

  /**
   * Maps a URL path to its file destination inside the export directory.
   *
   * Extensionless paths become directories with an index.html so static
   * hosts serve them at the original URL.
   */
  public static function destination(string $path): string {
    $path = preg_replace(['/\?.*/', '/#.*/'], '', $path);
    $path = trim(urldecode($path), '/');
    if ($path === '') {
      return 'index.html';
    }
    if (pathinfo($path, PATHINFO_EXTENSION) === '') {
      return $path . '/index.html';
    }
    return $path;
  }

}
