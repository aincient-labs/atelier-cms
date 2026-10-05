<?php

declare(strict_types=1);

namespace Drupal\aincient_pages\Theme;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Theme\ComponentNegotiator;
use Drupal\Core\Theme\ThemeManagerInterface;

/**
 * Core's SDC negotiation, with a way back to the ORIGINAL component.
 *
 * A pack may override a built-in through core SDC `replaces:` (the catalog
 * keeps the original's metadata; only the rendering changes). Two things need
 * the original anyway (DECISIONS 0455, P4a):
 *  - the owner's per-site "Use original instead" — the SDC ids in
 *    `aincient_pages.site_constraint:overrides_off` never negotiate, in the
 *    shell, the studio preview and the static export alike (all render through
 *    the one plugin manager);
 *  - the Components studio's Compare, which renders the same example twice —
 *    once inside {@see withOriginals()}.
 *
 * Returning NULL is core's own "no replacement" answer: the plugin manager
 * then instantiates the requested id itself. Twig's component cache key
 * carries the negotiated provider, so the two renderings never share a
 * compiled template ACROSS requests. Within one PHP process Drupal's Twig
 * environment memoises the template class per name, so render the original
 * and the override in separate requests (Compare does).
 */
class OverrideAwareComponentNegotiator extends ComponentNegotiator {

  /**
   * Inside {@see withOriginals()}: nothing negotiates.
   */
  private bool $originals = FALSE;

  public function __construct(
    ThemeManagerInterface $themeManager,
    private readonly ConfigFactoryInterface $configFactory,
  ) {
    parent::__construct($themeManager);
  }

  /**
   * {@inheritdoc}
   */
  public function negotiate(string $component_id, array $all_definitions): ?string {
    if ($this->originals || in_array($component_id, $this->overridesOff(), TRUE)) {
      return NULL;
    }
    return parent::negotiate($component_id, $all_definitions);
  }

  /**
   * Run $render with every override bypassed — the original components.
   *
   * @template T
   * @param callable(): T $render
   *
   * @return T
   */
  public function withOriginals(callable $render): mixed {
    $previous = $this->originals;
    $this->originals = TRUE;
    try {
      return $render();
    }
    finally {
      $this->originals = $previous;
    }
  }

  /**
   * The SDC ids the owner switched back to the original.
   *
   * @return list<string>
   */
  private function overridesOff(): array {
    $off = $this->configFactory->get('aincient_pages.site_constraint')->get('overrides_off');
    return is_array($off) ? array_values(array_filter($off, 'is_string')) : [];
  }

}
