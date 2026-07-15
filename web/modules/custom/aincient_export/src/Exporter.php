<?php

declare(strict_types=1);

namespace Drupal\aincient_export;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * Orchestrates one static export: inventory → render → package.
 *
 * Static HTML is the first adapter over the site's structured inventory
 * (DECISIONS 0181); this class owns the pipeline order so later adapters can
 * reuse the inventory/derivative steps and swap the serialization.
 */
final class Exporter {

  /**
   * Marker file proving a directory was produced by this exporter.
   *
   * The output directory is wiped on every run; the marker is the guard
   * against pointing --out at a directory we did not create.
   */
  private const MARKER = '.aincient-export.json';

  public function __construct(
    private readonly PathInventory $pathInventory,
    private readonly DerivativeWarmer $derivativeWarmer,
    private readonly StaticRenderer $renderer,
    private readonly LinkChecker $linkChecker,
    private readonly Packager $packager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * Runs a full export.
   *
   * @throws \RuntimeException
   *   When the output directory is unsafe to clear or cannot be created.
   */
  public function export(ExportOptions $options, ?callable $progress = NULL): ExportResult {
    $notify = $progress ?? static function (string $message): void {};
    $result = new ExportResult($options->outDir);
    $out = rtrim($options->outDir, '/');

    $this->prepareOutputDirectory($out);

    $notify('Warming image-style derivatives …');
    $result->derivativesWarmed = $this->derivativeWarmer->warmAll();

    $notify('Rendering pages …');
    $exported_assets = [];
    $redirects = [];
    foreach ($this->pathInventory->collect() as $path) {
      $outcome = $this->renderer->renderPath($path, $options->baseUrl);
      if ($outcome->error !== NULL) {
        $result->failures[$path] = $outcome->error;
        continue;
      }
      if ($outcome->redirectTarget !== NULL) {
        $redirects[$path] = ['target' => $outcome->redirectTarget, 'status' => $outcome->status];
        continue;
      }
      if ($outcome->status !== 200 || $outcome->content === NULL) {
        $result->skipped[$path] = $outcome->status;
        continue;
      }
      $destination = PathUtil::destination($path);
      $this->write($out . '/' . $destination, $outcome->content);
      $result->pages[$path] = $destination;
      if ($outcome->isHtml()) {
        foreach (AssetExtractor::fromHtml($outcome->content) as $url) {
          if (($asset = $this->toLocalPath($url, $options->baseUrl)) !== NULL) {
            $this->exportAsset($asset, $options, $out, $exported_assets, $result);
          }
        }
      }
    }

    $notify('Writing 404, robots.txt, redirects, sitemap …');
    $this->exportNotFoundPage($options, $out, $exported_assets, $result);
    $this->copyRobotsTxt($out);
    $this->writeRedirects($out, $redirects);
    $this->writeSitemap($out, $options->baseUrl, array_keys($result->pages));

    if ($options->runLinkCheck) {
      $notify('Checking links …');
      $result->brokenLinks = $this->linkChecker->check($out, $options->baseUrl, $options->checkIgnore);
    }

    $this->write($out . '/' . self::MARKER, json_encode([
      'generator' => 'aincient_export',
      'pages' => count($result->pages),
      'assets' => $result->assetsCopied,
    ]) . "\n");

    if ($options->zipPath !== NULL) {
      $notify('Packaging zip …');
      $result->zipFiles = $this->packager->package($out, $options->zipPath, $options->includeConfig, $options->includeUsers);
      $result->zipPath = $options->zipPath;
    }

    return $result;
  }

  /**
   * Exports one asset: disk copy when possible, kernel replay otherwise.
   *
   * The kernel fallback covers route-generated assets that exist only after
   * a request — lazy CSS/JS aggregates, unwarmed derivatives.
   *
   * @param array<string, bool> $exported
   *   Seen-set of asset paths, shared across the run.
   */
  private function exportAsset(string $path, ExportOptions $options, string $out, array &$exported, ExportResult $result): void {
    // The query string matters for rendering (lazy CSS/JS aggregates are
    // generated from their query args) but not for identity or destination.
    $bare = urldecode(preg_replace(['/\?.*/', '/#.*/'], '', $path));
    if (isset($exported[$bare]) || $bare === '/' || $bare === '') {
      return;
    }
    $exported[$bare] = TRUE;

    $destination = $out . '/' . PathUtil::destination($bare);
    $source = DRUPAL_ROOT . $bare;
    $css_content = NULL;

    if (is_file($source)) {
      $this->ensureDirectory(dirname($destination));
      copy($source, $destination);
      $result->assetsCopied++;
      if (str_ends_with(strtolower($bare), '.css')) {
        $css_content = (string) file_get_contents($source);
      }
    }
    else {
      $outcome = $this->renderer->renderPath($path, $options->baseUrl);
      if ($outcome->status !== 200) {
        // Not a servable asset — the link check will report it if referenced.
        return;
      }
      if ($outcome->file !== NULL) {
        $this->ensureDirectory(dirname($destination));
        copy($outcome->file, $destination);
      }
      else {
        $this->write($destination, (string) $outcome->content);
      }
      $result->assetsCopied++;
      if ($outcome->isCss()) {
        $css_content = $outcome->content;
      }
      elseif ($outcome->file !== NULL && str_ends_with(strtolower($bare), '.css')) {
        $css_content = (string) file_get_contents($outcome->file);
      }
    }

    // CSS pulls in fonts/images/@imports of its own; resolve relative refs
    // against the stylesheet location and recurse (seen-set bounds it).
    if ($css_content !== NULL) {
      foreach (AssetExtractor::fromCss($css_content) as $url) {
        $nested = $this->toLocalPath($url, $options->baseUrl);
        if ($nested === NULL && !preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) && !str_starts_with($url, '//')) {
          $nested = PathUtil::normalize(dirname($bare) . '/' . preg_replace(['/\?.*/', '/#.*/'], '', $url));
        }
        if ($nested !== NULL) {
          $this->exportAsset($nested, $options, $out, $exported, $result);
        }
      }
    }
  }

  /**
   * Renders a guaranteed-missing path and saves the themed 404 page.
   */
  private function exportNotFoundPage(ExportOptions $options, string $out, array &$exported, ExportResult $result): void {
    $outcome = $this->renderer->renderPath('/aincient-export/404-probe', $options->baseUrl);
    if ($outcome->status === 404 && $outcome->content !== NULL) {
      $this->write($out . '/404.html', $outcome->content);
      foreach (AssetExtractor::fromHtml($outcome->content) as $url) {
        if (($asset = $this->toLocalPath($url, $options->baseUrl)) !== NULL) {
          $this->exportAsset($asset, $options, $out, $exported, $result);
        }
      }
    }
  }

  private function copyRobotsTxt(string $out): void {
    if (is_file(DRUPAL_ROOT . '/robots.txt')) {
      copy(DRUPAL_ROOT . '/robots.txt', $out . '/robots.txt');
    }
  }

  /**
   * Writes redirect entities + render-time redirects in _redirects format.
   *
   * The Netlify-style "from to status" plain-text format is also understood
   * by Cloudflare Pages — our two flagship deploy targets.
   */
  private function writeRedirects(string $out, array $renderRedirects): void {
    $lines = [];
    if ($this->moduleHandler->moduleExists('redirect')) {
      /** @var \Drupal\redirect\Entity\Redirect $redirect */
      foreach ($this->entityTypeManager->getStorage('redirect')->loadMultiple() as $redirect) {
        $lines[] = sprintf('/%s %s %d', ltrim($redirect->getSourcePathWithQuery(), '/'), $redirect->getRedirectUrl()->toString(), $redirect->getStatusCode());
      }
    }
    foreach ($renderRedirects as $path => $redirect) {
      $lines[] = sprintf('%s %s %d', $path, $redirect['target'], $redirect['status']);
    }
    if ($lines) {
      $this->write($out . '/_redirects', implode("\n", $lines) . "\n");
    }
  }

  private function writeSitemap(string $out, string $baseUrl, array $paths): void {
    $base = rtrim($baseUrl, '/');
    $entries = array_map(
      static fn (string $path): string => '  <url><loc>' . htmlspecialchars($base . $path, ENT_XML1) . '</loc></url>',
      $paths,
    );
    $this->write($out . '/sitemap.xml', "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n" . implode("\n", $entries) . "\n</urlset>\n");
  }

  /**
   * Normalizes an asset URL to a local absolute path, or NULL if external.
   *
   * The query string is kept — exportAsset() needs it to replay
   * route-generated assets (aggregates) and strips it for destinations.
   */
  private function toLocalPath(string $url, string $baseUrl): ?string {
    $url = trim($url);
    if ($url === '' || $url[0] === '#' || str_starts_with($url, 'data:') || str_starts_with($url, 'mailto:') || str_starts_with($url, 'tel:') || str_starts_with($url, 'javascript:')) {
      return NULL;
    }
    if (str_starts_with($url, '//')) {
      $url = 'https:' . $url;
    }
    if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
      $base = rtrim($baseUrl, '/');
      if (!str_starts_with($url, $base . '/')) {
        return NULL;
      }
      $url = substr($url, strlen($base));
    }
    if ($url === '' || $url[0] !== '/') {
      return NULL;
    }
    return $url;
  }

  private function prepareOutputDirectory(string $out): void {
    if (is_dir($out)) {
      $entries = array_diff(scandir($out) ?: [], ['.', '..']);
      if ($entries && !is_file($out . '/' . self::MARKER)) {
        throw new \RuntimeException(sprintf('Refusing to clear %s: it is not empty and was not created by aincient_export (missing %s).', $out, self::MARKER));
      }
      $this->deleteContents($out);
    }
    $this->ensureDirectory($out);
  }

  private function deleteContents(string $dir): void {
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
      \RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $file_info) {
      $file_info->isDir() ? rmdir($file_info->getPathname()) : unlink($file_info->getPathname());
    }
  }

  private function write(string $destination, string $content): void {
    $this->ensureDirectory(dirname($destination));
    file_put_contents($destination, $content);
  }

  private function ensureDirectory(string $dir): void {
    if (!is_dir($dir) && !mkdir($dir, 0775, TRUE) && !is_dir($dir)) {
      throw new \RuntimeException(sprintf('Cannot create directory %s', $dir));
    }
  }

}
