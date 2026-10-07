<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * W10d: pack-validate lints the CSS a pack author WROTE — `build/input.css` and
 * the local files it imports — not the compiled stylesheet, whenever the pack
 * has a Tailwind build entry. A pack with no build keeps linting its declared
 * stylesheet.
 *
 * @group aincient
 */
#[RunTestsInSeparateProcesses]
final class PackCssLintTest extends KernelTestBase {

  protected static $modules = [
    'system',
    'user',
    'link',
    'menu_link_content',
    'workflows', 'content_moderation', 'aincient_core', 'aincient_pages',
    'atelier_test_build_pack',
    'atelier_test_dirty_pack',
    'atelier_test_hand_pack',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['workflows', 'content_moderation', 'aincient_pages']);
  }

  /**
   * The row for the module's single component.
   */
  private function row(string $module): array {
    $report = $this->container->get('aincient_pages.pack_validator')->validate($module);
    $this->assertCount(1, $report['rows']);
    return $report['rows'][0];
  }

  /**
   * An untouched scaffold validates clean: the compiled assets file carries
   * preflight-like literals and the synced preset carries colours, but neither
   * is the author's code.
   */
  public function testPristineScaffoldHasNoCssWarnings(): void {
    $row = $this->row('atelier_test_build_pack');
    $this->assertSame('OK', $row['status'], $row['messages']);
    $this->assertSame('', $row['messages']);
  }

  /**
   * A literal colour in the authored build/pack.css warns, naming that file
   * and line — and nothing from the compiled output or the preset leaks in.
   */
  public function testAuthoredLiteralWarnsAgainstAuthoredFile(): void {
    $row = $this->row('atelier_test_dirty_pack');
    $this->assertSame('WARN', $row['status']);
    $this->assertStringContainsString('build/pack.css line 2: hardcoded colour #fff', $row['messages']);
    $this->assertSame(1, substr_count($row['messages'], 'hardcoded colour'), 'Only the authored literal is reported.');
    $this->assertStringNotContainsString('assets/', $row['messages']);
    $this->assertStringNotContainsString('generated', $row['messages']);
  }

  /**
   * Without a build entry the declared stylesheet IS the authored source.
   */
  public function testPackWithoutBuildLintsItsStylesheet(): void {
    $row = $this->row('atelier_test_hand_pack');
    $this->assertSame('WARN', $row['status']);
    $this->assertStringContainsString('assets/atelier-test-hand-pack.css line 2: hardcoded colour #fff', $row['messages']);
  }

}
