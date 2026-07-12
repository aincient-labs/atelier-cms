<?php

declare(strict_types=1);

namespace Drupal\aincient_pages;

/**
 * The GDPR consent model for a site's PUBLIC pages.
 *
 * AIncient is an EU-first product, so third-party requests (web fonts now;
 * analytics/embeds later) are consent-GATED by default rather than fired on page
 * load. This service computes the consent categories + whether the banner is
 * even needed, and hands the front-end ({@see js/consent.js}) a JSON descriptor.
 *
 * Honest by construction: a category is "active" only when something on the page
 * actually uses it right now, and the banner auto-shows ONLY when at least one
 * non-essential category is active. So a site whose fonts are self-hosted (and
 * with no analytics/embeds) shows NO banner — we never put up cookie-banner
 * theatre when nothing third-party loads.
 *
 * The banner lives on the END-USER's site and is styled from THEIR brand tokens
 * (never AIncient's own brand) — see css/consent.css.
 */
final class ConsentSettings {

  /**
   * The first-party cookie the visitor's choice is stored in (client-side).
   */
  public const COOKIE = 'aincient_consent';

  public function __construct(
    private readonly BrandRepository $brand,
  ) {}

  /**
   * The consent categories, in display order.
   *
   * `required` categories are always on (no toggle). `active` means the page is
   * currently loading something in that category — inactive non-required ones
   * are shown disabled ("not in use on this site yet") for transparency.
   *
   * @return list<array{id: string, label: string, required: bool, active: bool, desc: string}>
   */
  public function categories(): array {
    // Fonts are a live third-party request only under Google delivery; under
    // self-host (or system fonts) nothing leaves the origin for typography.
    $fontsActive = ($this->brand->webFont()['mode'] ?? 'none') === BrandRepository::DELIVERY_GOOGLE;

    return [
      [
        'id' => 'necessary',
        'label' => 'Necessary',
        'required' => TRUE,
        'active' => TRUE,
        'desc' => 'Essential for the site to function. Always on.',
      ],
      [
        'id' => 'fonts',
        'label' => 'External fonts',
        'required' => FALSE,
        'active' => $fontsActive,
        'desc' => 'Loads brand typefaces from Google Fonts. Your IP address is shared with Google while enabled.',
      ],
      [
        'id' => 'analytics',
        'label' => 'Analytics',
        'required' => FALSE,
        'active' => FALSE,
        'desc' => 'Anonymous usage measurement. Not in use on this site yet.',
      ],
      [
        'id' => 'embeds',
        'label' => 'Embeds',
        'required' => FALSE,
        'active' => FALSE,
        'desc' => 'Third-party embeds such as video or maps. Not in use on this site yet.',
      ],
    ];
  }

  /**
   * Whether the banner is needed — TRUE iff a non-required category is active.
   */
  public function isActive(): bool {
    foreach ($this->categories() as $category) {
      if (!$category['required'] && $category['active']) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * The JSON config the front-end reads (cookie name + categories).
   */
  public function configJson(): string {
    return (string) json_encode([
      'cookie' => self::COOKIE,
      'categories' => $this->categories(),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  }

}
