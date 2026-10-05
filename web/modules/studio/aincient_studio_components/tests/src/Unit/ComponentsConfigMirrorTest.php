<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_studio_components\Unit;

use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Yaml\Yaml;

/**
 * The Components studio's FlowDrop config ships twice and the copies must be ONE.
 *
 * Same contract as the Checks studio's ChecksConfigMirrorTest: the module owns
 * its agent's config as `config/install`, while `config/sync` is what a real
 * site runs from (install with `--existing-config`, upgrade with `cim`). Edit
 * in the editor, `cex`, then copy the export over `config/install` minus
 * `uuid` and `_core` (DECISIONS 0455, P2).
 *
 * @group aincient
 */
final class ComponentsConfigMirrorTest extends UnitTestCase {

  /**
   * @return iterable<string, array{string}>
   */
  public static function shippedConfig(): iterable {
    yield 'components agent' => ['flowdrop_workflow.flowdrop_workflow.aincient_components_agent.yml'];
    yield 'propose_component_constraint node type' => ['flowdrop_node_type.flowdrop_node_type.aincient_flows_aincient_capability_propose_component_constraint.yml'];
    yield 'components_state node type' => ['flowdrop_node_type.flowdrop_node_type.aincient_studio_components_components_state.yml'];
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
