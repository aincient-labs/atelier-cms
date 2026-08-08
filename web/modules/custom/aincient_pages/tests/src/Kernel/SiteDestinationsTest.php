<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Kernel;

use Drupal\aincient_pages\SiteDestinations;
use Drupal\KernelTests\KernelTestBase;
use Drupal\menu_link_content\Entity\MenuLinkContent;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the bounded link-destination shortlist injected into the page agent's
 * prompt — the site's main menu, NEVER its page list.
 *
 * @group aincient
 */
#[RunTestsInSeparateProcesses]
final class SiteDestinationsTest extends KernelTestBase {

  protected static $modules = [
    'system',
    'user',
    'link',
    'menu_link_content',
    'node',
    'workflows', 'content_moderation', 'aincient_core', 'aincient_pages',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['workflows', 'content_moderation', 'aincient_pages']);
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('menu_link_content');
  }

  private function destinations(): SiteDestinations {
    return $this->container->get('aincient_pages.site_destinations');
  }

  /**
   * The block emits every live nav link in BOTH target forms a link prop
   * accepts — a page reference token and a plain path — verbatim, so the agent
   * can copy one straight into `cta_url`. Nesting is shown, not flattened away.
   */
  public function testEmitsMenuLinksInTheFormsLinkPropsAccept(): void {
    MenuLinkContent::create([
      'title' => 'Pricing',
      // A page reference: MenuRepository hands this back as entity:node:15 —
      // exactly the token form a link prop takes.
      'link' => ['uri' => 'entity:node/15'],
      'menu_name' => 'main',
      'weight' => 0,
    ])->save();
    $docs = MenuLinkContent::create([
      'title' => 'Docs',
      'link' => ['uri' => 'internal:/docs'],
      'menu_name' => 'main',
      'weight' => 1,
    ]);
    $docs->save();
    MenuLinkContent::create([
      'title' => 'Getting started',
      'link' => ['uri' => 'internal:/docs/start'],
      'menu_name' => 'main',
      'parent' => 'menu_link_content:' . $docs->uuid(),
      'weight' => 0,
    ])->save();

    $out = $this->destinations()->forPrompt();

    $this->assertStringContainsString('SITE DESTINATIONS', $out);
    $this->assertStringContainsString('- "Pricing" → entity:node:15', $out);
    $this->assertStringContainsString('- "Docs" → /docs', $out);
    // A child is indented under its parent, not dropped or flattened.
    $this->assertStringContainsString('  - "Getting started" → /docs/start', $out);
    // It must tell the agent this is the NAV, not the whole site — otherwise a
    // missing page reads as "no such page" instead of "go and search".
    $this->assertStringContainsString('find_reference', $out);
  }

  /**
   * A link the operator disabled is not part of the live navigation, so offering
   * it as a CTA target would surface something they deliberately hid.
   */
  public function testDisabledLinksAreNotOffered(): void {
    MenuLinkContent::create(['title' => 'Live', 'link' => ['uri' => 'internal:/live'], 'menu_name' => 'main'])->save();
    MenuLinkContent::create(['title' => 'Hidden', 'link' => ['uri' => 'internal:/hidden'], 'menu_name' => 'main', 'enabled' => FALSE])->save();

    $out = $this->destinations()->forPrompt();
    $this->assertStringContainsString('"Live"', $out);
    $this->assertStringNotContainsString('Hidden', $out);
  }

  /**
   * No menu yet → NO block at all. A heading with nothing under it would imply a
   * shortlist exists and quietly discourage the find_reference call that is the
   * only right move on a fresh site.
   */
  public function testEmptyMenuEmitsNothing(): void {
    $this->assertSame('', $this->destinations()->forPrompt());
  }

  /**
   * The block is BOUNDED — a pathological mega-menu can't grow the prompt without
   * limit — and when the cap bites it SAYS so rather than presenting a partial
   * list as the complete navigation.
   */
  public function testLongMenuIsCappedAndSaysSo(): void {
    for ($i = 0; $i < 40; $i++) {
      MenuLinkContent::create([
        'title' => 'Link ' . $i,
        'link' => ['uri' => 'internal:/p' . $i],
        'menu_name' => 'main',
        'weight' => $i,
      ])->save();
    }

    $out = $this->destinations()->forPrompt();
    $this->assertSame(30, substr_count($out, ' → '));
    $this->assertStringContainsString('Only the first 30 links are shown', $out);
  }

}
