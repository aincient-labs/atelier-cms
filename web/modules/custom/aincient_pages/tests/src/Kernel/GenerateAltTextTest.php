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
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the generate_alt_text capability (the Media studio's vision rail).
 *
 * Uses drupal/ai's bundled `echoai` test provider (it implements ChatInterface)
 * as the vision model, so the whole tool path runs — vision role resolution →
 * chat call with the image attached → alt write → widget envelope — without a
 * live vision API key. echoai echoes its input, so the "alt text" it returns is
 * deterministic non-empty text, which is all this path needs to assert.
 *
 * @group aincient
 */
#[RunTestsInSeparateProcesses]
final class GenerateAltTextTest extends KernelTestBase {

  use UserCreationTrait;

  protected static $modules = [
    'system', 'user', 'field', 'text', 'file', 'image', 'media',
    'workflows', 'content_moderation', 'key', 'ai', 'ai_test',
    'aincient_core', 'aincient_pages',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    // echoai's chat() consults the ai_test mock-response store.
    $this->installEntitySchema('ai_mock_provider_result');
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
    $tool = $this->container->get('plugin.manager.ai.function_calls')
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

  /** Bind the vision role to the echoai test provider. */
  private function bindVisionRole(): void {
    $this->container->get('aincient_core.model_role_resolver')
      ->bind(ModelRoles::VISION, 'echoai', 'gpt-4o');
  }

  public function testSuggestsAltTextWithoutPersistingIt(): void {
    $this->bindVisionRole();
    // Seed with a known alt so we can assert the tool leaves it untouched.
    $seed = $this->seedImage('original alt');

    $out = $this->invoke(['source' => $seed['token']]);
    $payload = json_decode($out, TRUE);

    $this->assertSame('media_result', $payload['__widget__'] ?? NULL, $out);
    $this->assertSame('alt_text', $payload['payload']['mode']);
    $this->assertSame($seed['token'], $payload['payload']['source']);
    $this->assertNotEmpty($payload['payload']['alt_text']);
    // The suggestion carries the media id so the client can populate that item.
    $this->assertSame((string) $seed['id'], $payload['payload']['id']);
    // Crucially, NOTHING was written: the suggestion is staged into the editor for
    // the human to review + Save ("AI proposes, you approve"). The alt is unchanged.
    $media = Media::load($seed['id']);
    $this->assertSame('original alt', $media->get('field_media_image')->alt);
  }

  public function testWorksWithoutAnExplicitVisionBinding(): void {
    // No vision binding — resolve() falls back to the default chat model. With
    // the default op-type provider set to echoai, alt-text still works (the
    // "unset = use default chat model" contract).
    \Drupal::configFactory()->getEditable('ai.settings')
      ->set('default_providers.chat', ['provider_id' => 'echoai', 'model_id' => 'gpt-4o'])
      ->save();
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
