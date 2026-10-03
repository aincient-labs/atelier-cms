<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_brand\Kernel;

use Drupal\aincient_brand\Controller\BrandController;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The Identity studio's seams as a studio MODULE (plans/studio-modules.md Phase E.5).
 *
 * The last built-in: `design_system` declared by a manifest in the brand module
 * that moved tiers whole (the 0433 pattern), its three brand write routes
 * stamped with the on/off switch under their original paths, the no-AI admin
 * form left core, its six draft-only verbs claimed as the studio's, and —
 * because this was the last attribute plugin — General surviving as the
 * manager's own definition.
 *
 * @group aincient
 */
#[RunTestsInSeparateProcesses]
final class IdentityStudioModuleTest extends KernelTestBase {

  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'node',
    'key',
    'workflows',
    'content_moderation',
    'aincient_core',
    'aincient_pages',
    'aincient_chat',
    'aincient_brand',
  ];

  /**
   * The studio is discovered from the manifest under the module's provider,
   * with its frozen id, weight, admin label, four flows and six verbs.
   */
  public function testStudioComesFromTheManifest(): void {
    $manager = $this->container->get('plugin.manager.aincient.studios');

    $identity = $manager->get('design_system');
    $this->assertNotNull($identity);
    $this->assertSame('aincient_brand', $identity->getPluginDefinition()['provider']);
    $this->assertSame('Identity', $identity->label());
    $this->assertSame(10, $identity->weight());
    $this->assertSame('ui/index.tsx', $identity->uiEntry());
    $this->assertSame('use aincient studio design_system', $identity->permission());
    $this->assertSame(
      ['brand_studio', 'aincient_brand_specialist_colour', 'aincient_brand_specialist_shape', 'aincient_brand_specialist_typography'],
      $identity->flows(),
    );
    $this->assertSame(
      array_map(static fn(string $slug): string => "aincient_brand:$slug", ['brand_picker', 'preview_brand', 'propose_brand_status', 'propose_design_tokens', 'reset_preview', 'route_logo_image']),
      $identity->capabilities(),
    );

    // Identity is the lightest studio and sorts first after General.
    $this->assertSame(['general', 'design_system'], $manager->keys());
  }

  /**
   * General is the manager's own definition now that no attribute plugin
   * exists: open, no permission, the default — and a manifest cannot take it.
   */
  public function testGeneralIsTheManagersOwn(): void {
    $manager = $this->container->get('plugin.manager.aincient.studios');
    $general = $manager->get('general');
    $this->assertNotNull($general);
    $this->assertSame('aincient_chat', $general->getPluginDefinition()['provider']);
    $this->assertTrue($general->isOpen());
    $this->assertNull($general->permission());
    $this->assertSame('general', $manager->defaultId());
    $this->assertNull($general->uiEntry());
    $this->assertSame([], $general->flows());
    $this->assertSame([], $general->capabilities());
  }

  /**
   * The three brand write routes are this studio's: gated by its derived
   * permission, stamped by the route subscriber so a switched-off Identity 403s
   * them, under the paths the brand rail fetches. The no-AI admin form and the
   * revision history stay core, under the administer permission, unstamped.
   */
  public function testBrandRoutesAreGatedByTheIdentityStudio(): void {
    $this->container->get('router.builder')->rebuild();
    $provider = $this->container->get('router.route_provider');
    $this->assertInstanceOf(RouteProviderInterface::class, $provider);

    $routes = [
      'manifest' => ['/atelier/brand/manifest', 'GET', 'manifest'],
      'save' => ['/atelier/brand/save', 'POST', 'save'],
      'status' => ['/atelier/brand/status', 'POST', 'setStatus'],
    ];
    foreach ($routes as $name => [$path, $method, $action]) {
      $route = $provider->getRouteByName("aincient_brand.brand_$name");
      $this->assertSame($path, $route->getPath(), $name);
      $this->assertSame([$method], $route->getMethods(), $name);
      $this->assertSame('use aincient studio design_system', $route->getRequirement('_permission'), $name);
      $this->assertSame('design_system', $route->getRequirement('_aincient_studio_enabled'), $name);
      $this->assertSame('\\' . BrandController::class . "::$action", $route->getDefault('_controller'), $name);
    }
    $this->assertCount(0, $provider->getRoutesByNames(['aincient_pages.brand_save']), 'The old name is gone.');

    foreach (['aincient_pages.brand_form', 'aincient_pages.brand_history'] as $name) {
      $route = $provider->getRouteByName($name);
      $this->assertSame('administer aincient pages', $route->getRequirement('_permission'), $name);
      $this->assertNull($route->getRequirement('_aincient_studio_enabled'), "$name is the operator's no-AI path, core's.");
    }

    // The chrome endpoints Identity SHARES with the Site studios are core's.
    $route = $provider->getRouteByName('aincient_pages.chrome_save');
    $this->assertStringContainsString('+', (string) $route->getRequirement('_permission'));
    $this->assertNull($route->getRequirement('_aincient_studio_enabled'));
  }

  /**
   * The six brand verbs are discovered under this module (ids unchanged — the
   * module kept its name) and refused together when the studio is off; the
   * two core FlowDrop processors the agent composes with are not verbs.
   */
  public function testBrandVerbsAreTheStudios(): void {
    $definitions = $this->container->get('plugin.manager.aincient.capabilities')->getDefinitions();
    $gates = $this->container->get('aincient_core.capability_gates');
    $ids = ['brand_picker', 'preview_brand', 'propose_brand_status', 'propose_design_tokens', 'reset_preview', 'route_logo_image'];
    foreach ($ids as $slug) {
      $this->assertSame('aincient_brand', $definitions["aincient_brand:$slug"]['provider'], $slug);
      $this->assertNull($gates->refusal("aincient_brand:$slug"), "$slug runs while the studio is on.");
    }

    $this->config('aincient_chat.settings')->set('disabled_studios', ['design_system'])->save();
    foreach ($ids as $slug) {
      $this->assertNotNull($gates->refusal("aincient_brand:$slug"), "$slug is refused while the studio is off.");
    }
    $this->assertNull($gates->refusal('aincient_pages:list_pages'), 'A core verb is untouched.');
  }

}
