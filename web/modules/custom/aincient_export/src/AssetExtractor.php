<?php

declare(strict_types=1);

namespace Drupal\aincient_export;

/**
 * Extracts asset references from HTML and CSS.
 *
 * Pure string-in/list-out helpers — callers decide what is local, external,
 * or already exported.
 */
final class AssetExtractor {

  /**
   * Meta names/properties whose content is an asset URL.
   */
  private const ASSET_META = [
    'image_src',
    'og:image',
    'og:image:url',
    'og:image:secure_url',
    'twitter:image',
  ];

  /**
   * Link rels that point at assets (rel=canonical etc. point at pages).
   */
  private const ASSET_LINK_RELS = [
    'stylesheet',
    'icon',
    'shortcut icon',
    'apple-touch-icon',
    'apple-touch-icon-precomposed',
    'manifest',
    'preload',
    'prefetch',
    'modulepreload',
    'mask-icon',
  ];

  /**
   * Returns raw asset URLs referenced by an HTML document.
   *
   * @return string[]
   */
  public static function fromHtml(string $html): array {
    if (trim($html) === '') {
      return [];
    }
    $urls = [];
    $document = new \DOMDocument();
    @$document->loadHTML($html);
    $xpath = new \DOMXPath($document);

    foreach ($xpath->query('//img | //source | //video | //audio | //embed | //track') as $element) {
      foreach (['src', 'poster'] as $attribute) {
        if ($element->hasAttribute($attribute)) {
          $urls[] = $element->getAttribute($attribute);
        }
      }
      if ($element->hasAttribute('srcset')) {
        $urls = array_merge($urls, self::fromSrcset($element->getAttribute('srcset')));
      }
    }
    foreach ($xpath->query('//script[@src]') as $element) {
      $urls[] = $element->getAttribute('src');
    }
    foreach ($xpath->query('//link[@href]') as $element) {
      if (in_array(strtolower($element->getAttribute('rel')), self::ASSET_LINK_RELS, TRUE)) {
        $urls[] = $element->getAttribute('href');
      }
    }
    foreach ($xpath->query('//meta[@content]') as $element) {
      $key = strtolower($element->getAttribute('name') ?: $element->getAttribute('property'));
      if (in_array($key, self::ASSET_META, TRUE)) {
        $urls[] = $element->getAttribute('content');
      }
    }
    // url(...) in <style> blocks and style="" attributes.
    $urls = array_merge($urls, self::fromCssUrls($html));

    return array_values(array_unique(array_filter(array_map('trim', $urls))));
  }

  /**
   * Returns raw asset URLs referenced by a CSS document.
   *
   * @return string[]
   */
  public static function fromCss(string $css): array {
    $urls = self::fromCssUrls($css);
    if (preg_match_all('/@import\s+(?!url)["\']([^"\']+)["\']/i', $css, $matches)) {
      $urls = array_merge($urls, $matches[1]);
    }
    return array_values(array_unique(array_filter(array_map('trim', $urls))));
  }

  /**
   * @return string[]
   */
  private static function fromSrcset(string $srcset): array {
    $urls = [];
    foreach (explode(',', $srcset) as $candidate) {
      $url = preg_split('/\s+/', trim($candidate))[0] ?? '';
      if ($url !== '') {
        $urls[] = $url;
      }
    }
    return $urls;
  }

  /**
   * @return string[]
   */
  private static function fromCssUrls(string $text): array {
    if (!preg_match_all('/url\(\s*(["\']?)([^"\')]+)\1\s*\)/i', $text, $matches)) {
      return [];
    }
    return $matches[2];
  }

}
