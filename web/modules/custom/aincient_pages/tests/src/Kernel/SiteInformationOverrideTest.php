<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests SiteInformationOverrider: identity layered over system.site at read
 * time.
 *
 * The contract (DECISIONS 0183): the identity is the single write target for
 * the operator's site information; system.site stays a pristine shipped file.
 * A non-empty identity value overrides the matching system.site key; an empty
 * one lets the shipped default show through; the page slots are
 * `entity:node:<id>` tokens resolved to `/node/<id>` internal paths.
 *
 * @group aincient
 */
#[RunTestsInSeparateProcesses]
final class SiteInformationOverrideTest extends KernelTestBase {

  protected static $modules = [
    'system', 'field', 'text', 'file', 'image', 'media', 'user', 'node',
    'workflows', 'content_moderation', 'aincient_pages',
  ];

  /** The shipped system.site baseline the overrides layer onto. */
  private const SHIPPED = [
    'name' => 'Shipped name',
    'slogan' => '',
    'mail' => 'shipped@example.com',
    'page' => ['403' => '', '404' => '', 'front' => '/node'],
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installSchema('node', ['node_access']);
    // Installing the module's config also creates the shipped `aincient_page`
    // bundle (config/optional) — the landing pages the site slots reference.
    $this->installConfig(['field', 'media', 'workflows', 'content_moderation', 'aincient_pages']);
    $this->config('system.site')->setData(self::SHIPPED)->save();
  }

  private function identity(): \Drupal\aincient_pages\SiteIdentity {
    return $this->container->get('aincient_pages.site_identity');
  }

  /** The EFFECTIVE (override-applied) system.site as consumers read it. */
  private function effective(): \Drupal\Core\Config\ImmutableConfig {
    return $this->container->get('config.factory')->get('system.site');
  }

  private function makePage(): Node {
    $node = Node::create(['type' => 'aincient_page', 'title' => 'Landing', 'status' => 1]);
    $node->save();
    return $node;
  }

  public function testEmptyIdentityLeavesShippedDefaults(): void {
    $this->assertSame('Shipped name', $this->effective()->get('name'));
    $this->assertSame('shipped@example.com', $this->effective()->get('mail'));
    $this->assertSame('/node', $this->effective()->get('page.front'));
  }

  public function testBrandNameAndTaglineOverrideNameAndSlogan(): void {
    $this->identity()->update(['name' => 'Lumen', 'tagline' => 'Light, shaped.']);
    $this->assertSame('Lumen', $this->effective()->get('name'));
    $this->assertSame('Light, shaped.', $this->effective()->get('slogan'));
    // The RAW (stored) config stays pristine — this is what cex exports.
    $this->assertSame('Shipped name', $this->effective()->getRawData()['name']);
  }

  public function testSiteMailOverridesAndValidates(): void {
    $applied = $this->identity()->updateSite(['mail' => 'hello@lumen.example']);
    $this->assertSame(['site email'], $applied);
    $this->assertSame('hello@lumen.example', $this->effective()->get('mail'));

    // A malformed address is skipped, never applied.
    $this->assertSame([], $this->identity()->updateSite(['mail' => 'not-an-email']));
    $this->assertSame('hello@lumen.example', $this->effective()->get('mail'));

    // '' clears the override — the shipped default shows through again.
    $this->identity()->updateSite(['mail' => '']);
    $this->assertSame('shipped@example.com', $this->effective()->get('mail'));
  }

  public function testPageSlotsResolveTokensToInternalPaths(): void {
    $node = $this->makePage();
    $token = 'entity:node:' . $node->id();
    $applied = $this->identity()->updateSite(['front' => $token, 'page_404' => $token]);
    $this->assertSame(['front page', '404 page'], $applied);
    $this->assertSame('/node/' . $node->id(), $this->effective()->get('page.front'));
    $this->assertSame('/node/' . $node->id(), $this->effective()->get('page.404'));
    // The untouched slot keeps its shipped default.
    $this->assertSame('', $this->effective()->get('page.403'));
  }

  public function testNonNodeTokensAreRejected(): void {
    $this->assertSame([], $this->identity()->updateSite(['front' => 'media:3']));
    $this->assertSame([], $this->identity()->updateSite(['front' => '/hand-written/path']));
    $this->assertSame('/node', $this->effective()->get('page.front'));
  }

  public function testUnknownSiteKeysAreIgnored(): void {
    $this->assertSame([], $this->identity()->updateSite(['bogus' => 'x']));
    $this->assertArrayNotHasKey('bogus', $this->identity()->site());
  }

  public function testDeletingTheReferencedNodeClearsTheSlot(): void {
    $node = $this->makePage();
    $this->identity()->updateSite(['front' => 'entity:node:' . $node->id()]);
    $this->assertSame('/node/' . $node->id(), $this->effective()->get('page.front'));

    $node->delete();
    $this->assertSame('', $this->identity()->site()['front']);
    $this->container->get('config.factory')->reset('system.site');
    $this->assertSame('/node', $this->effective()->get('page.front'));
  }

  public function testOverriddenConfigCarriesIdentityCacheTag(): void {
    // Anything render-cached against system.site must invalidate when the
    // identity it mirrors is republished in the studio.
    $this->assertContains('config:aincient_pages.identity', $this->effective()->getCacheTags());
  }

}
