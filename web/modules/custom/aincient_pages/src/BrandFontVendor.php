<?php

declare(strict_types=1);

namespace Drupal\aincient_pages;

use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Self-hosts a site's chosen brand web fonts so the PUBLIC pages never hot-link
 * Google — the operator's privacy-first delivery option (the alternative is the
 * consent-gated Google delivery; see {@see BrandRepository::fontDelivery}).
 *
 * Mirrors the self-hosted emoji font (DECISIONS 0058), but for the user-chosen,
 * RUNTIME-variable brand families: on brand save we fetch Google's already-
 * optimised, unicode-range-subset woff2 chunks for the configured families,
 * vendor them under public://aincient/fonts/<key>/, and rewrite the CSS to local
 * URLs. Visitors then load the brand font from OUR origin — no third-party
 * request, so no consent banner is needed for fonts.
 *
 * Content-addressed by the family set (the <key> is a hash of the sorted family
 * names), so re-saving the same fonts is a no-op and a font change vendors a
 * fresh set without clobbering the old one. Best-effort: any fetch failure
 * returns NULL and the caller falls back to the system font stack — we NEVER
 * silently fall back to hot-linking Google (that would defeat the point).
 */
final class BrandFontVendor {

  /**
   * The public:// subdirectory holding every vendored family set.
   */
  private const BASE = 'public://aincient/fonts';

  /**
   * A real desktop UA so css2 serves woff2 subsets (not the legacy TTF).
   */
  private const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 '
    . '(KHTML, like Gecko) Chrome/120.0 Safari/537.36';

  public function __construct(
    private readonly ClientInterface $http,
    private readonly FileSystemInterface $fileSystem,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * The public URL of the already-vendored stylesheet for $families, or NULL if
   * it hasn't been vendored yet. A pure read — never fetches (safe on the hot
   * render path; vendoring happens on save via {@see ensure}).
   */
  public function vendoredHref(array $families): ?string {
    $families = $this->normalise($families);
    if (!$families) {
      return NULL;
    }
    $uri = self::BASE . '/' . $this->key($families) . '/fonts.css';
    return is_file($this->fileSystem->realpath($uri) ?: '') ? $this->fileUrlGenerator->generateString($uri) : NULL;
  }

  /**
   * Ensure $families are vendored locally; returns the stylesheet URL or NULL.
   *
   * Idempotent: if the set is already vendored it returns immediately; otherwise
   * it fetches + writes it. Call on brand save (NOT on render). Outbound HTTP, so
   * never invoke it from a cacheable render path.
   */
  public function ensure(array $families): ?string {
    $families = $this->normalise($families);
    if (!$families) {
      return NULL;
    }
    $existing = $this->vendoredHref($families);
    if ($existing !== NULL) {
      return $existing;
    }
    return $this->build($families);
  }

  /**
   * Fetch Google's subset woff2 for $families, vendor them, write fonts.css.
   */
  private function build(array $families): ?string {
    $key = $this->key($families);
    $dir = self::BASE . '/' . $key;
    if (!$this->fileSystem->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
      $this->logger->warning('Could not prepare brand-font directory @dir', ['@dir' => $dir]);
      return NULL;
    }

    $css = $this->fetch($this->cssUrl($families));
    if ($css === NULL) {
      return NULL;
    }

    preg_match_all('/@font-face\s*\{([^}]*)\}/s', $css, $blocks);
    $out = "/* GENERATED self-hosted brand fonts — served from our origin, never\n"
      . "   Google, so the public site leaks no visitor IP. Vendored on brand save\n"
      . "   by Drupal\\aincient_pages\\BrandFontVendor. Families: "
      . implode(', ', $families) . ". */\n\n";
    $count = 0;
    foreach ($blocks[1] as $block) {
      if (!preg_match('/url\(([^)]+)\)/', $block, $u)) {
        continue;
      }
      $woff2 = $this->fetch(trim($u[1], "'\" "), "$dir/font.$count.woff2");
      if ($woff2 === NULL) {
        return NULL;
      }
      // Preserve the per-block family/style/weight/range verbatim — a multi-
      // family request returns one block per (family × axis × subset).
      $family = preg_match('/font-family:\s*([^;]+);/', $block, $f) ? trim($f[1]) : '';
      $style = preg_match('/font-style:\s*([^;]+);/', $block, $s) ? trim($s[1]) : 'normal';
      $weight = preg_match('/font-weight:\s*([^;]+);/', $block, $w) ? trim($w[1]) : '400';
      $range = preg_match('/unicode-range:\s*([^;]+);/', $block, $r) ? trim($r[1]) : '';
      $out .= "@font-face {\n"
        . ($family !== '' ? "  font-family: $family;\n" : '')
        . "  font-style: $style;\n"
        . "  font-weight: $weight;\n"
        . "  font-display: swap;\n"
        . "  src: url(font.$count.woff2) format('woff2');\n"
        . ($range !== '' ? "  unicode-range: $range;\n" : '')
        . "}\n";
      $count++;
    }

    if ($count === 0) {
      $this->logger->warning('No @font-face blocks parsed for brand fonts (@f)', ['@f' => implode(', ', $families)]);
      return NULL;
    }

    $uri = "$dir/fonts.css";
    if ($this->fileSystem->saveData($out, $uri, FileExists::Replace) === FALSE) {
      return NULL;
    }
    $this->logger->info('Vendored @n brand-font chunk(s) for @f', ['@n' => $count, '@f' => implode(', ', $families)]);
    return $this->fileUrlGenerator->generateString($uri);
  }

  /**
   * GET a URL with the desktop UA; write to $out if given. NULL on any failure.
   */
  private function fetch(string $url, ?string $out = NULL): ?string {
    try {
      $options = ['headers' => ['User-Agent' => self::UA], 'timeout' => 15];
      if ($out !== NULL) {
        $options['sink'] = $this->fileSystem->realpath($out) ?: $out;
        $this->http->request('GET', $url, $options);
        return '';
      }
      return (string) $this->http->request('GET', $url, $options)->getBody();
    }
    catch (\Throwable $e) {
      $this->logger->warning('Brand-font fetch failed (@url): @msg', ['@url' => $url, '@msg' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * The Google css2 URL for $families — IDENTICAL weights to
   * {@see BrandRepository::fontLinkHref} so self-host and Google delivery fetch
   * the same glyph set.
   */
  private function cssUrl(array $families): string {
    $parts = [];
    foreach ($families as $name) {
      $parts[] = 'family=' . str_replace(' ', '+', $name) . ':wght@400;500;600;700;800';
    }
    return 'https://fonts.googleapis.com/css2?' . implode('&', $parts) . '&display=swap';
  }

  /**
   * A stable directory key for a family set (order-independent).
   */
  private function key(array $families): string {
    $sorted = $families;
    sort($sorted);
    return substr(hash('sha256', implode('|', $sorted)), 0, 16);
  }

  /**
   * Trim + validate family names (the same gate the config writer applies).
   *
   * @return string[]
   */
  private function normalise(array $families): array {
    return array_values(array_filter(array_map('trim', $families), [BrandRepository::class, 'isFontName']));
  }

}
