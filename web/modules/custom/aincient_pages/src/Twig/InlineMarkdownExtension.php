<?php

declare(strict_types=1);

namespace Drupal\aincient_pages\Twig;

use Drupal\aincient_pages\MarkdownRenderer;
use Drupal\Core\Render\Markup;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * The `inline_md` Twig filter — renders a long-form text PROP's inline Markdown
 * (links, **bold**, *italic*, `code`) to safe HTML.
 *
 * Applied in the section SDC templates to the long-form props (subheading, body,
 * bio, quote, answer, description, caption) so authors can add links and basic
 * emphasis. Block Markdown is intentionally NOT parsed — see
 * {@see MarkdownRenderer::toInlineHtml}; the full-block `markdown` section stays
 * the home for prose. The filter returns a {@see Markup} object so Twig does not
 * re-escape the already-sanitised HTML.
 *
 * `is_safe: html` only marks the RETURN safe; the input is sanitised by the
 * renderer regardless, so a non-string/untrusted value can't bypass it.
 */
final class InlineMarkdownExtension extends AbstractExtension {

  public function __construct(
    private readonly MarkdownRenderer $markdown,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFilters(): array {
    return [
      new TwigFilter('inline_md', [$this, 'render'], ['is_safe' => ['html']]),
    ];
  }

  /**
   * Render a value's inline Markdown to sanitised HTML.
   *
   * @param mixed $value
   *   The prop value (coerced to string; non-strings render as '').
   * @param bool $breaks
   *   Convert source newlines to <br> (used on multi-paragraph `body`).
   */
  public function render(mixed $value, bool $breaks = FALSE): Markup {
    $source = is_string($value) ? $value : '';
    return Markup::create($this->markdown->toInlineHtml($source, $breaks));
  }

}
