<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Unit;

use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\aincient_pages\Catalog\CapabilityFence;
use Drupal\aincient_pages\Catalog\PackValidator;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Theme\ComponentPluginManager;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The two pack-level rules that are not about components:
 *
 * - THE CAPABILITY FENCE (plans/console-extension-point.md Phase 3b): a pack
 *   may not ship agent verbs. Rejected on the strength of the DIRECTORY, with
 *   or without a manifest — a pack cannot get past the fence by omitting one.
 * - THE STUDIO PAYLOAD: a declared `studios` payload must be real, an
 *   undeclared one is called out, and a pack studio id must be prefixed with
 *   the module name, because studio ids share one namespace with ours and
 *   become permission names and URL values.
 *
 * @group aincient
 * @covers \Drupal\aincient_pages\Catalog\CapabilityFence
 * @covers \Drupal\aincient_pages\Catalog\PackValidator
 */
#[RunTestsInSeparateProcesses]
final class PackFenceTest extends UnitTestCase {

  /**
   * A throwaway module tree, returned by the mocked extension list.
   */
  private string $packPath;

  protected function setUp(): void {
    parent::setUp();
    $this->packPath = sys_get_temp_dir() . '/atelier-pack-fence-' . uniqid();
    mkdir($this->packPath, 0777, TRUE);
  }

  protected function tearDown(): void {
    exec('rm -rf ' . escapeshellarg($this->packPath));
    parent::tearDown();
  }

  /**
   * A validator over the throwaway pack: no components anywhere, and a studio
   * manager that reports exactly the given studio ids as this pack's.
   *
   * @param list<string> $studioIds
   *   Studio plugin ids the pack provides (NULL manager ⇒ no chat layer).
   */
  private function validator(?array $studioIds): PackValidator {
    $components = $this->createMock(ComponentPluginManager::class);
    $components->method('getDefinitions')->willReturn([]);

    $moduleList = $this->createMock(ModuleExtensionList::class);
    $moduleList->method('getPath')->willReturn($this->packPath);

    $studios = NULL;
    if ($studioIds !== NULL) {
      $definitions = ['builtin' => ['id' => 'builtin', 'provider' => 'aincient_chat']];
      foreach ($studioIds as $id) {
        $definitions[$id] = ['id' => $id, 'provider' => 'acme_pack'];
      }
      $studios = $this->createMock(PluginManagerInterface::class);
      $studios->method('getDefinitions')->willReturn($definitions);
    }

    return new PackValidator($components, $moduleList, $studios);
  }

  /**
   * Write the pack's manifest (omit to leave the pack manifest-less).
   */
  private function manifest(string $yaml): void {
    file_put_contents($this->packPath . '/atelier.pack.yml', $yaml);
  }

  /**
   * Give the pack a capability plugin directory.
   */
  private function shipCapability(): void {
    mkdir($this->packPath . '/' . CapabilityFence::CAPABILITY_DIR, 0777, TRUE);
    file_put_contents($this->packPath . '/' . CapabilityFence::CAPABILITY_DIR . '/SendInvoice.php', '<?php');
  }

  /**
   * The fence itself: the directory is the whole test.
   */
  public function testFenceFiresOnTheCapabilityDirectory(): void {
    $this->assertSame([], CapabilityFence::check($this->packPath, 'acme_pack'), 'A pack without capabilities is silent.');
    $this->shipCapability();
    $errors = CapabilityFence::check($this->packPath, 'acme_pack');
    $this->assertCount(1, $errors);
    $this->assertStringContainsString(CapabilityFence::CAPABILITY_DIR, $errors[0]);
    $this->assertStringContainsString('0368', $errors[0], 'The message names the decision, so the reader can find the why.');

    // Our own capability-owning modules are exempt — otherwise running the
    // validator over aincient_pages would report the product as a violation.
    $this->assertSame([], CapabilityFence::check($this->packPath, 'aincient_pages'));
  }

  /**
   * A pack shipping a capability is REJECTED, and a missing manifest is not a
   * way around it — the fence runs on any named module.
   */
  public function testCapabilityPackIsRejectedWithoutAManifest(): void {
    $this->shipCapability();
    $report = $this->validator([])->validate('acme_pack');

    $this->assertSame(1, $report['rejected'], 'A capability-shipping pack fails CI.');
    $this->assertFalse($report['pack']['found'], 'No manifest — and it still does not pass.');
    $this->assertStringContainsString('may not add agent capabilities', implode(' ', $report['pack']['errors']));
  }

  /**
   * The same pack WITH a manifest: the fence error rides beside the manifest's.
   */
  public function testCapabilityPackIsRejectedWithAManifest(): void {
    $this->manifest("api: 1\nprovides: [components]\n");
    $this->shipCapability();
    $report = $this->validator([])->validate('acme_pack');

    $this->assertSame(1, $report['rejected']);
    $this->assertTrue($report['pack']['found']);
    $this->assertStringContainsString('may not add agent capabilities', implode(' ', $report['pack']['errors']));
  }

  /**
   * A correctly declared, correctly named studio payload passes.
   */
  public function testWellFormedStudioPayloadPasses(): void {
    $this->manifest("api: 1\nprovides: [studios]\n");
    $report = $this->validator(['acme_pack_reports'])->validate('acme_pack');

    $this->assertSame(0, $report['rejected']);
    $this->assertSame([], $report['pack']['errors']);
    $this->assertSame([], $report['pack']['warnings']);
  }

  /**
   * A declared payload that ships nothing is a manifest that lies.
   */
  public function testDeclaredButAbsentStudioPayloadIsRejected(): void {
    $this->manifest("api: 1\nprovides: [studios]\n");
    $report = $this->validator([])->validate('acme_pack');

    $this->assertSame(1, $report['rejected']);
    $this->assertStringContainsString('ships no studio plugin', implode(' ', $report['pack']['errors']));
  }

  /**
   * Shipping a studio without declaring it is a warning, not a rejection — the
   * plugin is real and gated; what is missing is the manifest saying so.
   */
  public function testUndeclaredStudioPayloadWarns(): void {
    $this->manifest("api: 1\nprovides: [components]\n");
    $report = $this->validator(['acme_pack_reports'])->validate('acme_pack');

    $this->assertSame(0, $report['rejected']);
    $this->assertStringContainsString('does not declare "studios"', implode(' ', $report['pack']['warnings']));
  }

  /**
   * An unprefixed studio id is rejected: ids are a flat public namespace, and
   * `reports` would collide with ours (and with the next pack's) forever.
   */
  public function testUnprefixedStudioIdIsRejected(): void {
    $this->manifest("api: 1\nprovides: [studios]\n");
    $report = $this->validator(['reports'])->validate('acme_pack');

    $this->assertSame(1, $report['rejected']);
    $this->assertStringContainsString('out of scope', implode(' ', $report['pack']['errors']));

    // The module name itself is a legal id — a single-studio pack need not
    // repeat itself as `acme_pack_acme_pack`.
    $ok = $this->validator(['acme_pack'])->validate('acme_pack');
    $this->assertSame(0, $ok['rejected']);
  }

  /**
   * No chat layer on the install ⇒ no studio grade, and no invented failure.
   */
  public function testStudioGradeIsSkippedWithoutTheChatLayer(): void {
    $this->manifest("api: 1\nprovides: [studios]\n");
    $report = $this->validator(NULL)->validate('acme_pack');

    $this->assertSame(0, $report['rejected']);
    $this->assertSame([], $report['pack']['errors']);
  }

}
