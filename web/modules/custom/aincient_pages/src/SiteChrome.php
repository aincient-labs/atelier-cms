<?php

declare(strict_types=1);

namespace Drupal\aincient_pages;

use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Menu\MenuLinkTreeInterface;
use Drupal\Core\Menu\MenuTreeParameters;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Path\PathMatcherInterface;
use Drupal\Core\Url;

/**
 * The site chrome: the brand header + footer that wrap EVERY anonymous page.
 *
 * Single source of truth for the header/footer so the two render paths stay
 * consistent: the generated-page controller (full-bleed, at the aincient_page
 * node's own canonical URL) and the
 * `aincient_theme` front-end theme (every Drupal-native page) both build their
 * chrome from here. Identity (name/logo/tagline/footer note) comes from
 * {@see SiteIdentity}; the design-token style override from {@see BrandRepository}
 * (Foundations); the nav from Drupal's core `main`/`footer` menus.
 */
final class SiteChrome {

  /**
   * How deep the chrome nav renders. Top level + this many sublevels of nesting
   * (so 3 == top + 2 nested levels). Bump this to allow deeper dropdowns.
   */
  private const MAX_DEPTH = 3;

  /**
   * The default-on product attribution shown in the footer note bar.
   *
   * Uses the canonical PRODUCT name — "Atelier by AIncient Labs" — not the
   * distribution/"CMS" phrasing. This is AIncient's OWN brand (never the
   * operator's), so it lives here as a constant, not in the operator-editable
   * identity/chrome config; operators toggle only its VISIBILITY via the footer
   * `show_credit` setting ({@see ChromeRepository}).
   *
   * The `?ref=built-with` tag makes the attribution loop measurable: this credit
   * ships on every exported/self-hosted site, so referral visits back to the
   * marketing site are self-identifying regardless of the host domain (Umami
   * reads the query param). The path currently 302s to the homepage, forwarding
   * the query string, until a real product page exists.
   */
  public const CREDIT_LABEL = 'Atelier by AIncient Labs';
  public const CREDIT_URL = 'https://aincient-labs.com/product/atelier?ref=built-with';

  /** The footer attribution credit as an SDC prop `{label, href}`. */
  public static function credit(): array {
    return ['label' => self::CREDIT_LABEL, 'href' => self::CREDIT_URL];
  }

  public function __construct(
    private readonly MenuLinkTreeInterface $menuTree,
    private readonly BrandRepository $brand,
    private readonly SiteIdentity $identity,
    private readonly ChromeRepository $chrome,
    private readonly LanguageManagerInterface $languageManager,
    private readonly PathMatcherInterface $pathMatcher,
  ) {}

  /** Props for the `aincient_pages:site-header` SDC. */
  public function headerProps(): array {
    return [
      'name' => $this->identity->name(),
      'logo_url' => $this->identity->logoUrl(),
      'nav' => $this->nav('main'),
      'language_links' => $this->languageLinks(),
    ] + $this->chrome->header();
  }

  /**
   * Visitor-facing language-switch links for the current page, as a flat list of
   * `{langcode, label, url, active}`.
   *
   * Empty on a single-language site (the common case) — the header hides the
   * switcher when this is empty. When an operator adds a second language and
   * translates a page, this lights up automatically: each link points at the
   * same page under that language's URL (path-prefix negotiation), and `active`
   * marks the language the visitor is currently viewing. Mirrors what core's
   * language_block does, flattened for the SDC.
   */
  public function languageLinks(): array {
    $languages = $this->languageManager->getLanguages();
    if (count($languages) < 2) {
      return [];
    }
    $current = $this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_URL)->getId();
    // The current page as a route URL, re-emitted once per language: setting the
    // `language` option runs the URL path processor so each href carries that
    // language's path prefix (e.g. `/de/…`). More predictable than
    // getLanguageSwitchLinks(), which returns nothing in the full-bleed page
    // controller's route context.
    $route = $this->pathMatcher->isFrontPage() ? '<front>' : '<current>';
    $links = [];
    foreach ($languages as $langcode => $language) {
      $url = Url::fromRoute($route)->setOption('language', $language);
      $links[] = [
        'langcode' => $langcode,
        'label' => $language->getName(),
        'url' => $url->toString(),
        'active' => $langcode === $current,
      ];
    }
    return $links;
  }

  /** Props for the `aincient_pages:site-footer` SDC. */
  public function footerProps(): array {
    $note = $this->identity->footerNote();
    if ($note === '') {
      $note = '© ' . date('Y') . ' ' . ($this->identity->name() ?: 'AIncient');
    }
    return [
      'name' => $this->identity->name(),
      'tagline' => $this->identity->tagline(),
      'logo_url' => $this->identity->logoUrl(),
      'nav' => $this->nav('footer'),
      'note' => $note,
      'credit' => self::credit(),
    ] + $this->chrome->footer();
  }

  /**
   * A Drupal menu as a nested [{label, url, below: [...]}] tree.
   *
   * Sourced from core's menu system (managed at /admin/structure/menu, or the
   * Globals studio for `main`/`footer`) — access-checked and sorted by the menu's
   * own weights. Nesting is capped at {@see self::MAX_DEPTH}; `below` holds the
   * same shape recursively (empty for a leaf).
   */
  public function nav(string $menuName): array {
    $params = (new MenuTreeParameters())->onlyEnabledLinks()->setMaxDepth(self::MAX_DEPTH);
    $tree = $this->menuTree->transform($this->menuTree->load($menuName, $params), [
      ['callable' => 'menu.default_tree_manipulators:checkAccess'],
      ['callable' => 'menu.default_tree_manipulators:generateIndexAndSort'],
    ]);
    return $this->toLinks($tree);
  }

  /**
   * Recursively flatten a menu link tree into [{label, url, below}] nodes.
   *
   * @param array<mixed> $tree
   *   A list of \Drupal\Core\Menu\MenuLinkTreeElement, keyed/sorted by the
   *   generateIndexAndSort manipulator.
   */
  private function toLinks(array $tree): array {
    $links = [];
    foreach ($tree as $element) {
      if (!$element->link->isEnabled()) {
        continue;
      }
      $links[] = [
        'label' => (string) $element->link->getTitle(),
        'url' => $element->link->getUrlObject()->toString(),
        'below' => $element->subtree ? $this->toLinks($element->subtree) : [],
      ];
    }
    return $links;
  }

  /**
   * A render array for ANY Drupal menu, by name, via the generic menu SDC.
   *
   * The reusable entry point: give it a menu machine name and a presentation
   * variant ('header' dropdown / 'footer' grouped list) and get the
   * `aincient_pages:menu` component populated with that menu's nested links.
   */
  public function menu(string $menuName, string $variant = 'header'): array {
    return [
      '#type' => 'component',
      '#component' => 'aincient_pages:menu',
      '#props' => [
        'items' => $this->nav($menuName),
        'name' => $menuName,
        'variant' => $variant,
      ],
    ];
  }

  /**
   * The brand `<style>` payload for the document head: a CSS-var :root override
   * scoped to `html:root` so it always wins over the stylesheet's `:root`
   * token defaults regardless of head order. Empty string when no brand tokens
   * are set.
   */
  public function brandStyle(): string {
    $css = $this->brand->cssVariables();
    // cssVariables() emits ":root{…}"; bump specificity to html:root so the
    // inline override beats the compiled stylesheet's ":root{…}" defaults no
    // matter the <head> ordering (equal-specificity selectors are order-dependent).
    return $css === '' ? '' : preg_replace('/^:root/', 'html:root', $css, 1);
  }

}
