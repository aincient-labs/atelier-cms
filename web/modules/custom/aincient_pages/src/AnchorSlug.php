<?php

declare(strict_types=1);

namespace Drupal\aincient_pages;

use League\CommonMark\Normalizer\TextNormalizerInterface;

/**
 * The ONE slug rule for in-page anchors — a section's `anchor` prop and the
 * ids stamped on `markdown` headings share it, so `## Café & Prices!` and
 * `anchor: "Café & Prices!"` both become `cafe-prices` and an author can predict
 * a fragment from its text.
 *
 * Lowercase ASCII letters, digits and single hyphens, starting with a letter (a
 * bare number is a legal id but reads as a mistake), at most 64 chars. Latin
 * diacritics fold to ASCII; a leading `#` — an author pasting the link form —
 * is tolerated. Uniqueness is NOT this class's job: PageStore suffixes repeats
 * across a page's sections; CommonMark's UniqueSlugNormalizer wraps this for
 * headings within one Markdown document.
 */
final class AnchorSlug implements TextNormalizerInterface {

  public const MAX_LENGTH = 64;

  /**
   * Normalise a requested anchor to a fragment slug, or NULL to drop it.
   */
  public static function slug(mixed $value): ?string {
    if (!is_string($value)) {
      return NULL;
    }
    $slug = ltrim(trim($value), '#');
    if ($slug === '') {
      return NULL;
    }
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $slug);
    $slug = mb_strtolower(is_string($ascii) && $ascii !== '' ? $ascii : $slug);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $slug = ltrim(trim($slug, '-'), '0123456789-');
    if ($slug === '') {
      return NULL;
    }
    return rtrim(substr($slug, 0, self::MAX_LENGTH), '-');
  }

  /**
   * CommonMark's heading-slug hook ({@see MarkdownRenderer}). A heading whose
   * text slugs to nothing ("## 2024", "## ***") falls back to `section` so the
   * unique wrapper can still number it — an empty id is worse than a dull one.
   */
  public function normalize(string $text, array $context = []): string {
    return self::slug($text) ?? 'section';
  }

}
