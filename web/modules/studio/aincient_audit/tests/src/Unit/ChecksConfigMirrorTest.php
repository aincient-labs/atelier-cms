<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_audit\Unit;

use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Yaml\Yaml;

/**
 * The Checks studio's FlowDrop config ships twice and the copies must be ONE.
 *
 * A studio module owns its flows as `config/install` (the tier guard's rule (c)
 * insists), while `config/sync` stays the site's full active export — the
 * appliance installs with `--existing-config` and upgrades with `cim`, both of
 * which read sync and skip a module's install config. So the install copy is
 * never what a real site runs from; it exists so the module is complete on its
 * own, and the only way it can be wrong is by drifting from sync. This test
 * makes that drift a build failure: edit the flow in the editor, `cex`, then
 * copy the export over `config/install` (minus `uuid` and `_core`, which are
 * the site's, not the module's). Same contract as the Library studio's
 * ImageAgentConfigMirrorTest, over four files: the audit agent, the two policy
 * workflows and the `policy_check` node type they are built from.
 *
 * @group aincient
 */
final class ChecksConfigMirrorTest extends UnitTestCase {

  /**
   * @return iterable<string, array{string}>
   */
  public static function shippedConfig(): iterable {
    yield 'audit agent' => ['flowdrop_workflow.flowdrop_workflow.aincient_audit_agent.yml'];
    yield 'seo policy' => ['flowdrop_workflow.flowdrop_workflow.aincient_policy_seo.yml'];
    yield 'links policy' => ['flowdrop_workflow.flowdrop_workflow.aincient_policy_links.yml'];
    yield 'policy_check node type' => ['flowdrop_node_type.flowdrop_node_type.aincient_audit_policy_check.yml'];
  }

  #[DataProvider('shippedConfig')]
  public function testInstallCopyMatchesTheSyncExport(string $file): void {
    $module = dirname(__DIR__, 3);
    $install = Yaml::parseFile($module . '/config/install/' . $file);
    $sync = Yaml::parseFile(dirname($module, 4) . '/config/sync/' . $file);
    $this->assertIsArray($install);
    $this->assertIsArray($sync);

    $this->assertArrayNotHasKey('uuid', $install, 'The install copy carries no site uuid.');
    $this->assertArrayNotHasKey('_core', $install, 'The install copy carries no site hash.');
    unset($sync['uuid'], $sync['_core']);

    $this->assertSame($sync, $install, "config/install and config/sync disagree on $file — copy the fresh export over the module (drop uuid + _core).");
  }

}
