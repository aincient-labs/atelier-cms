<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_studio_content\Unit;

use Drupal\Tests\UnitTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The pages agent ships twice and the two copies must be ONE flow.
 *
 * A studio module owns its FlowDrop agent as `config/install` (the tier guard's
 * rule (c) insists), while `config/sync` stays the site's full active export —
 * the appliance installs with `--existing-config` and upgrades with `cim`, both
 * of which read sync and skip a module's install config. So the install copy is
 * never what a real site runs from; it exists so the module is complete on its
 * own, and the only way it can be wrong is by drifting from sync. This test
 * makes that drift a build failure: edit the flow in the editor, `cex`, then
 * copy the export over `config/install` (minus `uuid` and `_core`, which are
 * the site's, not the module's). Same contract as the Library, Checks and Site
 * studios' mirror tests.
 *
 * @group aincient
 */
final class ContentConfigMirrorTest extends UnitTestCase {

  private const FLOW = 'flowdrop_workflow.flowdrop_workflow.aincient_pages_agent.yml';

  public function testInstallCopyMatchesTheSyncExport(): void {
    $module = dirname(__DIR__, 3);
    $install = Yaml::parseFile($module . '/config/install/' . self::FLOW);
    $sync = Yaml::parseFile(dirname($module, 4) . '/config/sync/' . self::FLOW);
    $this->assertIsArray($install);
    $this->assertIsArray($sync);

    $this->assertArrayNotHasKey('uuid', $install, 'The install copy carries no site uuid.');
    $this->assertArrayNotHasKey('_core', $install, 'The install copy carries no site hash.');
    unset($sync['uuid'], $sync['_core']);

    $this->assertSame($sync, $install, 'config/install and config/sync disagree on aincient_pages_agent — copy the fresh export over the module (drop uuid + _core).');
  }

}
