<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Kernel;

use Drupal\aincient_pages\SiteChrome;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use Drupal\menu_link_content\Entity\MenuLinkContent;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the shared site chrome: menu-sourced nav + brand-token injection.
 *
 * @group aincient
 */
#[RunTestsInSeparateProcesses]
final class SiteChromeTest extends KernelTestBase {

  protected static $modules = [
    'system',
    'user',
    'link',
    'menu_link_content',
    'language',
    'field', 'text', 'filter', 'node', 'content_translation',
    'workflows', 'content_moderation', 'aincient_core', 'aincient_pages',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['workflows', 'content_moderation', 'aincient_pages']);
    $this->installEntitySchema('user');
    $this->installEntitySchema('menu_link_content');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['user']);
    // nav() runs core's checkAccess manipulator as the CURRENT user, which is
    // anonymous here — without this every node-backed menu link is filtered out
    // and the assertions below would pass for the wrong reason.
    $anonymous = Role::load(RoleInterface::ANONYMOUS_ID)
      ?: Role::create(['id' => RoleInterface::ANONYMOUS_ID, 'label' => 'Anonymous']);
    $anonymous->grantPermission('access content')->save();
    // ContentEntityBase::isTranslatable() is FALSE until the BUNDLE is marked
    // translatable — which is also why the production guard is right: with no
    // per-bundle translation configured there are no translations to be missing.
    $this->container->get('content_translation.manager')
      ->setEnabled('node', 'aincient_page', TRUE);
    $this->installConfig(['node', 'filter']);
    // aincient_pages ships the `aincient_page` type in its own config.
    if (NodeType::load('aincient_page') === NULL) {
      NodeType::create(['type' => 'aincient_page', 'name' => 'Page'])->save();
    }
  }

  private function chrome(): SiteChrome {
    return $this->container->get('aincient_pages.chrome');
  }

  public function testNavReadsCoreMenu(): void {
    MenuLinkContent::create(['title' => 'Journeys', 'link' => ['uri' => 'internal:/'], 'menu_name' => 'main'])->save();
    MenuLinkContent::create(['title' => 'Privacy', 'link' => ['uri' => 'internal:/'], 'menu_name' => 'footer'])->save();

    $main = $this->chrome()->nav('main');
    $footer = $this->chrome()->nav('footer');

    $this->assertContains('Journeys', array_column($main, 'label'));
    $this->assertContains('Privacy', array_column($footer, 'label'));
    // A footer link is not a main link and vice versa.
    $this->assertNotContains('Privacy', array_column($main, 'label'));
  }

  public function testHeaderFooterPropsShape(): void {
    $header = $this->chrome()->headerProps();
    $footer = $this->chrome()->footerProps();
    $this->assertArrayHasKey('nav', $header);
    $this->assertArrayHasKey('name', $header);
    // The language switcher rides along; empty on a single-language site.
    $this->assertArrayHasKey('language_links', $header);
    $this->assertSame([], $header['language_links']);
    // Empty brand note falls back to an auto © line.
    $this->assertStringStartsWith('©', $footer['note']);
    // The chrome layout variants ride along into the SDC props (defaults).
    $this->assertSame('left', $header['logo_position']);
    $this->assertSame('medium', $header['logo_size']);
    $this->assertTrue($header['sticky']);
    $this->assertSame('end', $header['nav_alignment']);
    $this->assertSame('inline', $footer['layout']);
    $this->assertSame('medium', $footer['logo_size']);
    $this->assertTrue($footer['show_tagline']);
  }

  /**
   * A many-language site: every configured language is offered, each carrying
   * its endonym so the header can list "Deutsch" rather than "German", and with
   * `translated` defaulting TRUE on a route that has no content entity to ask.
   */
  public function testLanguageLinksCarryEndonymsAndTranslationState(): void {
    foreach (['de', 'fr', 'es', 'ja'] as $langcode) {
      ConfigurableLanguage::createFromLangcode($langcode)->save();
    }

    $links = $this->chrome()->languageLinks();

    // English (default) + the four added.
    $this->assertCount(5, $links);
    $byCode = array_column($links, NULL, 'langcode');
    $this->assertSame('Deutsch', $byCode['de']['native']);
    $this->assertSame('日本語', $byCode['ja']['native']);
    // `label` stays the site-language name; `native` is the endonym.
    $this->assertSame('German', $byCode['de']['label']);
    // Exactly one active language, and it is the one being viewed.
    $this->assertSame(['en'], array_column(array_filter($links, static fn(array $l) => $l['active']), 'langcode'));
    // No content entity on this route => every language counts as available
    // rather than everything being marked untranslated.
    $this->assertSame([TRUE, TRUE, TRUE, TRUE, TRUE], array_column($links, 'translated'));
  }

  /**
   * The switcher's hrefs come from `<current>`, whose outbound route processor
   * varies by route — so the chrome MUST hand that cache context on. Losing it
   * (the plain Url::toString() drops all bubbleable metadata) let one page's
   * header be reused on another: the static exporter renders the front page
   * first, and every later frozen page then linked the language FRONT pages
   * instead of its own translations (pitch-demo report, 12 Sep 2026).
   */
  public function testLanguageLinksCarryTheRouteCacheContext(): void {
    ConfigurableLanguage::createFromLangcode('de')->save();

    $this->chrome()->languageLinks();
    $contexts = $this->chrome()->cacheability()->getCacheContexts();

    $this->assertContains('route', $contexts);
    $this->assertContains('languages:language_url', $contexts);
  }

  /**
   * A menu entry whose target has no translation in the language being browsed
   * points at a page that does not exist there — live Drupal would render the
   * default-language copy under a foreign prefix, and a frozen snapshot has
   * nothing to serve at all. It is dropped, not linked and not labelled: a
   * visible "untranslated" marker would itself need translating into every
   * language (DECISIONS 0421; contrast the switcher's 0420 rule, which keeps a
   * stable shape because it is how you change language).
   */
  public function testNavDropsEntriesWithNoTranslationInTheCurrentLanguage(): void {
    // BOTH as config entities: the default language is otherwise only the
    // LanguageDefault service, so switching it below would leave the site
    // monolingual — and isTranslatable() is FALSE on a monolingual site, which
    // would make every assertion here pass for the wrong reason.
    if (ConfigurableLanguage::load('en') === NULL) {
      ConfigurableLanguage::createFromLangcode('en')->save();
    }
    ConfigurableLanguage::createFromLangcode('de')->save();

    $english = Node::create(['type' => 'aincient_page', 'title' => 'English only', 'status' => 1]);
    $english->save();
    $both = Node::create(['type' => 'aincient_page', 'title' => 'Both', 'status' => 1]);
    $both->save();
    $both->addTranslation('de', ['title' => 'Beide'])->save();

    foreach ([$english, $both] as $node) {
      MenuLinkContent::create([
        'title' => $node->label(),
        'link' => ['uri' => 'entity:node/' . $node->id()],
        'menu_name' => 'main',
      ])->save();
    }
    // An external entry is language-agnostic and must survive either way.
    MenuLinkContent::create(['title' => 'Elsewhere', 'link' => ['uri' => 'https://example.com'], 'menu_name' => 'main'])->save();

    // Browsing in English: everything is offered.
    $labels = array_column($this->chrome()->nav('main'), 'label');
    $this->assertContains('English only', $labels);
    $this->assertContains('Both', $labels);
    $this->assertContains('Elsewhere', $labels);

    // Browsing in German: the untranslated page is gone, the rest stays.
    $this->container->get('language_manager')
      ->setConfigOverrideLanguage(ConfigurableLanguage::load('de'));
    \Drupal::service('language.default')->set(ConfigurableLanguage::load('de'));
    $this->container->get('language_manager')->reset();

    $labels = array_column($this->chrome()->nav('main'), 'label');
    $this->assertNotContains('English only', $labels);
    $this->assertContains('Both', $labels);
    $this->assertContains('Elsewhere', $labels);
  }

  public function testNavNestsChildLinks(): void {
    $parent = MenuLinkContent::create(['title' => 'Products', 'link' => ['uri' => 'internal:/'], 'menu_name' => 'main', 'weight' => 0]);
    $parent->save();
    MenuLinkContent::create(['title' => 'Widgets', 'link' => ['uri' => 'internal:/'], 'menu_name' => 'main', 'weight' => 0, 'parent' => $parent->getPluginId()])->save();

    $main = $this->chrome()->nav('main');
    $products = NULL;
    foreach ($main as $node) {
      if ($node['label'] === 'Products') {
        $products = $node;
      }
    }
    $this->assertNotNull($products);
    // Each node exposes a recursive `below`; the child rides under its parent.
    $this->assertSame(['Widgets'], array_column($products['below'], 'label'));
  }

  public function testMenuHelperBuildsComponentRenderArray(): void {
    MenuLinkContent::create(['title' => 'About', 'link' => ['uri' => 'internal:/'], 'menu_name' => 'main'])->save();
    $build = $this->chrome()->menu('main', 'header');
    $this->assertSame('component', $build['#type']);
    $this->assertSame('aincient_pages:menu', $build['#component']);
    $this->assertSame('main', $build['#props']['name']);
    $this->assertSame('header', $build['#props']['variant']);
    $this->assertContains('About', array_column($build['#props']['items'], 'label'));
  }

  public function testBrandStyleScopesToHtmlRoot(): void {
    $this->chrome()->nav('main');
    // A raw colour is allowed at Tier 1 (the exact-match brand escape hatch).
    $this->container->get('aincient_pages.brand')->update(['brand_primary' => '#ff7f66']);
    $style = $this->chrome()->brandStyle();
    // Scoped to html:root so it beats the stylesheet's ":root{…}" token defaults.
    $this->assertStringStartsWith('html:root{', $style);
    $this->assertStringContainsString('--brand-primary:#ff7f66', $style);
  }

}
