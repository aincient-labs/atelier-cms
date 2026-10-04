<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\image\Entity\ImageStyle;
use Drupal\Core\Site\Settings;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Core\Test\TestDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves the site's image styles really emit AVIF / WebP bytes.
 *
 * Nothing else generates a derivative in tests, so a missing ImageMagick
 * delegate would be silent: image_convert_avif quietly falls back to its
 * configured WebP extension when the toolkit lacks AVIF. This test installs
 * the real ImageMagick toolkit config and style entities from config/sync,
 * renders derivatives and checks the magic bytes. It FAILS (never skips) if
 * the toolkit cannot write the format.
 *
 * @group aincient
 */
#[RunTestsInSeparateProcesses]
final class ImageStyleDerivativeFormatTest extends KernelTestBase {

  protected static $modules = [
    'system', 'user', 'file', 'image', 'file_mdm', 'sophron', 'imagemagick',
  ];

  /**
   * Uses the REAL filesystem, not vfsStream.
   *
   * The ImageMagick toolkit shells out to identify/convert, which cannot open
   * vfs:// paths; with the default vfs files directory every derivative fails
   * regardless of delegates. KernelTestBase::tearDown() removes this directory.
   */
  protected function setUpFilesystem() {
    $test_db = new TestDatabase($this->databasePrefix);
    $this->siteDirectory = $this->root . '/' . $test_db->getTestSitePath();
    mkdir($this->siteDirectory . '/files/config/sync', 0775, TRUE);
    $settings = Settings::getInstance() ? Settings::getAll() : [];
    $settings['file_public_path'] = $this->siteDirectory . '/files';
    $settings['config_sync_directory'] = $this->siteDirectory . '/files/config/sync';
    new Settings($settings);
  }

  private function sync(string $name): array {
    $path = DRUPAL_ROOT . '/../config/sync/' . $name . '.yml';
    $this->assertFileExists($path);
    $data = Yaml::decode(file_get_contents($path));
    unset($data['_core']);
    return $data;
  }

  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system', 'image', 'file_mdm', 'sophron', 'imagemagick']);
    // The real toolkit configuration, exactly as the site has it (not GD).
    $this->config('system.image')->setData($this->sync('system.image'))->save();
    $this->config('imagemagick.settings')->setData($this->sync('imagemagick.settings'))->save();
    $this->assertSame('imagemagick', $this->config('system.image')->get('toolkit'));
    foreach (['thumbnail', 'medium', 'large', 'media_library', 'webp'] as $id) {
      ImageStyle::load($id)?->delete();
      ImageStyle::create($this->sync('image.style.' . $id))->save();
    }
  }

  /**
   * @return array<string, array{0: string, 1: string}>
   */
  public static function styleProvider(): array {
    return [
      'medium (image_convert_avif)' => ['medium', 'avif'],
      'thumbnail (image_convert_avif)' => ['thumbnail', 'avif'],
      'large (image_convert_avif)' => ['large', 'avif'],
      'media_library (image_convert_avif)' => ['media_library', 'avif'],
      'webp (image_convert)' => ['webp', 'webp'],
    ];
  }

  #[DataProvider('styleProvider')]
  public function testDerivativeBytes(string $id, string $format): void {
    $dir = 'public://derivative-test';
    $this->assertTrue(\Drupal::service('file_system')->prepareDirectory($dir, 1));
    $source = $dir . '/source.png';
    $gd = imagecreatetruecolor(400, 300);
    imagefilledrectangle($gd, 0, 0, 399, 299, imagecolorallocate($gd, 200, 60, 40));
    imagefilledellipse($gd, 200, 150, 160, 160, imagecolorallocate($gd, 30, 90, 200));
    ob_start();
    imagepng($gd);
    file_put_contents($source, ob_get_clean());
    $this->assertGreaterThan(0, filesize($source), 'PNG fixture written.');

    $style = ImageStyle::load($id);
    $this->assertNotNull($style, "Style $id loads.");
    // The style's own extension mapping must already say $format; for the AVIF
    // styles that is false (and the dest is .webp) when the toolkit lacks AVIF.
    $dest = $style->buildUri($source);
    $this->assertStringEndsWith('.' . $format, $dest, "Style $id plans a .$format derivative (a .webp here means AVIF is unsupported by the toolkit).");
    $this->assertTrue($style->createDerivative($source, $dest), "Style $id createDerivative() succeeded.");
    $this->assertFileExists($dest, "Style $id derivative exists.");
    $bytes = file_get_contents($dest);
    $this->assertGreaterThan(0, strlen($bytes), "Style $id derivative is non-empty.");

    if ($format === 'avif') {
      $this->assertSame('ftyp', substr($bytes, 4, 4), "Style $id: ISO-BMFF 'ftyp' box at offset 4.");
      $this->assertContains(substr($bytes, 8, 4), ['avif', 'avis'], "Style $id: AVIF major brand.");
    }
    else {
      $this->assertSame('RIFF', substr($bytes, 0, 4), "Style $id: RIFF header.");
      $this->assertSame('WEBP', substr($bytes, 8, 4), "Style $id: WEBP signature.");
    }
  }

}
