<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Kernel;

use Drupal\aincient_pages\MediaRepository;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\image\Entity\ImageStyle;
use Drupal\KernelTests\KernelTestBase;
use Drupal\media\Entity\Media;
use Drupal\media\Entity\MediaType;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Tests the image-media library behind the page authoring path (Phase 4a).
 *
 * @group aincient
 */
#[RunTestsInSeparateProcesses]
final class MediaRepositoryTest extends KernelTestBase {

  protected static $modules = [
    'system', 'user', 'field', 'text', 'file', 'image', 'media', 'workflows', 'content_moderation', 'aincient_pages',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installSchema('file', ['file_usage']);
    $this->installConfig(['system', 'field', 'media']);

    MediaType::create([
      'id' => 'image',
      'label' => 'Image',
      'source' => 'image',
      'source_configuration' => ['source_field' => 'field_media_image'],
    ])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_media_image',
      'entity_type' => 'media',
      'type' => 'image',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_media_image',
      'entity_type' => 'media',
      'bundle' => 'image',
      'label' => 'Image',
      'settings' => [
        'alt_field' => TRUE,
        'file_extensions' => 'png jpg jpeg',
        'uri_scheme' => 'public',
        // The core default: a directory built ENTIRELY of date tokens joined by
        // a literal '-'. Regression guard — this must resolve to e.g. '2026-07',
        // not collapse to the bare '-' separator (public://-/). See
        // testCreateFromUploadResolvesDateTokens().
        'file_directory' => '[date:custom:Y]-[date:custom:m]',
      ],
    ])->save();
    ImageStyle::create(['name' => 'media_library', 'label' => 'Media Library'])->save();
    // The display-sized crop the Presence teaser card resolves through.
    ImageStyle::create(['name' => '960w540h', 'label' => '960w540h'])->save();
  }

  private function repo(): MediaRepository {
    return $this->container->get('aincient_pages.media');
  }

  /** Persist a real-enough image media item with the given name. */
  private function makeMedia(string $name): int {
    $path = 'public://' . $name . '.png';
    file_put_contents($path, base64_decode(
      'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='
    ));
    $file = File::create(['uri' => $path, 'status' => 1]);
    $file->save();
    $media = Media::create([
      'bundle' => 'image',
      'name' => $name,
      'field_media_image' => ['target_id' => $file->id(), 'alt' => $name . ' alt'],
    ]);
    $media->save();
    return (int) $media->id();
  }

  public function testSearchReturnsShapedTokens(): void {
    $id = $this->makeMedia('Falcon');
    $rows = $this->repo()->search();
    $this->assertCount(1, $rows);
    $this->assertSame((string) $id, $rows[0]['id']);
    $this->assertSame('media:' . $id, $rows[0]['token']);
    $this->assertSame('Falcon', $rows[0]['name']);
    $this->assertSame('Falcon alt', $rows[0]['alt']);
    // The thumbnail resolves through the media_library style.
    $this->assertStringContainsString('styles/media_library', $rows[0]['thumb']);
  }

  public function testSearchFiltersByName(): void {
    $this->makeMedia('Hero background');
    $this->makeMedia('Team photo');
    $this->assertCount(1, $this->repo()->search('team'));
    $this->assertCount(1, $this->repo()->search('hero'));
    $this->assertCount(0, $this->repo()->search('nope'));
    $this->assertCount(2, $this->repo()->search(''));
  }

  public function testResolveTokenRoundTrips(): void {
    $id = $this->makeMedia('Falcon');
    $row = $this->repo()->resolveToken('media:' . $id);
    $this->assertNotNull($row);
    $this->assertSame('media:' . $id, $row['token']);
    // A bare id is accepted too; a dangling / malformed token is NULL.
    $this->assertNotNull($this->repo()->resolveToken((string) $id));
    $this->assertNull($this->repo()->resolveToken('media:999999'));
    $this->assertNull($this->repo()->resolveToken('not-a-token'));
  }

  public function testPreviewUrlResolvesAtDisplayStyle(): void {
    $id = $this->makeMedia('Falcon');
    // A media token resolves through the requested display-sized style — NOT the
    // small media_library thumb the picker rows carry (which upscales blurry).
    $url = $this->repo()->previewUrl('media:' . $id, '960w540h');
    $this->assertNotNull($url);
    $this->assertStringContainsString('styles/960w540h', $url);
    // Non-media tokens (and dangling refs) don't resolve — the card falls back.
    $this->assertNull($this->repo()->previewUrl('entity:node:1', '960w540h'));
    $this->assertNull($this->repo()->previewUrl('media:999999', '960w540h'));
    $this->assertNull($this->repo()->previewUrl('not-a-token', '960w540h'));
  }

  public function testCreateFromUploadMakesMediaWithAlt(): void {
    $tmp = $this->container->get('file_system')->realpath('temporary://') . '/' . uniqid('upl') . '.png';
    file_put_contents($tmp, base64_decode(
      'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='
    ));
    $upload = new UploadedFile($tmp, 'logo.png', 'image/png', NULL, TRUE);
    $row = $this->repo()->createFromUpload($upload, 'Our logo');
    $this->assertSame('Our logo', $row['name']);
    $this->assertSame('Our logo', $row['alt']);
    $this->assertMatchesRegularExpression('/^media:\d+$/', $row['token']);
    // The new item is findable through the same library.
    $this->assertNotNull($this->repo()->resolveToken($row['token']));
  }

  public function testCreateFromUploadResolvesDateTokens(): void {
    $tmp = $this->container->get('file_system')->realpath('temporary://') . '/' . uniqid('upl') . '.png';
    file_put_contents($tmp, base64_decode(
      'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='
    ));
    $upload = new UploadedFile($tmp, 'logo.png', 'image/png', NULL, TRUE);
    $row = $this->repo()->createFromUpload($upload);

    // The file must land in the field's token-resolved directory (e.g.
    // 'public://2026-07/…'), NOT public://-/ from stripping the date tokens.
    $expected = $this->container->get('date.formatter')
      ->format($this->container->get('datetime.time')->getRequestTime(), 'custom', 'Y-m');
    $id = (int) explode(':', $row['token'])[1];
    $uri = Media::load($id)->get('field_media_image')->entity->getFileUri();
    $this->assertStringStartsWith('public://' . $expected . '/', $uri);
    $this->assertStringNotContainsString('://-/', $uri);
  }

  public function testRejectsDisallowedExtension(): void {
    $tmp = $this->container->get('file_system')->tempnam('temporary://', 'upl') . '.txt';
    file_put_contents($tmp, 'not an image');
    $upload = new UploadedFile($tmp, 'notes.txt', 'text/plain', NULL, TRUE);
    $this->expectException(\RuntimeException::class);
    $this->repo()->createFromUpload($upload);
  }

  public function testDetailSupersetsAPickerRow(): void {
    $id = $this->makeMedia('Falcon');
    $row = $this->repo()->detail($id);
    $this->assertNotNull($row);
    // The picker-row fields still hold …
    $this->assertSame((string) $id, $row['id']);
    $this->assertSame('media:' . $id, $row['token']);
    $this->assertSame('Falcon', $row['name']);
    $this->assertSame('Falcon alt', $row['alt']);
    // … plus the editor-only additions.
    $this->assertSame('published', $row['status']);
    $this->assertSame('image/png', $row['mime']);
    $this->assertSame(1, $row['width']);
    $this->assertSame(1, $row['height']);
    // The preview is the source file, not the small media_library thumb.
    $this->assertStringNotContainsString('styles/media_library', $row['preview']);
    // An unknown / non-image id has no detail.
    $this->assertNull($this->repo()->detail(999999));
  }

  public function testUpdateMetadataWritesNameAndAltIndependently(): void {
    $id = $this->makeMedia('Falcon');
    // Alt-only save leaves the name alone.
    $row = $this->repo()->updateMetadata($id, ['alt' => 'A soaring falcon']);
    $this->assertSame('Falcon', $row['name']);
    $this->assertSame('A soaring falcon', $row['alt']);
    // Name-only save leaves the (now updated) alt alone.
    $row = $this->repo()->updateMetadata($id, ['name' => 'Peregrine']);
    $this->assertSame('Peregrine', $row['name']);
    $this->assertSame('A soaring falcon', $row['alt']);
    // Persisted, not just returned.
    $reloaded = $this->repo()->detail($id);
    $this->assertSame('Peregrine', $reloaded['name']);
    $this->assertSame('A soaring falcon', $reloaded['alt']);
  }

  public function testUpdateMetadataRejectsEmptyNameAndUnknownId(): void {
    $id = $this->makeMedia('Falcon');
    try {
      $this->repo()->updateMetadata($id, ['name' => '   ']);
      $this->fail('Expected an empty name to be rejected.');
    }
    catch (\RuntimeException) {
    }
    $this->expectException(\RuntimeException::class);
    $this->repo()->updateMetadata(999999, ['name' => 'X']);
  }

  public function testCreateFromBytesMintsMediaAndToken(): void {
    $png = base64_decode(
      'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='
    );
    $row = $this->repo()->createFromBytes($png, 'a-warm-sunrise.png', 'A warm sunrise');
    // A generated image is indistinguishable from an upload: name/alt seed from
    // the alt, and it carries a fresh, resolvable media:<id> token.
    $this->assertSame('A warm sunrise', $row['name']);
    $this->assertSame('A warm sunrise', $row['alt']);
    $this->assertMatchesRegularExpression('/^media:\d+$/', $row['token']);
    $this->assertNotNull($this->repo()->resolveToken($row['token']));
    // The bytes really landed as a readable image file.
    $id = (int) explode(':', $row['token'])[1];
    $detail = $this->repo()->detail($id);
    $this->assertSame(1, $detail['width']);
    $this->assertSame(1, $detail['height']);
    // Empty bytes are rejected (a provider that returned nothing).
    $this->expectException(\RuntimeException::class);
    $this->repo()->createFromBytes('', 'x.png', NULL);
  }

  public function testCreateFromBytesNormalisesExtension(): void {
    $png = base64_decode(
      'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='
    );
    // A filename with no / a disallowed extension is normalised to png (what the
    // field allows and Nano Banana returns) so the stored file is always valid.
    $row = $this->repo()->createFromBytes($png, 'no-extension-here', NULL);
    $id = (int) explode(':', $row['token'])[1];
    $uri = Media::load($id)->get('field_media_image')->entity->getFileUri();
    $this->assertStringEndsWith('.png', $uri);
  }

  public function testSourceBytesReadsAnExistingItem(): void {
    $id = $this->makeMedia('Falcon');
    $bytes = $this->repo()->sourceBytes('media:' . $id);
    $this->assertNotNull($bytes);
    $this->assertNotSame('', $bytes['binary']);
    $this->assertSame('image/png', $bytes['mime']);
    $this->assertStringEndsWith('.png', $bytes['filename']);
    // A bare id works; a dangling / non-media / malformed token is NULL.
    $this->assertNotNull($this->repo()->sourceBytes((string) $id));
    $this->assertNull($this->repo()->sourceBytes('media:999999'));
    $this->assertNull($this->repo()->sourceBytes('entity:node:1'));
    $this->assertNull($this->repo()->sourceBytes('not-a-token'));
  }

  public function testReplaceFileKeepsTokenAndAlt(): void {
    $id = $this->makeMedia('Falcon');
    $before = $this->repo()->detail($id);

    $tmp = $this->container->get('file_system')->realpath('temporary://') . '/' . uniqid('upl') . '.png';
    // A 2×2 PNG — distinct dimensions from the 1×1 the item started with, so we
    // can prove the file (and its derived width/height) actually changed.
    file_put_contents($tmp, base64_decode(
      'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAEElEQVR4nGM4IScHRAwQCgAfJgQRoo8irwAAAABJRU5ErkJggg=='
    ));
    $upload = new UploadedFile($tmp, 'replacement.png', 'image/png', NULL, TRUE);
    $after = $this->repo()->replaceFile($id, $upload);

    // Same media item → same id + token (every consumer keeps resolving).
    $this->assertSame($before['token'], $after['token']);
    $this->assertSame($before['id'], $after['id']);
    // Alt text is a property of the item, preserved across the file swap.
    $this->assertSame('Falcon alt', $after['alt']);
    // The bytes really changed.
    $this->assertSame(2, $after['width']);
    $this->assertSame(2, $after['height']);
  }

  public function testReplaceFromMediaOverwritesTargetKeepingItsToken(): void {
    $target = $this->makeMedia('Original');
    // A distinct 2×2 source whose bytes we commit onto the target.
    $srcPath = 'public://edited.png';
    file_put_contents($srcPath, base64_decode(
      'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAEElEQVR4nGM4IScHRAwQCgAfJgQRoo8irwAAAABJRU5ErkJggg=='
    ));
    $srcFile = File::create(['uri' => $srcPath, 'status' => 1]);
    $srcFile->save();
    $source = Media::create([
      'bundle' => 'image',
      'name' => 'Edited',
      'field_media_image' => ['target_id' => $srcFile->id(), 'alt' => 'Edited alt'],
    ]);
    $source->save();

    $before = $this->repo()->detail($target);
    $after = $this->repo()->replaceFromMedia($target, 'media:' . $source->id());

    // Target keeps its identity + its own alt; only the pixels change.
    $this->assertSame($before['token'], $after['token']);
    $this->assertSame($before['id'], $after['id']);
    $this->assertSame('Original alt', $after['alt']);
    $this->assertSame(2, $after['width']);
    $this->assertSame(2, $after['height']);
    // The source is untouched (non-destructive on the "from" side).
    $this->assertNotNull(Media::load($source->id()));
  }

  public function testReplaceFromMediaRejectsUnreadableSource(): void {
    $target = $this->makeMedia('Original');
    $this->expectException(\RuntimeException::class);
    $this->repo()->replaceFromMedia($target, 'media:999999');
  }

  public function testDeleteRemovesTheItem(): void {
    $id = $this->makeMedia('Disposable');
    $this->assertNotNull($this->repo()->detail($id));
    $this->repo()->delete($id);
    $this->assertNull(Media::load($id));
    $this->assertNull($this->repo()->detail($id));
  }

  public function testDeleteRejectsUnknownId(): void {
    $this->expectException(\RuntimeException::class);
    $this->repo()->delete(999999);
  }

}
