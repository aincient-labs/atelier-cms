<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_core\Unit\File;

use Drupal\aincient_core\File\ImageUploadValidator;
use Drupal\aincient_core\File\ValidatedImage;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * @coversDefaultClass \Drupal\aincient_core\File\ImageUploadValidator
 * @group aincient_core
 */
class ImageUploadValidatorTest extends UnitTestCase {

  /**
   * Temp files created during a test, cleaned up in tearDown().
   *
   * @var string[]
   */
  private array $tempFiles = [];

  protected function tearDown(): void {
    foreach ($this->tempFiles as $path) {
      if (is_file($path)) {
        unlink($path);
      }
    }
    $this->tempFiles = [];
    parent::tearDown();
  }

  /**
   * Creates a temp file and tracks it for cleanup.
   */
  private function tempPath(string $suffix): string {
    $path = tempnam(sys_get_temp_dir(), 'aincient_iuv_') . $suffix;
    $this->tempFiles[] = $path;
    return $path;
  }

  /**
   * Builds a valid JPEG fixture file.
   */
  private function makeJpeg(int $width = 20, int $height = 20): string {
    $path = $this->tempPath('.jpg');
    $im = imagecreatetruecolor($width, $height);
    imagejpeg($im, $path);
    imagedestroy($im);
    return $path;
  }

  /**
   * Builds a valid PNG fixture file.
   */
  private function makePng(int $width = 20, int $height = 20): string {
    $path = $this->tempPath('.png');
    $im = imagecreatetruecolor($width, $height);
    imagepng($im, $path);
    imagedestroy($im);
    return $path;
  }

  /**
   * @covers ::validate
   */
  public function testValidJpegPasses(): void {
    $path = $this->makeJpeg(20, 30);
    $upload = new UploadedFile($path, 'x.jpg', 'image/jpeg', NULL, TRUE);

    $validator = new ImageUploadValidator();
    $result = $validator->validate($upload, 'png gif jpg jpeg webp', NULL);

    $this->assertInstanceOf(ValidatedImage::class, $result);
    $this->assertSame('jpg', $result->extension);
    $this->assertSame('image/jpeg', $result->mimeType);
    $this->assertSame(20, $result->width);
    $this->assertSame(30, $result->height);
    $this->assertNotEmpty($result->bytes);

    $info = getimagesizefromstring($result->bytes);
    $this->assertNotFalse($info);
    $this->assertSame(\IMAGETYPE_JPEG, $info[2]);
  }

  /**
   * @covers ::validate
   */
  public function testValidAvifPasses(): void {
    if (!function_exists('imageavif')) {
      $this->markTestSkipped('GD lacks AVIF support');
    }

    $path = $this->tempPath('.avif');
    $im = imagecreatetruecolor(16, 16);
    imageavif($im, $path, 82);
    imagedestroy($im);
    $upload = new UploadedFile($path, 'x.avif', 'image/avif', NULL, TRUE);

    $validator = new ImageUploadValidator();
    $result = $validator->validate($upload, 'png gif jpg jpeg webp avif', NULL);

    $this->assertInstanceOf(ValidatedImage::class, $result);
    $this->assertSame('avif', $result->extension);
    $this->assertNotEmpty($result->bytes);
  }

  /**
   * @covers ::parseExtensions
   * @covers ::maxBytes
   */
  public function testParseExtensionsAndMaxBytes(): void {
    $this->assertSame(
      ['png', 'gif', 'jpg', 'jpeg', 'webp'],
      ImageUploadValidator::parseExtensions('png gif jpg jpeg webp')
    );

    $this->assertSame(8 * 1024 * 1024, ImageUploadValidator::maxBytes('8 MB'));
    $this->assertSame(0, ImageUploadValidator::maxBytes(''));
    $this->assertSame(0, ImageUploadValidator::maxBytes(NULL));
  }

  /**
   * @covers ::validate
   */
  public function testNonImageThrows(): void {
    $path = $this->tempPath('.jpg');
    file_put_contents($path, 'not an image');
    $upload = new UploadedFile($path, 'x.jpg', 'image/jpeg', NULL, TRUE);

    $validator = new ImageUploadValidator();
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessageMatches('/not a valid image/');
    $validator->validate($upload, 'png gif jpg jpeg webp', NULL);
  }

  /**
   * @covers ::validate
   */
  public function testDisallowedExtensionThrows(): void {
    $path = $this->makePng();
    $upload = new UploadedFile($path, 'x.png', 'image/png', NULL, TRUE);

    $validator = new ImageUploadValidator();
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessageMatches('/Unsupported file type/');
    $validator->validate($upload, 'gif', NULL);
  }

  /**
   * The security assertion: a polyglot (image bytes + trailing PHP payload)
   * is re-encoded, and the payload does not survive into the returned bytes.
   *
   * @covers ::validate
   */
  public function testPolyglotPayloadIsStripped(): void {
    $jpegPath = $this->makeJpeg();
    $jpegBytes = file_get_contents($jpegPath);
    $this->assertNotFalse($jpegBytes);

    $polyglotPath = $this->tempPath('.jpg');
    file_put_contents($polyglotPath, $jpegBytes . "\n<?php echo 'pwned'; ?>");

    $upload = new UploadedFile($polyglotPath, 'x.jpg', 'image/jpeg', NULL, TRUE);

    $validator = new ImageUploadValidator();
    $result = $validator->validate($upload, 'png gif jpg jpeg webp', NULL);

    $this->assertStringNotContainsString('<?php', $result->bytes);
  }

}
