<?php

declare(strict_types=1);

namespace Drupal\aincient_pages;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Menu\MenuLinkTreeInterface;
use Drupal\Core\Menu\MenuTreeParameters;
use Drupal\Core\Language\LanguageManager;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
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
   * Uses the canonical PRODUCT name in full — "Atelier CMS by AIncient Labs" —
   * never the short form "Atelier" alone, because this credit is the one brand
   * surface that ships on every self-hosted site and is what people then search
   * for. This is AIncient's OWN brand (never the
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
  public const CREDIT_LABEL = 'Atelier CMS by AIncient Labs';
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
    private readonly ConfigFactoryInterface $configFactory,
    private readonly RouteMatchInterface $routeMatch,
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
   * `{langcode, label, native, url, active, translated}`.
   *
   * Empty on a single-language site (the common case) — the header hides the
   * switcher when this is empty. Each entry points at the SAME page under that
   * language's URL (path-prefix negotiation); `active` marks the language being
   * viewed and `translated` says whether the page this URL resolves to actually
   * has a translation in that language (FALSE means the visitor lands on the
   * fallback rendering — the header marks those rather than hiding them, so the
   * switcher's shape stays stable across a site's pages).
   *
   * `label` is the language's name in the SITE's language (what Drupal's
   * language list holds); `native` is its endonym — "Deutsch", not "German" —
   * because a visitor scanning a 9-language list is looking for their own
   * language written the way they write it. Falls back to `label` for a custom
   * language core has no endonym for.
   */
  /**
   * Cacheability collected while building the chrome (see languageLinks()).
   */
  private ?CacheableMetadata $cacheability = NULL;

  /**
   * The cache metadata the chrome's links depend on.
   *
   * MUST be applied to the render array that carries headerProps(): the
   * language switcher points at `<current>`, whose outbound route processor
   * varies by route. Without it the header is cached route-agnostically and
   * the first page rendered in a process poisons every later one — which is
   * exactly what the static exporter did (front page first, so every frozen
   * page linked the language FRONT pages instead of its own translations).
   */
  public function cacheability(): CacheableMetadata {
    return $this->cacheability ?? new CacheableMetadata();
  }

  /**
   * Whether the CURRENT route is the site's front page.
   *
   * Deliberately NOT `path.matcher`: PathMatcher::isFrontPage() memoizes its
   * answer in a service that lives for the whole process and is never reset.
   * That is harmless in a web request (one page per process) and wrong in the
   * static exporter, which renders every page in ONE process — the front page
   * goes first, so every later page inherited its TRUE and emitted front-page
   * language links. Same reasoning as PageMetatags::isFrontPage().
   */
  private function isFrontPage(): bool {
    if ($this->routeMatch->getRouteName() === NULL) {
      return FALSE;
    }
    $url = Url::fromRouteMatch($this->routeMatch);
    if (!$url->isRouted()) {
      return FALSE;
    }
    $front = trim((string) $this->configFactory->get('system.site')->get('page.front'));
    return $front !== '' && '/' . $url->getInternalPath() === $front;
  }

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
    // Error pages have no translations of their own: `<current>` there would
    // emit `/de/system/404`, which is neither a real page (the exporter's link
    // check flags it) nor useful to a visitor. Send them to each language's
    // front page instead.
    $errorRoute = in_array($this->routeMatch->getRouteName(), ['system.404', 'system.403'], TRUE);
    $route = ($errorRoute || $this->isFrontPage()) ? '<front>' : '<current>';
    // Core's canonical endonym table, keyed by langcode => [English, native].
    $standard = LanguageManager::getStandardLanguageList();
    $entity = $this->routeEntity();
    $metadata = $this->cacheability ?? new CacheableMetadata();
    $links = [];
    foreach ($languages as $langcode => $language) {
      $translated = $entity === NULL || $entity->hasTranslation($langcode);
      // A language this page has no translation in has no page to link to: in a
      // FROZEN snapshot that target simply does not exist (the export holds a
      // page once per real translation), and on the live site it would render
      // the default-language copy under a foreign prefix — the "English text
      // under a 简体中文 label" the pitch-demo report called out. Point at that
      // language's front page instead; the row still carries its "untranslated"
      // marker, so the affordance stays honest and live matches frozen.
      $target = $translated ? $route : '<front>';
      $url = Url::fromRoute($target)->setOption('language', $language);
      // toString(TRUE): the plain toString() DISCARDS the bubbleable metadata,
      // including the 'route' cache context RouteProcessorCurrent adds for
      // `<current>`. Dropping it is what let one page's switcher be reused on
      // every other page. Collect it and let cacheability() hand it on.
      $generated = $url->toString(TRUE);
      $metadata = $metadata->merge(CacheableMetadata::createFromObject($generated));
      $links[] = [
        'langcode' => $langcode,
        'label' => $language->getName(),
        'native' => (string) ($standard[$langcode][1] ?? $language->getName()),
        'url' => $generated->getGeneratedUrl(),
        'active' => $langcode === $current,
        'translated' => $translated,
      ];
    }
    // The set of links (and which one is active) also varies by the negotiated
    // URL language, and by the entity whose translations they were read from.
    $metadata->addCacheContexts(['languages:' . LanguageInterface::TYPE_URL]);
    if ($entity !== NULL) {
      $metadata->addCacheableDependency($entity);
    }
    $this->cacheability = $metadata;
    return $links;
  }

  /**
   * The content entity the current route renders, or NULL when there isn't one.
   *
   * Used only to answer "is this page translated into language X". A route with
   * no entity (a view, a listing, the login form) returns NULL, which the caller
   * reads as "can't tell" and treats every language as available — better than
   * marking everything untranslated on pages where translation isn't an entity
   * property at all.
   *
   * Note this reads the FIRST content-entity upcast parameter, which is the page
   * being viewed on every canonical route we render chrome for.
   */
  private function routeEntity(): ?ContentEntityInterface {
    foreach ($this->routeMatch->getParameters() as $parameter) {
      if ($parameter instanceof ContentEntityInterface && $parameter->isTranslatable()) {
        return $parameter;
      }
    }
    return NULL;
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
