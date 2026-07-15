<?php

declare(strict_types=1);

namespace Drupal\aincient_export;

/**
 * Verifies that every local reference in an export resolves to a file.
 *
 * This replaces crawl-based discovery as the "forgot a route" guard: the
 * inventory is hand-maintained, so a page linking to something the inventory
 * missed must fail the export loudly instead of shipping a broken site.
 */
final class LinkChecker {

  /**
   * Checks all HTML and CSS files under $dir.
   *
   * @param string $dir
   *   The export directory.
   * @param string $baseUrl
   *   Absolute references with this prefix are treated as local.
   * @param string[] $ignorePatterns
   *   fnmatch() patterns for referenced paths that are intentionally not
   *   exported (e.g. /user/login in a menu).
   *
   * @return array<int, array{file: string, ref: string}>
   *   Broken references, empty when the export is self-contained.
   */
  public function check(string $dir, string $baseUrl, array $ignorePatterns = []): array {
    $broken = [];
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file_info) {
      $extension = strtolower($file_info->getExtension());
      if (!in_array($extension, ['html', 'css'], TRUE)) {
        continue;
      }
      $content = (string) file_get_contents($file_info->getPathname());
      $refs = $extension === 'html'
        ? array_merge(AssetExtractor::fromHtml($content), self::pageLinks($content))
        : AssetExtractor::fromCss($content);
      $relative_dir = dirname(substr($file_info->getPathname(), strlen(rtrim($dir, '/'))));

      foreach ($refs as $ref) {
        $path = self::toLocalPath($ref, $baseUrl, $relative_dir);
        if ($path === NULL) {
          continue;
        }
        foreach ($ignorePatterns as $pattern) {
          if (fnmatch($pattern, $path)) {
            continue 2;
          }
        }
        if (!self::resolves($dir, $path)) {
          $broken[] = ['file' => substr($file_info->getPathname(), strlen(rtrim($dir, '/')) + 1), 'ref' => $ref];
        }
      }
    }
    return $broken;
  }

  /**
   * Returns TRUE when $path maps to a file in the export.
   */
  private static function resolves(string $dir, string $path): bool {
    $target = rtrim($dir, '/') . $path;
    return is_file($target)
      || is_file(rtrim($target, '/') . '/index.html');
  }

  /**
   * Extracts a[href] page links (assets are covered by AssetExtractor).
   *
   * @return string[]
   */
  private static function pageLinks(string $html): array {
    $links = [];
    $document = new \DOMDocument();
    @$document->loadHTML($html);
    foreach ((new \DOMXPath($document))->query('//a[@href]') as $anchor) {
      $links[] = trim($anchor->getAttribute('href'));
    }
    return array_filter($links);
  }

  /**
   * Normalizes a reference to a local absolute path, or NULL if external.
   */
  private static function toLocalPath(string $ref, string $baseUrl, string $relativeDir): ?string {
    if ($ref === '' || $ref[0] === '#' || str_starts_with($ref, 'data:') || str_starts_with($ref, 'mailto:') || str_starts_with($ref, 'tel:') || str_starts_with($ref, 'javascript:')) {
      return NULL;
    }
    if (str_starts_with($ref, '//')) {
      $ref = 'https:' . $ref;
    }
    if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $ref)) {
      $base = rtrim($baseUrl, '/');
      if ($ref === $base) {
        return '/';
      }
      if (!str_starts_with($ref, $base . '/')) {
        return NULL;
      }
      $ref = substr($ref, strlen($base));
    }
    $ref = preg_replace(['/\?.*/', '/#.*/'], '', $ref);
    if ($ref === '' || $ref === '/') {
      return '/';
    }
    // Relative references (common in CSS) resolve against the file location.
    if ($ref[0] !== '/') {
      $ref = PathUtil::normalize(rtrim($relativeDir, '/') . '/' . $ref);
    }
    return urldecode($ref);
  }

}
