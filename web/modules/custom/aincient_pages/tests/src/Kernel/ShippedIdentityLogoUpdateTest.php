<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Kernel;

use Drupal\aincient_pages\SiteIdentity;
use Drupal\Core\Config\FileStorage;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\KernelTests\KernelTestBase;
use Drupal\media\Entity\Media;
use Drupal\media\Entity\MediaType;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * aincient_pages_update_10019: clear the `media:7` logo/favicon nobody chose.
 *
 * Every install shipped `logo`/`favicon: 'media:7'` from config/sync. The update
 * clears a key only while it still holds exactly that token and media 7 is
 * missing or not image media (which the Globals picker could not have offered).
 *
 * @group aincient
 */
#[RunTestsInSeparateProcesses]
final class ShippedIdentityLogoUpdateTest extends KernelTestBase {

  protected static $modules = [
    'system', 'user', 'field', 'text', 'file', 'image', 'media', 'node', 'workflows', 'content_moderation', 'aincient_core', 'aincient_pages',
  ];

  /** The owner's other identity values, which the update must never touch. */
  private const OTHER = [
    'guidelines' => ['name' => 'Bakery', 'tagline' => 'Fresh daily', 'description' => '', 'tone' => 'warm'],
    'footer_note' => '© Bakery',
    'build_with' => FALSE,
    'site' => ['mail' => 'owner@example.com', 'front' => '', 'page_403' => '', 'page_404' => ''],
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installSchema('file', ['file_usage']);
    $this->installConfig(['system', 'field', 'media']);

    // The image media type the Globals logo/favicon picker draws from.
    MediaType::create([
      'id' => 'image',
      'label' => 'Image',
      'source' => 'image',
      'source_configuration' => ['source_field' => 'field_media_image'],
    ])->save();
    FieldStorageConfig::create(['field_name' => 'field_media_image', 'entity_type' => 'media', 'type' => 'image'])->save();
    FieldConfig::create(['field_name' => 'field_media_image', 'entity_type' => 'media', 'bundle' => 'image', 'label' => 'Image'])->save();

    // The global-block media type (DECISIONS 0138): media, but never offered by
    // the logo picker. aincient_pages may already ship it.
    if (!MediaType::load('block')) {
      MediaType::create([
        'id' => 'block',
        'label' => 'Global block',
        'source' => 'aincient_block_fragment',
        'source_configuration' => ['source_field' => 'field_page_content'],
      ])->save();
    }
    if (!FieldStorageConfig::loadByName('media', 'field_page_content')) {
      FieldStorageConfig::create(['field_name' => 'field_page_content', 'entity_type' => 'media', 'type' => 'string_long'])->save();
    }
    if (!FieldConfig::loadByName('media', 'block', 'field_page_content')) {
      FieldConfig::create(['field_name' => 'field_page_content', 'entity_type' => 'media', 'bundle' => 'block', 'label' => 'Content'])->save();
    }

    \Drupal::moduleHandler()->loadInclude('aincient_pages', 'install');
  }

  public function testMissingMediaSevenIsCleared(): void {
    $this->identity('media:7', 'media:7');

    $message = (string) aincient_pages_update_10019();

    $this->assertSame('', $this->config(SiteIdentity::CONFIG)->get('logo'));
    $this->assertSame('', $this->config(SiteIdentity::CONFIG)->get('favicon'));
    $this->assertStringContainsString('Cleared', $message);
    $this->assertOthersUntouched();
  }

  public function testNonImageMediaSevenIsCleared(): void {
    $this->createMedia(7, 'block');
    $this->identity('media:7', 'media:7');

    aincient_pages_update_10019();

    $this->assertSame('', $this->config(SiteIdentity::CONFIG)->get('logo'));
    $this->assertSame('', $this->config(SiteIdentity::CONFIG)->get('favicon'));
    $this->assertOthersUntouched();
  }

  public function testOnlyTheKeyStillOnMediaSevenIsCleared(): void {
    $this->createMedia(12, 'image');
    $this->identity('media:7', 'media:12');

    aincient_pages_update_10019();

    $this->assertSame('', $this->config(SiteIdentity::CONFIG)->get('logo'));
    $this->assertSame('media:12', $this->config(SiteIdentity::CONFIG)->get('favicon'));
    $this->assertOthersUntouched();
  }

  public function testOwnerChosenOtherTokenIsUntouched(): void {
    $this->createMedia(12, 'image');
    $this->identity('media:12', 'media:12');

    $message = (string) aincient_pages_update_10019();

    $this->assertSame('media:12', $this->config(SiteIdentity::CONFIG)->get('logo'));
    $this->assertSame('media:12', $this->config(SiteIdentity::CONFIG)->get('favicon'));
    $this->assertStringContainsString('unchanged', $message);
    $this->assertOthersUntouched();
  }

  public function testExistingImageMediaSevenIsUntouched(): void {
    $this->createMedia(7, 'image');
    $this->identity('media:7', 'media:7');

    $message = (string) aincient_pages_update_10019();

    $this->assertSame('media:7', $this->config(SiteIdentity::CONFIG)->get('logo'));
    $this->assertSame('media:7', $this->config(SiteIdentity::CONFIG)->get('favicon'));
    $this->assertStringContainsString('unchanged', $message);
    $this->assertOthersUntouched();
  }

  public function testEmptyIsUntouched(): void {
    $this->identity('', '');

    aincient_pages_update_10019();

    $this->assertSame('', $this->config(SiteIdentity::CONFIG)->get('logo'));
    $this->assertSame('', $this->config(SiteIdentity::CONFIG)->get('favicon'));
    $this->assertOthersUntouched();
  }

  /** The shipped config/sync copy must never point at a media item again. */
  public function testConfigSyncShipsNoLogoOrFavicon(): void {
    $shipped = (new FileStorage(DRUPAL_ROOT . '/../config/sync'))->read(SiteIdentity::CONFIG);
    $this->assertIsArray($shipped);
    $this->assertSame('', $shipped['logo']);
    $this->assertSame('', $shipped['favicon']);
  }

  private function identity(string $logo, string $favicon): void {
    $config = $this->config(SiteIdentity::CONFIG);
    foreach (self::OTHER as $key => $value) {
      $config->set($key, $value);
    }
    $config->set('logo', $logo)->set('favicon', $favicon)->save();
  }

  private function assertOthersUntouched(): void {
    $config = $this->config(SiteIdentity::CONFIG);
    foreach (self::OTHER as $key => $value) {
      $this->assertSame($value, $config->get($key), "$key untouched");
    }
  }

  private function createMedia(int $id, string $bundle): void {
    if ($bundle === 'image') {
      $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
      file_put_contents("public://logo-$id.png", $png);
      $file = File::create(['uri' => "public://logo-$id.png", 'status' => 1]);
      $file->save();
      $values = ['field_media_image' => [['target_id' => $file->id()]]];
    }
    else {
      $values = ['field_page_content' => '{"sections":[]}'];
    }
    Media::create(['mid' => $id, 'bundle' => $bundle, 'name' => "Media $id"] + $values)->save();
    $this->assertNotNull(Media::load($id));
  }

}
