<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_core\Kernel;

use Drupal\file\Entity\File;
use Drupal\user\Entity\User;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The owner-scoped chokepoint over the private context store.
 *
 * Covers aincient_core_file_download(): Drupal's private delivery denies by
 * default, and this hook is the only grant — to the entry's owner or a store
 * administrator, and to no one else; a non-context private uri is passed
 * through (NULL) so other modules decide.
 *
 * @group aincient_core
 */
#[RunTestsInSeparateProcesses]
final class ContextDownloadHookTest extends KernelTestBase {

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
    $this->installSchema('file', ['file_usage']);
    $this->installEntitySchema('aincient_context_entry');
    // Burn uid 1 so createUser() mints ordinary, non-superuser actors.
    $this->createUser();
  }

  /**
   * Stores one entry owned by a fresh user and returns [entry, ownerUid, uri].
   *
   * @return array{0:\Drupal\aincient_core\Entity\ContextLedgerEntry, 1:int, 2:string}
   *   The entry, its owner uid, and the derivative uri.
   */
  private function storeOne(): array {
    $owner = $this->createUser();
    $im = imagecreatetruecolor(64, 48);
    $path = sys_get_temp_dir() . '/' . uniqid('ctx-hook-', TRUE) . '.png';
    imagepng($im, $path);
    imagedestroy($im);
    $upload = new UploadedFile($path, 'secret.png', 'image/png', NULL, TRUE);

    $entry = $this->container->get('aincient_core.context_store')
      ->storeImage($upload, 'thr_hook', 1, (int) $owner->id());
    @unlink($path);

    $uri = File::load((int) $entry->get('file')->target_id)->getFileUri();
    return [$entry, (int) $owner->id(), $uri];
  }

  /**
   * The owner gets served headers, with the no-store cache directive.
   */
  public function testOwnerIsServedWithNoStore(): void {
    [, $ownerUid, $uri] = $this->storeOne();
    $this->setCurrentUser(User::load($ownerUid));

    $result = aincient_core_file_download($uri);

    $this->assertIsArray($result);
    $this->assertSame('image/webp', $result['Content-Type']);
    $this->assertSame('private, no-store', $result['Cache-Control']);
    $this->assertArrayHasKey('Content-Length', $result);
  }

  /**
   * A different, non-admin user is denied.
   */
  public function testOtherUserIsDenied(): void {
    [, , $uri] = $this->storeOne();
    $stranger = $this->createUser();
    $this->setCurrentUser($stranger);

    $this->assertSame(-1, aincient_core_file_download($uri));
  }

  /**
   * A store administrator is served, even without ownership.
   */
  public function testAdminIsServed(): void {
    [, , $uri] = $this->storeOne();
    $admin = $this->createUser(['administer aincient context store']);
    $this->setCurrentUser($admin);

    $result = aincient_core_file_download($uri);

    $this->assertIsArray($result);
    $this->assertSame('private, no-store', $result['Cache-Control']);
  }

  /**
   * A non-context private uri is passed through (NULL), not our concern.
   */
  public function testUnrelatedPrivateUriPassesThrough(): void {
    $this->assertNull(aincient_core_file_download('private://some-other-thing/file.txt'));
  }

  /**
   * An orphan under our directory (no ledger entry) is denied.
   */
  public function testOrphanUnderOurDirectoryIsDenied(): void {
    $this->setCurrentUser($this->createUser());
    $this->assertSame(-1, aincient_core_file_download('private://atelier-context/deadbeef.webp'));
  }

}
