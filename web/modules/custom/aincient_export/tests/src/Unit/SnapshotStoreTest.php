<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_export\Unit;

use Drupal\aincient_export\Exporter;
use Drupal\aincient_export\Snapshot;
use Drupal\aincient_export\SnapshotStore;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\aincient_export\SnapshotStore
 * @group aincient_export
 */
final class SnapshotStoreTest extends UnitTestCase {

  private string $dir;

  private SnapshotStore $store;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->dir = sys_get_temp_dir() . '/aincient-frozen-' . bin2hex(random_bytes(4));
    mkdir($this->dir, 0775, TRUE);
    $exporter = $this->createMock(Exporter::class);
    $time = $this->createMock(TimeInterface::class);
    $this->store = new SnapshotStore($exporter, $time, $this->dir);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    exec('rm -rf ' . escapeshellarg($this->dir));
    parent::tearDown();
  }

  private function makeSnapshot(string $id, array $marker = []): string {
    $dir = $this->dir . '/' . $id;
    mkdir($dir . '/about', 0775, TRUE);
    file_put_contents($dir . '/index.html', 'home');
    file_put_contents($dir . '/about/index.html', 'about');
    file_put_contents($dir . '/' . Exporter::MARKER, json_encode(['generator' => 'aincient_export', 'pages' => 2, 'assets' => 0] + $marker));
    return $dir;
  }

  /**
   * @covers ::list
   * @covers ::current
   * @covers ::get
   */
  public function testListReadsMarkersNewestFirstAndIgnoresStrays(): void {
    $this->makeSnapshot('20260901-1000');
    $this->makeSnapshot('20260910-1102-autumn', ['label' => 'Autumn', 'keep' => TRUE]);
    // A folder without a marker is not a snapshot.
    mkdir($this->dir . '/20260905-0900');
    // Neither is a stray file.
    touch($this->dir . '/notes.txt');

    $ids = array_map(static fn (Snapshot $s) => $s->id, $this->store->list());
    $this->assertSame(['20260910-1102-autumn', '20260901-1000'], $ids);
    $this->assertSame(SnapshotStore::LIVE, $this->store->current());
    $this->assertFalse($this->store->isFrozen());

    $autumn = $this->store->get('20260910-1102-autumn');
    $this->assertSame('Autumn', $autumn->label);
    $this->assertTrue($autumn->keep);
    $this->assertSame(2, $autumn->pages);
    $this->assertNull($this->store->get('20260905-0900'));
    $this->assertNull($this->store->get('../etc'));
  }

  /**
   * @covers ::use
   */
  public function testUseIsAtomicRelativeAndValidated(): void {
    $this->makeSnapshot('20260901-1000');
    $this->makeSnapshot('20260910-1102');

    $this->store->use('20260901-1000');
    $this->assertSame('20260901-1000', $this->store->current());
    $this->assertSame('20260901-1000', readlink($this->dir . '/current'), 'relative target');

    $this->store->use('20260910-1102');
    $this->assertSame('20260910-1102', $this->store->current());
    $this->assertCount(0, glob($this->dir . '/.current.*') ?: [], 'no temp links left behind');

    $this->store->use(SnapshotStore::LIVE);
    $this->assertSame(SnapshotStore::LIVE, $this->store->current());
    $this->assertFalse(is_link($this->dir . '/current'), 'Live is no symlink');

    $this->expectException(\InvalidArgumentException::class);
    $this->store->use('20260905-0900');
  }

  /**
   * @covers ::use
   */
  public function testUseRefusesAFolderWithoutMarker(): void {
    mkdir($this->dir . '/20260905-0900');
    file_put_contents($this->dir . '/20260905-0900/index.html', 'x');
    $this->expectException(\InvalidArgumentException::class);
    $this->store->use('20260905-0900');
  }

  /**
   * @covers ::current
   */
  public function testDanglingCurrentReportsLive(): void {
    $this->makeSnapshot('20260901-1000');
    $this->store->use('20260901-1000');
    exec('rm -rf ' . escapeshellarg($this->dir . '/20260901-1000'));
    $this->assertSame(SnapshotStore::LIVE, $this->store->current());
  }

  /**
   * @covers ::prune
   * @covers ::delete
   * @covers ::setKeep
   */
  public function testPruneKeepsNewestKeptAndServed(): void {
    foreach (['20260901-1000', '20260902-1000', '20260903-1000', '20260904-1000', '20260905-1000'] as $id) {
      $this->makeSnapshot($id);
    }
    $this->store->setKeep('20260901-1000', TRUE);
    $this->store->use('20260902-1000');

    $deleted = $this->store->prune(1);
    // Newest unkept, unserved = 20260905 (kept by count 1); 20260904 + 20260903 go;
    // 20260902 is served, 20260901 is kept.
    $this->assertSame(['20260904-1000', '20260903-1000'], $deleted);
    $ids = array_map(static fn (Snapshot $s) => $s->id, $this->store->list());
    $this->assertSame(['20260905-1000', '20260902-1000', '20260901-1000'], $ids);

    $this->expectException(\LogicException::class);
    $this->store->delete('20260902-1000');
  }

  /**
   * @covers ::validId
   */
  public function testValidId(): void {
    $this->assertTrue(SnapshotStore::validId('20260910-1102'));
    $this->assertTrue(SnapshotStore::validId('20260910-1102-spring-launch-2'));
    $this->assertFalse(SnapshotStore::validId('current'));
    $this->assertFalse(SnapshotStore::validId('../x'));
    $this->assertFalse(SnapshotStore::validId('20260910-1102-Upper'));
  }

}
