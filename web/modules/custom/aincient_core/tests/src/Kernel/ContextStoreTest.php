<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_core\Kernel;

use Drupal\aincient_core\Context\ContextStore;
use Drupal\aincient_core\Entity\ContextLedgerEntry;
use Drupal\file\Entity\File;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The storage foundation of the private, forensic context store.
 *
 * Covers {@see \Drupal\aincient_core\Context\ContextStore::storeImage}: an
 * uploaded image becomes a permanent ledger row plus ONE normalized WebP
 * derivative under private://atelier-context, content-addressed by hash (never
 * by the client filename), original not kept by default; and
 * {@see ContextStore::entryForUri} round-trips the derivative uri.
 *
 * @group aincient_core
 * @covers \Drupal\aincient_core\Context\ContextStore
 */
#[RunTestsInSeparateProcesses]
final class ContextStoreTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'file',
    'image',
    'filter',
    'text',
    'aincient_core',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUpFilesystem(): void {
    parent::setUpFilesystem();
    // Give the kernel a writable private:// scheme (bare kernel tests don't).
    $private = $this->siteDirectory . '/private';
    if (!is_dir($private)) {
      mkdir($private, 0775, TRUE);
    }
    $this->setSetting('file_private_path', $private);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    // File::save() unconditionally touches file_usage.
    $this->installSchema('file', ['file_usage']);
    $this->installEntitySchema('aincient_context_entry');
    // Burn uid 1 (the superuser) so createUser() mints ordinary actors.
    $this->createUser();
  }

  /**
   * A valid PNG upload is stored: ledger row + normalized WebP derivative.
   */
  public function testStoreImagePersistsLedgerAndDerivative(): void {
    $actor = $this->createUser();

    // A real, oversized PNG (long edge > the 1092 ceiling) so downscale runs.
    [$path, $originalBytes] = $this->makePng(2000, 1200);
    $upload = new UploadedFile($path, 'holiday snap.png', 'image/png', NULL, TRUE);

    $store = $this->container->get('aincient_core.context_store');
    $this->assertInstanceOf(ContextStore::class, $store);

    $entry = $store->storeImage($upload, 'thr_abc123', 7, (int) $actor->id());

    // Persisted.
    $this->assertInstanceOf(ContextLedgerEntry::class, $entry);
    $this->assertNotNull($entry->id());
    $reloaded = $this->container->get('entity_type.manager')
      ->getStorage('aincient_context_entry')
      ->loadUnchanged($entry->id());
    $this->assertInstanceOf(ContextLedgerEntry::class, $reloaded);

    // The derivative File: WebP, under our private directory, long edge <= 1092.
    $derivative = File::load((int) $entry->get('file')->target_id);
    $this->assertNotNull($derivative);
    $uri = $derivative->getFileUri();
    $this->assertStringStartsWith('private://atelier-context/', $uri);
    $this->assertStringEndsWith('.webp', $uri);
    $this->assertFileExists($this->container->get('file_system')->realpath($uri));

    $info = getimagesize($this->container->get('file_system')->realpath($uri));
    $this->assertNotFalse($info);
    $this->assertSame(IMAGETYPE_WEBP, $info[2], 'The derivative is WebP.');
    $this->assertLessThanOrEqual(1092, max((int) $info[0], (int) $info[1]), 'The derivative long edge fits the ceiling.');
    $this->assertSame((int) $info[0], (int) $entry->get('width')->value);
    $this->assertSame((int) $info[1], (int) $entry->get('height')->value);

    // Ledger fields.
    $this->assertSame('thr_abc123', $entry->get('thread_id')->value);
    $this->assertSame(7, (int) $entry->get('turn')->value);
    $this->assertSame((int) $actor->id(), (int) $entry->getOwnerId());
    $this->assertSame('image', $entry->get('kind')->value);
    $this->assertSame(hash('sha256', $originalBytes), $entry->get('sha256')->value, 'sha256 is the hash of the original bytes.');
    $this->assertSame(strlen($originalBytes), (int) $entry->get('size')->value);
    $this->assertSame('image/png', $entry->get('declared_mime')->value);
    $this->assertSame('image/png', $entry->get('sniffed_mime')->value);
    $this->assertSame('holiday snap.png', $entry->get('filename')->value, 'The client filename is preserved in the ledger.');

    // derivative_sha256 is the hash of the bytes actually stored on disk.
    $storedBytes = file_get_contents($this->container->get('file_system')->realpath($uri));
    $this->assertSame(hash('sha256', $storedBytes), $entry->get('derivative_sha256')->value);

    // Original NOT kept by default.
    $this->assertTrue($entry->get('original_file')->isEmpty(), 'The original is not stored by default.');

    // The client filename never leaks into the URI; the hash does.
    $this->assertStringNotContainsStringIgnoringCase('holiday', $uri);
    $this->assertStringContainsString($entry->get('derivative_sha256')->value, $uri);

    // entryForUri round-trips, and returns NULL for an unrelated uri.
    $found = $store->entryForUri($uri);
    $this->assertNotNull($found);
    $this->assertSame((int) $entry->id(), (int) $found->id());
    $this->assertNull($store->entryForUri('private://atelier-context/deadbeef.webp'));

    @unlink($path);
  }

  /**
   * Builds a real PNG of the given size and returns [path, bytes].
   *
   * @return array{0:string, 1:string}
   *   The temp file path and the raw PNG bytes.
   */
  private function makePng(int $width, int $height): array {
    $im = imagecreatetruecolor($width, $height);
    // A splash of colour so the image isn't degenerate.
    imagefilledrectangle($im, 0, 0, (int) ($width / 2), $height, imagecolorallocate($im, 200, 40, 40));
    $path = sys_get_temp_dir() . '/' . uniqid('ctx-test-', TRUE) . '.png';
    imagepng($im, $path);
    imagedestroy($im);
    return [$path, (string) file_get_contents($path)];
  }

}
