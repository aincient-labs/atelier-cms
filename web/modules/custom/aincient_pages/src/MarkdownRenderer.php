<?php

declare(strict_types=1);

namespace Drupal\aincient_pages;

use Drupal\Component\Utility\Xss;
use League\CommonMark\CommonMarkConverter;
use League\CommonMark\ConverterInterface;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\InlinesOnly\InlinesOnlyExtension;
use League\CommonMark\MarkdownConverter;

/**
 * Converts authored Markdown source into SANITISED HTML for the `markdown`
 * placeable section.
 *
 * The page-schema stores Markdown source as a plain string prop (it is NOT a
 * Drupal field, so there is no text-format/filter pipeline in this path); the
 * renderer calls this to flatten it to HTML before feeding the `markdown` SDC's
 * `html` prop. Two layers of safety, neither trusting the author:
 *
 *   1. CommonMark is configured with `html_input => escape` (raw HTML embedded
 *      in the Markdown is escaped, never rendered) and `allow_unsafe_links =>
 *      false` (javascript:/data: links are dropped).
 *   2. The output is still passed through {@see Xss::filterAdmin()} — the same
 *      defence-in-depth posture as the `prose` SDC, which trusts pre-sanitised
 *      HTML. So even a future parser-config slip can't inject script.
 *
 * Single converter instance, reused — CommonMark's environment is immutable and
 * safe to share across conversions.
 */
final class MarkdownRenderer {

  /**
   * The marks inline Markdown can produce that we allow through. Block tags,
   * images, and anything else are stripped — see {@see toInlineHtml}.
   */
  private const INLINE_TAGS = ['a', 'em', 'strong', 'code', 'br'];

  private ?ConverterInterface $converter = NULL;

  private ?ConverterInterface $inlineConverter = NULL;

  /**
   * Convert Markdown source to sanitised, render-ready HTML.
   *
   * @param string $markdown
   *   The authored Markdown source.
   *
   * @return string
   *   Sanitised HTML, or '' for empty/whitespace-only input.
   */
  public function toSafeHtml(string $markdown): string {
    if (trim($markdown) === '') {
      return '';
    }
    $html = (string) $this->converter()->convert($markdown);
    return Xss::filterAdmin($html);
  }

  /**
   * Convert INLINE Markdown to sanitised, render-ready HTML.
   *
   * For long-form text PROPS (subheading, body, bio, quote, answer, description,
   * caption) — copy that wants basic formatting (links, **bold**, *italic*,
   * `code`) without becoming a full prose block. Unlike {@see toSafeHtml}, only
   * inline syntax is parsed: block constructs (`#` headings, `-` lists, `>`
   * quotes) render as LITERAL text, so section layout/rhythm is never disturbed.
   *
   * @param string $markdown
   *   The authored inline-Markdown source.
   * @param bool $breaks
   *   Convert source newlines to <br> (matches the legacy nl2br on `body`).
   *
   * @return string
   *   Sanitised inline HTML, or '' for empty/whitespace-only input.
   */
  public function toInlineHtml(string $markdown, bool $breaks = FALSE): string {
    if (trim($markdown) === '') {
      return '';
    }
    // Trim the converter's trailing newline so $breaks doesn't append a stray
    // <br> at the end of the field.
    $html = rtrim((string) $this->inlineConverter()->convert($markdown), "\n");
    if ($breaks) {
      $html = nl2br($html, FALSE);
    }
    // Inline allow-list (defence in depth atop the inline-only parser): keep only
    // the marks above, dropping images and any stray tag. Xss::filter also vets
    // <a href> schemes (javascript:/data: are removed).
    return Xss::filter($html, self::INLINE_TAGS);
  }

  /**
   * The lazily-built, reusable CommonMark converter (safe defaults baked in).
   */
  private function converter(): ConverterInterface {
    return $this->converter ??= new CommonMarkConverter([
      // Escape raw HTML in the source rather than rendering it (belt; Xss is
      // the braces). Authors write Markdown, not HTML.
      'html_input' => 'escape',
      // Drop javascript:/data: and other unsafe link schemes.
      'allow_unsafe_links' => FALSE,
    ]);
  }

  /**
   * The lazily-built inline-only converter: parses inline syntax exclusively
   * (League's {@see InlinesOnlyExtension} — no block parsers), with the same
   * safe HTML/link posture as the block converter above.
   */
  private function inlineConverter(): ConverterInterface {
    if ($this->inlineConverter !== NULL) {
      return $this->inlineConverter;
    }
    $environment = new Environment([
      'html_input' => 'escape',
      'allow_unsafe_links' => FALSE,
    ]);
    $environment->addExtension(new InlinesOnlyExtension());
    return $this->inlineConverter = new MarkdownConverter($environment);
  }

}
