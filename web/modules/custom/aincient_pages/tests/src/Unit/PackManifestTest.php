<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Unit;

use Drupal\aincient_pages\Catalog\PackManifest;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the atelier.pack.yml contract (plans/byo-components.md §6.1/§7.1) —
 * like the gate fixtures, every rule here is a promise to a pack author.
 *
 * @group aincient
 * @coversDefaultClass \Drupal\aincient_pages\Catalog\PackManifest
 */
#[RunTestsInSeparateProcesses]
final class PackManifestTest extends UnitTestCase {

  /** A minimal valid manifest, overridable per case. */
  private static function manifest(array $overrides = []): array {
    return $overrides + ['api' => 1, 'provides' => ['components']];
  }

  private static function errors(array $manifest, string $module = 'acme_pack'): array {
    return PackManifest::validate($manifest, $module)[0];
  }

  public function testMinimalManifestPasses(): void {
    [$errors, $warnings] = PackManifest::validate(self::manifest(), 'acme_pack');
    $this->assertSame([], $errors);
    $this->assertSame([], $warnings);
  }

  public function testMissingApiIsRejected(): void {
    $m = self::manifest();
    unset($m['api']);
    $this->assertNotSame([], self::errors($m));
    $this->assertNotSame([], self::errors(self::manifest(['api' => '1'])), 'A string api is rejected — the major is an integer.');
  }

  public function testUnknownApiMajorIsRejected(): void {
    $this->assertNotSame([], self::errors(self::manifest(['api' => 2])));
  }

  /**
   * The owns: scoping rule is a SECURITY FLOOR — a pack may fence only its own
   * config namespace or page kinds, never ours (a pack declaring `field.*`
   * would fence OUR config off from config:import).
   */
  public function testOutOfScopeOwnsPatternIsRejected(): void {
    $this->assertNotSame([], self::errors(self::manifest(['owns' => ['field.*']])));
    $this->assertNotSame([], self::errors(self::manifest(['owns' => ['aincient_core.model_roles']])));
    $this->assertNotSame([], self::errors(self::manifest(['owns' => ['acme_packextra.settings']])), 'A prefix that only STARTS like the module name is out of scope (acme_pack. ≠ acme_packextra.).');
    $this->assertSame([], self::errors(self::manifest(['owns' => ['acme_pack.settings', 'aincient_pages.page_kind.acme_*']])));
  }

  public function testUnknownPayloadWarnsButPasses(): void {
    [$errors, $warnings] = PackManifest::validate(self::manifest(['provides' => ['components', 'blocks']]), 'acme_pack');
    $this->assertSame([], $errors);
    $this->assertStringContainsString('unknown payload "blocks"', implode(' ', $warnings));
  }

  public function testReadOnTheFixturePack(): void {
    $path = dirname(__DIR__, 2) . '/modules/atelier_test_pack';
    $result = PackManifest::read($path, 'atelier_test_pack');
    $this->assertTrue($result['found']);
    $this->assertSame([], $result['errors']);
    $this->assertSame(1, $result['manifest']['api']);
  }

  public function testMissingFileIsFoundFalseNotAnError(): void {
    $result = PackManifest::read(sys_get_temp_dir() . '/no-such-pack', 'nope');
    $this->assertFalse($result['found']);
    $this->assertSame([], $result['errors']);
  }

}
