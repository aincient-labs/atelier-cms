<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Kernel;

use Drupal\aincient_pages\SiteChrome;
use Drupal\KernelTests\KernelTestBase;
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
    'workflows', 'content_moderation', 'aincient_pages',
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
    // Empty brand note falls back to an auto © line.
    $this->assertStringStartsWith('©', $footer['note']);
    // The chrome layout variants ride along into the SDC props (defaults).
    $this->assertSame('left', $header['logo_position']);
    $this->assertTrue($header['sticky']);
    $this->assertSame('end', $header['nav_alignment']);
    $this->assertSame('inline', $footer['layout']);
    $this->assertTrue($footer['show_tagline']);
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
