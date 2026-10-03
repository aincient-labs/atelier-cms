<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_brand\Unit;

use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Yaml\Yaml;

/**
 * The brand agent ships twice and the two copies must be ONE set of flows.
 *
 * A studio module owns its FlowDrop agents as `config/install` (the tier guard's
 * rule (c) insists), while `config/sync` stays the site's full active export —
 * the appliance installs with `--existing-config` and upgrades with `cim`, both
 * of which read sync and skip a module's install config. So the install copies
 * are never what a real site runs from; they exist so the module is complete on
 * its own, and the only way they can be wrong is by drifting from sync. This
 * test makes that drift a build failure: edit a flow in the editor, `cex`, then
 * copy the export over `config/install` (minus `uuid` and `_core`). Seven files:
 * the `brand_studio` agent, its three specialist flows, and the three
 * `flowdrop_workflow_executor` node types that delegate to them.
 *
 * @group aincient
 */
final class BrandConfigMirrorTest extends UnitTestCase {

  public static function files(): array {
    $flows = ['brand_studio', 'aincient_brand_specialist_colour', 'aincient_brand_specialist_shape', 'aincient_brand_specialist_typography'];
    $out = [];
    foreach ($flows as $flow) {
      $out[$flow] = ["flowdrop_workflow.flowdrop_workflow.$flow.yml"];
    }
    foreach (['colour', 'shape', 'typography'] as $specialist) {
      $out["executor:$specialist"] = ["flowdrop_node_type.flowdrop_node_type.flowdrop_workflow_executor_flowdrop_workflow_aincient_brand_specialist_$specialist.yml"];
    }
    return $out;
  }

  #[DataProvider('files')]
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
