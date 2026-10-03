<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_audit\Kernel;

use Drupal\aincient_audit\Controller\AuditController;
use Drupal\aincient_audit\Plugin\FlowDropNodeProcessor\PolicyCheck;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The Checks studio's seams as a studio MODULE (plans/studio-modules.md Phase E).
 *
 * The module moved tiers under its historical machine name (DECISIONS 0433);
 * what changed is that it now declares itself: a manifest the server discovers
 * (no `#[Studio]` class in `aincient_chat`), the report route stamped with the
 * on/off switch, the two audit verbs claimed as the studio's own, and the
 * `policy_check` node processor it used to borrow from `aincient_flows`.
 *
 * @group aincient
 */
#[RunTestsInSeparateProcesses]
final class ChecksStudioModuleTest extends KernelTestBase {

  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'options',
    'filter',
    'node',
    'key',
    'metatag',
    'token',
    'workflows',
    'content_moderation',
    // FlowDrop runtime closure — the policy evaluator's orchestrator.
    'flowdrop_ui_components',
    'flowdrop',
    'flowdrop_node_category',
    'flowdrop_node_type',
    'flowdrop_node_processor',
    'flowdrop_workflow',
    'flowdrop_orchestration',
    'flowdrop_runtime',
    'flowdrop_pipeline',
    'flowdrop_job',
    'flowdrop_session',
    'flowdrop_interrupt',
    'flowdrop_memory',
    'aincient_core',
    'aincient_pages',
    'aincient_chat',
    // The capability tool deriver (the `aincient_capability:*` nodes) is
    // aincient_flows'; the studio's verbs reach it by tier, not by name.
    'aincient_flows',
    'aincient_audit',
  ];

  /**
   * The studio is discovered from the manifest, under the module's provider,
   * with its frozen id and weight, claiming exactly its verbs and flows.
   */
  public function testStudioComesFromTheManifest(): void {
    $studio = $this->container->get('plugin.manager.aincient.studios')->get('checks');
    $this->assertNotNull($studio);
    $this->assertSame('aincient_audit', $studio->getPluginDefinition()['provider']);
    $this->assertSame('Checks', $studio->label());
    $this->assertSame(80, $studio->weight());
    $this->assertSame('ui/index.tsx', $studio->uiEntry());
    $this->assertSame('use aincient studio checks', $studio->permission());
    $this->assertSame(['aincient_audit:run_page_audit', 'aincient_audit:read_meta_tags'], $studio->capabilities());
    $this->assertSame(['aincient_audit_agent', 'aincient_policy_seo', 'aincient_policy_links'], $studio->flows());
  }

  /**
   * Checks SHIPS OFF through the switch, not a flag (DECISIONS 0440): the
   * manifest says `default_enabled: false`, so installing the module seeded
   * `checks` into `disabled_studios`, and the one on-ramp is the switch.
   */
  public function testChecksShipsSwitchedOff(): void {
    $studio = $this->container->get('plugin.manager.aincient.studios')->get('checks');
    $this->assertFalse($studio->defaultEnabled());
    $switch = $this->container->get('aincient_chat.studio_switch');
    // KernelTestBase::enableModules() skips install hooks; run the seeding
    // hook the real installer fires, for this module, as it would at install.
    $this->assertTrue($switch->isEnabled('checks'), 'precondition: nothing has seeded the switch yet');
    $this->container->get('module_handler')->invoke('aincient_chat', 'modules_installed', [['aincient_audit'], FALSE]);
    $this->assertFalse($switch->isEnabled('checks'));
    $this->assertContains('checks', $switch->disabled());
    // The folded flag is gone from the schema, so nothing can quietly keep
    // reading it: a strict-schema save carrying it would be refused.
    $definition = $this->container->get('config.typed')->getDefinition('aincient_chat.settings');
    $this->assertArrayNotHasKey('checks_enabled', $definition['mapping']['features']['mapping']);

    $switch->setEnabled('checks', TRUE);
    $this->assertTrue($switch->isEnabled('checks'));
  }

  /**
   * The report route is the studio's: gated by its derived permission, and the
   * route subscriber stamps the studio-enabled requirement on it — so a
   * switched-off Checks studio 403s it even for a permission holder. The path
   * is the one the findings rail fetches, unchanged by the move.
   */
  public function testReportRouteIsGatedByTheChecksStudio(): void {
    $this->container->get('router.builder')->rebuild();
    $provider = $this->container->get('router.route_provider');
    $this->assertInstanceOf(RouteProviderInterface::class, $provider);
    $route = $provider->getRouteByName('aincient_audit.report');
    $this->assertSame('/atelier/audit/{node}/report', $route->getPath());
    $this->assertSame('use aincient studio checks', $route->getRequirement('_permission'));
    $this->assertSame('checks', $route->getRequirement('_aincient_studio_enabled'));
    $this->assertStringStartsWith('\\' . AuditController::class . '::', $route->getDefault('_controller'));

    // The editor-lock routes Checks SHARES with Content (one writer per draft)
    // are core's: a `+` permission, so the subscriber stamps no
    // studio on them and switching Checks off leaves the Content editor whole.
    foreach (['aincient_pages.page_lock_acquire', 'aincient_pages.page_lock_release', 'aincient_pages.page_lock_status'] as $name) {
      $route = $provider->getRouteByName($name);
      $this->assertStringContainsString('+', (string) $route->getRequirement('_permission'), $name);
      $this->assertNull($route->getRequirement('_aincient_studio_enabled'), $name);
    }
  }

  /**
   * The audit verbs are discovered under this module, and nowhere else.
   */
  public function testAuditCapabilitiesAreTheStudios(): void {
    $definitions = $this->container->get('plugin.manager.aincient.capabilities')->getDefinitions();
    foreach (['run_page_audit', 'read_meta_tags'] as $slug) {
      $this->assertArrayHasKey("aincient_audit:$slug", $definitions, $slug);
      $this->assertSame('aincient_audit', $definitions["aincient_audit:$slug"]['provider'], $slug);
    }
    // And the FlowDrop tool deriver still exposes them — by the module's tier,
    // not by a PROVIDERS entry (that list no longer names aincient_audit).
    $tools = $this->container->get('flowdrop.node_processor_plugin_manager')->getDefinitions();
    $this->assertArrayHasKey('aincient_flows:aincient_capability:run_page_audit', $tools);
    $this->assertArrayHasKey('aincient_flows:aincient_capability:read_meta_tags', $tools);
  }

  /**
   * The policy_check node is this module's processor now (same plugin id, new
   * provider) — the policy workflows are built from it and name it by the
   * renamed node type.
   */
  public function testPolicyCheckProcessorIsTheStudios(): void {
    $definitions = $this->container->get('flowdrop.node_processor_plugin_manager')->getDefinitions();
    $this->assertArrayHasKey('aincient_audit:policy_check', $definitions);
    $this->assertSame(PolicyCheck::class, $definitions['aincient_audit:policy_check']['class']);
    $this->assertArrayNotHasKey('aincient_flows:policy_check', $definitions);
  }

}
