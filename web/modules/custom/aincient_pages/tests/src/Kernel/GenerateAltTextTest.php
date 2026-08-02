<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Kernel;

use Drupal\aincient_core\ModelRoles;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\image\Entity\ImageStyle;
use Drupal\KernelTests\KernelTestBase;
use Drupal\media\Entity\Media;
use Drupal\media\Entity\MediaType;
use Drupal\Tests\aincient_core\Traits\ScriptedInferenceTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the generate_alt_text capability (the Media studio's vision rail).
 *
 * Runs on the scripted inference provider ({@see ScriptedInferenceTrait}) as the
 * vision model, so the whole tool path runs — vision role resolution → chat call
 * with the image attached → alt suggestion → widget envelope — without a live
 * vision API key. The provider answers with whatever the test scripted, so the
 * assertions are about the product's promise (the model's words reach the human,
 * unwritten) rather than about an echo.
 *
 * @group aincient
 */
#[RunTestsInSeparateProcesses]
final class GenerateAltTextTest extends KernelTestBase {

  use ScriptedInferenceTrait;
  use UserCreationTrait;

  protected static $modules = [
    'system', 'user', 'field', 'text', 'file', 'image', 'media',
    'workflows', 'content_moderation', 'key',
    'aincient_core', 'aincient_inference_test', 'aincient_pages',
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
        'file_directory' => '[date:custom:Y]-[date:custom:m]',
      ],
    ])->save();
    ImageStyle::create(['name' => 'media_library', 'label' => 'Media Library'])->save();

    $this->setUpCurrentUser([], ['administer aincient pages']);
  }

  /** Run the tool with the given context, returning its readable output. */
  private function invoke(array $context): string {
    $tool = $this->container->get('plugin.manager.aincient.capabilities')
      ->createInstance('aincient_pages:generate_alt_text');
    foreach ($context as $name => $value) {
      $tool->setContextValue($name, $value);
    }
    $tool->execute();
    return $tool->getReadableOutput();
  }

  /** Seed an image media item and return its `media:<id>` token + id. */
  private function seedImage(string $alt = ''): array {
    $path = 'public://source.png';
    file_put_contents($path, base64_decode(
      'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='
    ));
    $file = File::create(['uri' => $path, 'status' => 1]);
    $file->save();
    $media = Media::create([
      'bundle' => 'image',
      'name' => 'Seed',
      'field_media_image' => ['target_id' => $file->id(), 'alt' => $alt],
    ]);
    $media->save();
    return ['token' => 'media:' . $media->id(), 'id' => (int) $media->id()];
  }

  /** Bind the vision role to the scripted provider. */
  private function bindVisionRole(): void {
    $this->connectScriptedProvider();
    $this->bindScriptedRole(ModelRoles::VISION);
  }

  public function testSuggestsAltTextWithoutPersistingIt(): void {
    $this->bindVisionRole();
    $this->scriptInferenceText('A single clay rooftop tile against a pale sky.');
    // Seed with a known alt so we can assert the tool leaves it untouched.
    $seed = $this->seedImage('original alt');

    $out = $this->invoke(['source' => $seed['token']]);
    $payload = json_decode($out, TRUE);

    $this->assertSame('media_result', $payload['__widget__'] ?? NULL, $out);
    $this->assertSame('alt_text', $payload['payload']['mode']);
    $this->assertSame($seed['token'], $payload['payload']['source']);
    // The MODEL's description is what's proposed — the round trip really happened.
    $this->assertSame('A single clay rooftop tile against a pale sky.', $payload['payload']['alt_text']);
    // And the image itself was sent, not just the instruction: a vision call that
    // forgets the picture would still return plausible text.
    $this->assertContains('Image', $this->lastInferenceCall()['parts']);
    // The suggestion carries the media id so the client can populate that item.
    $this->assertSame((string) $seed['id'], $payload['payload']['id']);
    // Crucially, NOTHING was written: the suggestion is staged into the editor for
    // the human to review + Save ("AI proposes, you approve"). The alt is unchanged.
    $media = Media::load($seed['id']);
    $this->assertSame('original alt', $media->get('field_media_image')->alt);
  }

  public function testWorksWithoutAnExplicitVisionBinding(): void {
    // No vision binding — resolve() falls back to the DEFAULT role's model, so
    // alt-text works out of the box (the "unset = use the default chat model"
    // contract; the explicit vision binding is an override, not a gate).
    $this->connectScriptedProvider();
    $this->bindScriptedRole(ModelRoles::TASK);
    $seed = $this->seedImage();

    $out = $this->invoke(['source' => $seed['token']]);
    $payload = json_decode($out, TRUE);
    $this->assertSame('alt_text', $payload['payload']['mode'] ?? NULL, $out);
  }

  public function testRejectsMissingSource(): void {
    $this->bindVisionRole();
    $out = $this->invoke(['source' => '   ']);
    $this->assertStringStartsWith('Error:', $out);
  }

  public function testRejectsUnresolvableSource(): void {
    $this->bindVisionRole();
    $out = $this->invoke(['source' => 'media:99999']);
    $this->assertStringStartsWith('Error:', $out);
  }

  public function testRefusesWithoutPermission(): void {
    $this->bindVisionRole();
    $seed = $this->seedImage();
    $this->setUpCurrentUser();
    $out = $this->invoke(['source' => $seed['token']]);
    $this->assertStringStartsWith('Error:', $out);
    $this->assertStringContainsString('permission', $out);
  }

}
