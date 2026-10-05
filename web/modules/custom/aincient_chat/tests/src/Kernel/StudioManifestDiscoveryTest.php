<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_chat\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\aincient_chat\StudioPermissions;
use Drupal\aincient_chat\Studio\Studio;
use Drupal\aincient_chat\Studio\StudioManager;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * A studio declared by a `<module>.studios.yml` manifest alone (DECISIONS 0430).
 *
 * The fixture `aincient_studio_test` ships no studio class — only a manifest —
 * so this proves the YAML discovery, the default class, that every manifest
 * key reads through StudioBase, and that the derived permission is minted
 * exactly as for an attribute studio. `aincient_studio_bad_test` ships an
 * invalid manifest and proves 0425's rule: dropped with a warning, never
 * thrown, and the rest of the set unharmed.
 *
 * @group aincient
 * @covers \Drupal\aincient_chat\Studio\StudioManager
 * @covers \Drupal\aincient_chat\Studio\StudioBase
 * @covers \Drupal\aincient_chat\Studio\StudioManifest
 */
#[RunTestsInSeparateProcesses]
final class StudioManifestDiscoveryTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'node',
    'key',
    'aincient_core',
    'workflows',
    'content_moderation',
    'aincient_pages',
    'aincient_chat',
    'aincient_studio_components',
    'aincient_studio_content',
    'aincient_studio_media',
    'aincient_studio_site',
    'aincient_studio_test',
    'aincient_studio_bad_test',
  ];

  /**
   * The one studio that is not a manifest: General, the manager's own
   * (DECISIONS 0436). Every former attribute built-in is a studio module now —
   * Components (Phase B, 0431), the Library family (0432), Checks
   * (`aincient_audit`, 0433 — its container needs metatag + the FlowDrop
   * closure, so its own module test asserts it), the Site studios (0434),
   * Content (0435) and Identity (`aincient_brand`, 0436 — not enabled here, its
   * own module test asserts it).
   */
  private const BUILT_IN = [
    'general',
  ];

  private function manager(): StudioManager {
    $manager = $this->container->get('plugin.manager.aincient.studios');
    $this->assertInstanceOf(StudioManager::class, $manager);
    return $manager;
  }

  /**
   * The manifest studio joins the set after the built-ins (weight 500), and
   * the malformed one is not in it at all.
   */
  public function testManifestStudioIsDiscoveredAndBadOneDropped(): void {
    // Components (weight 40, from its own module) sorts between settings and
    // content exactly where its attribute plugin used to — the id and the
    // order are frozen (0425), only the home moved.
    // Weight order, exactly where the attribute plugins used to sit: the Site
    // studios (20 + 30, one module, two ids — Phase E.3), Components (40),
    // Content (50, Phase E.4), the Library family (60 + 70, one module, two
    // ids). Identity (10) and Checks are not enabled here.
    $expected = [...self::BUILT_IN, 'globals', 'settings', 'components', 'content'];
    $this->assertSame([...$expected, 'library', 'media', 'studio_test'], $this->manager()->keys());
    $this->assertNull($this->manager()->get('studio_bad_test'));
  }

  /**
   * The first real studio module reads through like the fixture: provider is
   * the module, the UI entry, its agent flow and its one verb are declared
   * (DECISIONS 0455).
   */
  public function testComponentsStudioComesFromItsModule(): void {
    $studio = $this->manager()->get('components');
    $this->assertNotNull($studio);
    $this->assertSame('aincient_studio_components', $studio->getPluginDefinition()['provider']);
    $this->assertSame('Components', $studio->label());
    $this->assertSame(40, $studio->weight());
    $this->assertSame('ui/index.tsx', $studio->uiEntry());
    $this->assertSame(['aincient_components_agent'], $studio->flows());
    $this->assertSame(['aincient_studio_components:propose_component_constraint'], $studio->capabilities());
    $this->assertSame('use aincient studio components', $studio->permission());
  }

  /**
   * Every manifest key reads through, with the defaults where it is silent.
   */
  public function testManifestKeysReadThrough(): void {
    $studio = $this->manager()->get('studio_test');
    $this->assertInstanceOf(Studio::class, $studio, 'A manifest with no class: gets the default Studio.');
    $this->assertSame('aincient_studio_test', $studio->getPluginDefinition()['provider']);
    $this->assertSame('Studio test', $studio->label());
    $this->assertSame('A fixture studio for the kernel tests.', $studio->description());
    $this->assertSame('Nothing to see here.', $studio->help());
    $this->assertSame(500, $studio->weight());
    $this->assertFalse($studio->isOpen());
    $this->assertSame(['aincient_studio_test:ping'], $studio->capabilities(), 'Bare slugs are prefixed with the provider.');
    $this->assertSame([], $studio->flows());
    $this->assertNull($studio->uiEntry());
    $this->assertNull($studio->demoPath());
    $this->assertTrue($studio->defaultEnabled());
    $this->assertSame('use aincient studio studio_test', $studio->permission());
  }

  /**
   * The permissions page mints the manifest studio's permission from the same
   * rule as the built-ins — a manifest cannot ship a studio ungated.
   */
  public function testPermissionMintedForManifestStudio(): void {
    $permissions = StudioPermissions::create($this->container)->permissions();
    $this->assertArrayHasKey('use aincient studio studio_test', $permissions);
    $this->assertTrue($permissions['use aincient studio studio_test']['restrict access']);
    $this->assertArrayNotHasKey('use aincient studio studio_bad_test', $permissions);
  }

  /**
   * The attribute built-ins answer the manifest-era reads with their defaults:
   * the new interface methods must not change what an existing studio is.
   */
  public function testBuiltInsAnswerNewReadsWithDefaults(): void {
    foreach (self::BUILT_IN as $id) {
      $studio = $this->manager()->get($id);
      $this->assertNotNull($studio, $id);
      $this->assertSame('aincient_chat', $studio->getPluginDefinition()['provider'], $id);
      $this->assertSame([], $studio->capabilities(), $id);
      $this->assertSame([], $studio->flows(), $id);
      $this->assertNull($studio->uiEntry(), $id);
      $this->assertNull($studio->demoPath(), $id);
      $this->assertTrue($studio->defaultEnabled(), $id);
      // General is the manager's own definition (0436): open, and it carries
      // the description + help a manifest would, so the catalog reads it like
      // any other studio.
      $this->assertTrue($studio->isOpen(), $id);
      $this->assertNotSame('', $studio->description(), $id);
      $this->assertNotSame('', $studio->help(), $id);
    }
  }

}
