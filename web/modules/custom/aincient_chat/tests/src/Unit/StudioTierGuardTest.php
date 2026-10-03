<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_chat\Unit;

use Drupal\aincient_chat\Studio\StudioTierGuard;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The folder rule, enforced (plans/studio-modules.md "Guards", DECISIONS 0430).
 *
 * Each rule of {@see StudioTierGuard} is proven both ways on a synthetic tree —
 * a clean tree passes, a deliberately broken one reports the violation — and
 * then run against the real `web/modules`. Without the synthetic half a guard
 * that silently matched nothing would stay green forever; the real-tree half
 * additionally asserts it walked at least one manifest (the
 * `aincient_studio_test` fixture), so rule (c) is not vacuous while no studio
 * module exists yet. No Drupal bootstrap: it reads files off disk.
 */
#[Group('aincient')]
final class StudioTierGuardTest extends UnitTestCase {

  /** @var list<string> */
  private array $trees = [];

  protected function tearDown(): void {
    foreach ($this->trees as $tree) {
      if (is_dir($tree)) {
        $this->rmTree($tree);
      }
    }
    parent::tearDown();
  }

  private function realRoot(): string {
    // Unit → src → tests → aincient_chat → custom → modules.
    return dirname(__DIR__, 5);
  }

  /**
   * Lay out a temp `web/modules` tree from a path => contents map.
   *
   * @param array<string, string> $files
   */
  private function tree(array $files): string {
    $tmp = sys_get_temp_dir() . '/studio_tier_guard_' . bin2hex(random_bytes(4));
    $this->trees[] = $tmp;
    foreach ($files as $path => $contents) {
      $full = $tmp . '/' . $path;
      if (!is_dir(dirname($full))) {
        mkdir(dirname($full), 0777, TRUE);
      }
      file_put_contents($full, $contents);
    }
    return $tmp;
  }

  private function info(string $name, array $deps = []): string {
    $yaml = "name: $name\ntype: module\ncore_version_requirement: ^11\n";
    if ($deps) {
      $yaml .= "dependencies:\n" . implode('', array_map(static fn ($d) => "  - '$d'\n", $deps));
    }
    return $yaml;
  }

  private function rmTree(string $dir): void {
    foreach (scandir($dir) ?: [] as $entry) {
      if ($entry === '.' || $entry === '..') {
        continue;
      }
      $path = $dir . '/' . $entry;
      is_dir($path) ? $this->rmTree($path) : unlink($path);
    }
    rmdir($dir);
  }

  // Rule (a).

  public function testPlacementPassesOnACleanTree(): void {
    $root = $this->tree([
      'studio/s1/s1.info.yml' => $this->info('s1'),
      'studio/s1/s1.studios.yml' => "s1:\n  label: S1\n",
      'custom/core1/core1.info.yml' => $this->info('core1'),
      // A test fixture is not a tier module and may carry a manifest.
      'custom/core1/tests/modules/fx/fx.info.yml' => $this->info('fx'),
      'custom/core1/tests/modules/fx/fx.studios.yml' => "fx:\n  label: Fx\n",
    ]);
    $this->assertSame([], StudioTierGuard::manifestPlacementViolations($root));
  }

  public function testPlacementFlagsMissingAndMisplacedManifests(): void {
    $root = $this->tree([
      'studio/bare/bare.info.yml' => $this->info('bare'),
      'studio/two/two.info.yml' => $this->info('two'),
      'studio/two/two.studios.yml' => "two:\n  label: T\n",
      'studio/two/extra.studios.yml' => "extra:\n  label: E\n",
      'custom/core1/core1.info.yml' => $this->info('core1'),
      'custom/core1/core1.studios.yml' => "core1:\n  label: C\n",
    ]);
    $violations = StudioTierGuard::manifestPlacementViolations($root);
    $this->assertCount(3, $violations);
    $this->assertStringContainsString('studio/bare', $violations[0]);
    $this->assertStringContainsString('studio/two', $violations[1]);
    $this->assertStringContainsString('custom/core1', $violations[2]);
  }

  // Rule (b).

  public function testDependenciesPassOnACleanTree(): void {
    $root = $this->tree([
      'studio/s1/s1.info.yml' => $this->info('s1', ['aincient_chat', 'drupal:node']),
      'custom/core1/core1.info.yml' => $this->info('core1', ['drupal:file']),
    ]);
    $this->assertSame([], StudioTierGuard::dependencyViolations($root));
  }

  public function testDependenciesFlagStudioToStudioAndCoreToStudio(): void {
    $root = $this->tree([
      'studio/s1/s1.info.yml' => $this->info('s1', ['s2 (>=1.0)']),
      'studio/s2/s2.info.yml' => $this->info('s2'),
      'custom/core1/core1.info.yml' => $this->info('core1', ['atelier:s2']),
    ]);
    $violations = StudioTierGuard::dependencyViolations($root);
    $this->assertCount(2, $violations);
    $this->assertStringContainsString('studio/s1 depends on studio module s2', implode("\n", $violations));
    $this->assertStringContainsString('custom/core1 depends on studio module s2', implode("\n", $violations));
  }

  public function testDependencyNamesNormalise(): void {
    $root = $this->tree(['custom/m/m.info.yml' => $this->info('m', ['drupal:node', 'foo (>=1.2)', 'plain'])]);
    $this->assertSame(['node', 'foo', 'plain'], StudioTierGuard::dependencies($root . '/custom/m/m.info.yml'));
  }

  // Rule (c).

  public function testManifestReferencesPassWhenPresent(): void {
    $root = $this->tree([
      'studio/s1/s1.info.yml' => $this->info('s1'),
      'studio/s1/s1.studios.yml' => "s1:\n  label: S1\n  flows: [f1]\n  capabilities: [ping]\n",
      'studio/s1/config/install/flowdrop_workflow.flowdrop_workflow.f1.yml' => "id: f1\n",
      'studio/s1/src/Plugin/AiCapability/Ping.php' => "<?php\n#[Capability(\n  id: 's1:ping',\n)]\nfinal class Ping {}\n",
    ]);
    $this->assertSame([], StudioTierGuard::manifestViolations($root));
  }

  public function testManifestFlagsMissingFlowMissingCapabilityAndDuplicateClaims(): void {
    $root = $this->tree([
      'studio/s1/s1.info.yml' => $this->info('s1'),
      // One studio claims `ping` twice: a duplicate; `nope` has no class; the
      // flow has no config.
      'studio/s1/s1.studios.yml' => "s1:\n  label: S1\n  flows: [ghost]\n  capabilities: [ping, nope, ping]\n",
      'studio/s1/src/Plugin/AiCapability/Ping.php' => "<?php\n#[Capability(id: 's1:ping')]\nfinal class Ping {}\n",
    ]);
    $text = implode("\n", StudioTierGuard::manifestViolations($root));
    $this->assertStringContainsString('flow "ghost"', $text);
    $this->assertStringContainsString('capability "nope"', $text);
    $this->assertStringContainsString('s1:ping is claimed by both', $text);
    // The id check is exact: `s1:ping` does not satisfy `s1:pin`.
    $root2 = $this->tree([
      'studio/s1/s1.info.yml' => $this->info('s1'),
      'studio/s1/s1.studios.yml' => "s1:\n  label: S1\n  capabilities: [pin]\n",
      'studio/s1/src/Plugin/AiCapability/Ping.php' => "<?php\n#[Capability(id: 's1:ping')]\nfinal class Ping {}\n",
    ]);
    $this->assertNotSame([], StudioTierGuard::manifestViolations($root2));
  }

  // The real tree.

  public function testRealTreeHonoursTheFolderRule(): void {
    $root = $this->realRoot();
    $this->assertDirectoryExists($root . '/custom');
    $this->assertSame([], StudioTierGuard::manifestPlacementViolations($root));
    $this->assertSame([], StudioTierGuard::dependencyViolations($root));
  }

  public function testRealTreeManifestsReferenceRealFlowsAndCapabilities(): void {
    $root = $this->realRoot();
    // Non-vacuous: the fixture studio module guarantees at least one manifest.
    $this->assertGreaterThanOrEqual(1, count(StudioTierGuard::manifests($root)));
    $this->assertSame([], StudioTierGuard::manifestViolations($root));
  }

}
