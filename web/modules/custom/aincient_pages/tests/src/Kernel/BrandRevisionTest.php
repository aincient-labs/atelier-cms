<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Kernel;

use Drupal\aincient_pages\BrandRepository;
use Drupal\aincient_pages\BrandRevisioner;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests brand revision history: snapshot-on-save, dedupe, prune, and restore.
 *
 * The seam is a ConfigEvents::SAVE subscriber, so these tests drive the public
 * BrandRepository (the AI path) and assert the history that results — proving
 * the subscriber catches writes without the writer cooperating.
 *
 * @group aincient
 */
#[RunTestsInSeparateProcesses]
final class BrandRevisionTest extends KernelTestBase {

  protected static $modules = ['system', 'user', 'workflows', 'content_moderation', 'aincient_pages'];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('aincient_brand_revision');
    // Writes the install brand config → the subscriber records the baseline.
    $this->installConfig(['workflows', 'content_moderation', 'aincient_pages']);
  }

  private function brand(): BrandRepository {
    return $this->container->get('aincient_pages.brand');
  }

  private function revisioner(): BrandRevisioner {
    return $this->container->get('aincient_pages.brand_revisioner');
  }

  public function testInstallRecordsABaselineRevision(): void {
    $revisions = $this->revisioner()->recent();
    $this->assertNotEmpty($revisions, 'Installing the brand config records a baseline.');
    $this->assertSame('initial brand', (string) $revisions[0]->get('summary')->value);
  }

  public function testEverySaveIsSnapshotted(): void {
    $before = count($this->revisioner()->recent());
    // brand_primary / neutral_border are Tier 1, where a raw colour is legal.
    $this->brand()->update(['brand_primary' => '#ff0000']);
    $this->brand()->update(['neutral_border' => '#00ff00']);
    $this->assertCount($before + 2, $this->revisioner()->recent());
  }

  public function testNoOpSaveIsNotSnapshotted(): void {
    $this->brand()->update(['brand_primary' => '#ff0000']);
    $count = count($this->revisioner()->recent());
    // Re-applying the same value changes nothing → no new revision.
    $this->brand()->update(['brand_primary' => '#ff0000']);
    $this->assertCount($count, $this->revisioner()->recent());
  }

  public function testSummaryRecordsWhatChanged(): void {
    $this->brand()->update(['brand_primary' => '#abcdef']);
    $summary = (string) $this->revisioner()->recent()[0]->get('summary')->value;
    $this->assertStringContainsString('brand_primary', $summary);
    $this->assertStringContainsString('#abcdef', $summary);
  }

  public function testStatusChangeIsSnapshottedWithSummary(): void {
    $before = count($this->revisioner()->recent());
    // Status persists out-of-band from tokens, but still writes the brand config,
    // so the save subscriber records it as a real, reversible revision.
    $this->brand()->setStatus(BrandRepository::STAGE_POLISH, TRUE);
    $recent = $this->revisioner()->recent();
    $this->assertCount($before + 1, $recent);
    $summary = (string) $recent[0]->get('summary')->value;
    $this->assertStringContainsString('status → polish', $summary);
    $this->assertStringContainsString('locked', $summary);
  }

  public function testRestoreRevertsTheBrand(): void {
    $this->brand()->update(['brand_primary' => '#111111']);
    // The newest snapshot now holds the #111111 state.
    $target = $this->revisioner()->recent()[0];

    $this->brand()->update(['brand_primary' => '#222222']);
    $this->assertSame('#222222', $this->brand()->tokens()['brand_primary']);

    $countBefore = count($this->revisioner()->recent());
    $this->assertTrue($this->revisioner()->restore((int) $target->id()));
    $this->assertSame('#111111', $this->brand()->tokens()['brand_primary']);
    // The restore is itself recorded, so it stays reversible.
    $this->assertCount($countBefore + 1, $this->revisioner()->recent());
  }

  public function testHistoryIsPrunedToTheCap(): void {
    for ($i = 0; $i < BrandRevisioner::MAX_REVISIONS + 5; $i++) {
      // A distinct valid hex each time so none are deduped (neutral_border is
      // Tier 1, where a raw colour is allowed).
      $this->brand()->update(['neutral_border' => '#' . str_pad((string) $i, 6, '0', STR_PAD_LEFT)]);
    }
    $this->assertLessThanOrEqual(
      BrandRevisioner::MAX_REVISIONS,
      count($this->revisioner()->recent(1000)),
      'History is capped at MAX_REVISIONS.',
    );
  }

}
