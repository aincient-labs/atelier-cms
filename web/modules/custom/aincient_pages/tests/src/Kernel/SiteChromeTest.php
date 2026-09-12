<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Kernel;

use Drupal\aincient_pages\SiteChrome;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
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
    'workflows', 'content_moderation', 'aincient_core', 'aincient_pages',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['workflows', 'content_moderation', 'aincient_pages']);
    $this->installEntitySchema('user');
    $this->installEntitySchema('menu_link_content');
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
