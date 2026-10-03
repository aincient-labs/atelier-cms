<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_studio_site\Kernel;

use Drupal\aincient_studio_site\Controller\ExamplesController;
use Drupal\aincient_studio_site\Controller\SnapshotActionsController;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The Site studios' seams as a studio MODULE (plans/studio-modules.md Phase E.3).
 *
 * Two frozen ids (`globals`, `settings`) declared by one manifest instead of
 * two `#[Studio]` classes in `aincient_chat`; the Settings studio's four
 * snapshot action routes stamped with the on/off switch; the shared chrome
 * routes left ungated as core's; and the chrome agent's one verb claimed as
 * the `globals` studio's own.
 *
 * @group aincient
 */
#[RunTestsInSeparateProcesses]
final class SiteStudioModuleTest extends KernelTestBase {

  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'node',
    'key',
    'file',
    'image',
    'workflows',
    'content_moderation',
    'aincient_core',
    'aincient_pages',
    'aincient_export',
    'aincient_chat',
    'aincient_studio_site',
  ];

  /**
   * Both studios are discovered from the manifest under the module's provider,
   * with their frozen ids and weights; only `globals` has an agent and a verb.
   */
  public function testStudiosComeFromTheManifest(): void {
    $manager = $this->container->get('plugin.manager.aincient.studios');

    $globals = $manager->get('globals');
    $this->assertNotNull($globals);
    $this->assertSame('aincient_studio_site', $globals->getPluginDefinition()['provider']);
    $this->assertSame('Navigation & Pages', $globals->label());
    $this->assertSame(20, $globals->weight());
    $this->assertSame('ui/index.tsx', $globals->uiEntry());
    $this->assertSame('use aincient studio globals', $globals->permission());
    $this->assertSame(['aincient_studio_site:preview_chrome'], $globals->capabilities());
    $this->assertSame(['aincient_chrome_agent'], $globals->flows());

    $settings = $manager->get('settings');
    $this->assertNotNull($settings);
    $this->assertSame('aincient_studio_site', $settings->getPluginDefinition()['provider']);
    $this->assertSame('Settings', $settings->label());
    $this->assertSame(30, $settings->weight());
    // A SECOND entry file in the same ui/ — one module, two UIs.
    $this->assertSame('ui/settings.tsx', $settings->uiEntry());
    $this->assertSame('use aincient studio settings', $settings->permission());
    $this->assertSame([], $settings->capabilities());
    $this->assertSame([], $settings->flows());
  }

  /**
   * The four Freeze & Live actions are the Settings studio's: gated by its
   * derived permission, stamped by the route subscriber so a switched-off
   * Settings 403s them, under the paths the snapshots section fetches. The
   * read-only list stays core's and ungated.
   */
  public function testSnapshotActionsAreGatedByTheSettingsStudio(): void {
    $this->container->get('router.builder')->rebuild();
    $provider = $this->container->get('router.route_provider');
    $this->assertInstanceOf(RouteProviderInterface::class, $provider);

    foreach (['freeze', 'use', 'keep', 'delete'] as $action) {
      $route = $provider->getRouteByName("aincient_studio_site.snapshot_$action");
      $this->assertSame("/atelier/snapshots/$action", $route->getPath(), $action);
      $this->assertSame(['POST'], $route->getMethods(), $action);
      $this->assertSame('use aincient studio settings', $route->getRequirement('_permission'), $action);
      $this->assertSame('settings', $route->getRequirement('_aincient_studio_enabled'), $action);
      $this->assertSame('\\' . SnapshotActionsController::class . "::$action", $route->getDefault('_controller'), $action);
    }

    // "Clear examples" (DECISIONS 0441) is the Settings studio's the same way.
    $examples = $provider->getRouteByName('aincient_studio_site.examples');
    $this->assertSame('/atelier/examples', $examples->getPath());
    $this->assertSame(['GET'], $examples->getMethods());
    $this->assertSame('use aincient studio settings', $examples->getRequirement('_permission'));
    $this->assertSame('settings', $examples->getRequirement('_aincient_studio_enabled'));
    $this->assertSame('\\' . ExamplesController::class . '::list', $examples->getDefault('_controller'));
    $clear = $provider->getRouteByName('aincient_studio_site.examples_clear');
    $this->assertSame('/atelier/examples/clear', $clear->getPath());
    $this->assertSame(['POST'], $clear->getMethods());
    $this->assertSame('settings', $clear->getRequirement('_aincient_studio_enabled'));
    $this->assertSame('\\' . ExamplesController::class . '::clear', $clear->getDefault('_controller'));

    $list = $provider->getRouteByName('aincient_export.snapshots');
    $this->assertSame('/atelier/snapshots', $list->getPath());
    $this->assertSame('use aincient operator console', $list->getRequirement('_permission'));
    $this->assertNull($list->getRequirement('_aincient_studio_enabled'), 'The read-only list is core\'s.');

    // The chrome endpoints the two studios SHARE with Identity (design_system)
    // are core's: a `+` permission, so the subscriber stamps no studio on them
    // and switching either studio off leaves the other two surfaces whole.
    foreach (['aincient_pages.chrome_manifest', 'aincient_pages.chrome_preview', 'aincient_pages.chrome_save'] as $name) {
      $route = $provider->getRouteByName($name);
      $this->assertStringContainsString('+', (string) $route->getRequirement('_permission'), $name);
      $this->assertNull($route->getRequirement('_aincient_studio_enabled'), $name);
    }
  }

  /**
   * The chrome agent's verb is discovered under this module, and nowhere else;
   * the applier it validates with is this module's service.
   */
  public function testPreviewChromeIsTheStudios(): void {
    $definitions = $this->container->get('plugin.manager.aincient.capabilities')->getDefinitions();
    $this->assertArrayHasKey('aincient_studio_site:preview_chrome', $definitions);
    $this->assertSame('aincient_studio_site', $definitions['aincient_studio_site:preview_chrome']['provider']);
    $this->assertArrayNotHasKey('aincient_pages:preview_chrome', $definitions);
    $this->assertTrue($this->container->has('aincient_studio_site.chrome_preview_applier'));
    $this->assertFalse($this->container->has('aincient_pages.chrome_preview_applier'));
  }

}
