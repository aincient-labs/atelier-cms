<?php

declare(strict_types=1);

namespace Drupal\aincient_pages\Twig;

use Drupal\aincient_pages\SiteDestinations;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * The `site_destinations()` Twig function — the site's own nav, as link targets.
 *
 * The live counterpart to `component_catalog()`: where that inlines the static
 * composition grammar, this inlines a small piece of the site's CURRENT state so
 * the page agent can link a CTA to a real page without being handed a URL. Bounded
 * by the main menu, never by the page count — the reasoning lives in
 * {@see SiteDestinations}.
 *
 * A separate extension rather than a second function on
 * {@see ComponentCatalogExtension} because the two have different natures: the
 * catalog is pure static grammar, this reads site content through a service.
 *
 * `is_safe: html` for the same reason as the catalog: the text is inlined verbatim
 * into an LLM system prompt, never emitted as page HTML, so Twig's autoescape must
 * not mangle it (menu titles are operator-authored plain text).
 */
final class SiteDestinationsExtension extends AbstractExtension {

  public function __construct(
    private readonly SiteDestinations $destinations,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFunctions(): array {
    return [
      new TwigFunction('site_destinations', [$this, 'destinations'], ['is_safe' => ['html']]),
    ];
  }

  /**
   * The site-destinations prompt block ('' when the menu is empty).
   */
  public function destinations(): string {
    return $this->destinations->forPrompt();
  }

}
