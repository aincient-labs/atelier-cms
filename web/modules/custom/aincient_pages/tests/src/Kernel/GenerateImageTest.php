<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Kernel;

use Drupal\aincient_core\ModelRoles;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai_test\Entity\AIMockProviderResult;
use Drupal\Component\Serialization\Yaml;
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
 * Tests the generate_image capability (the Media studio's Nano Banana rail).
 *
 * Uses drupal/ai's bundled `echoai` test provider (it implements both
 * text_to_image and image_to_image) bound to the `image` model role, so the
 * whole tool path is exercised — role resolution → provider call → createFromBytes
 * → widget envelope — without a live image API key.
 *
 * @group aincient
 */
#[RunTestsInSeparateProcesses]
final class GenerateImageTest extends KernelTestBase {

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
    // The title call runs a chat turn through echoai, which consults the
    // ai_test mock-response store — install its schema so we can script a reply.
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

    // A user who may generate images.
    $this->setUpCurrentUser([], ['administer aincient pages']);
  }

  /** Run the tool with the given context, returning its readable output. */
  private function invoke(array $context): string {
    $tool = $this->container->get('plugin.manager.ai.function_calls')
      ->createInstance('aincient_pages:generate_image');
    foreach ($context as $name => $value) {
      $tool->setContextValue($name, $value);
    }
    $tool->execute();    return $tool->getReadableOutput();
  }

  /** Bind the image role to the echoai test provider. */
  private function bindImageRole(): void {
    $resolver = $this->container->get('aincient_core.model_role_resolver');
    $resolver->bind(ModelRoles::IMAGE, 'echoai', 'gpt-4o');
    $this->assertNotNull($resolver->imageBinding());
  }

  /** Route the FAST role (the title call) to echoai's default chat model. */
  private function useEchoForTitleCall(): void {
    \Drupal::configFactory()->getEditable('ai.settings')
      ->set('default_providers.chat', ['provider_id' => 'echoai', 'model_id' => 'gpt-4o'])
      ->save();
  }

  /**
   * Script echoai's chat reply for the title call fired by the given prompt.
   *
   * echoai matches a request by exact-equality on the ChatInput array, so we
   * rebuild the SAME input the tool builds — the instruction string here MUST
   * mirror {@see GenerateImage::madeNameAndAlt}; if it drifts, the match misses,
   * the tool falls back, and the made-title assertion fails loudly (not silently).
   */
  private function scriptTitleReply(string $prompt, string $title, string $alt): void {
    $instruction = 'A user generated an image from this prompt: "' . $prompt . '". '
      . 'Write a short human title and alt text for that image. '
      . 'Return ONLY strict JSON, no commentary: {"title": "...", "alt": "..."}. '
      . 'The title is at most 6 words, sentence case, naming the SUBJECT of the image — never the raw prompt text. '
      . 'The alt is one descriptive sentence of about 125 characters for an HTML alt attribute; do not begin with "image of".';
    $input = new ChatInput([new ChatMessage('user', $instruction)]);
    AIMockProviderResult::create([
      'label' => 'title-call',
      'operation_type' => 'chat',
      'mock_enabled' => TRUE,
      'request' => Yaml::encode($input->toArray()),
      'response' => Yaml::encode([
        'normalized' => ['role' => 'assistant', 'text' => (string) json_encode(['title' => $title, 'alt' => $alt])],
      ]),
      'sleep_time' => 0,
    ])->save();
  }

  public function testMakesATitleAndAltFromTheTitleModel(): void {
    $this->bindImageRole();
    $this->useEchoForTitleCall();
    $prompt = 'a warm sunrise over terracotta rooftops';
    $this->scriptTitleReply($prompt, 'Golden rooftop morning', 'Sunlight spills across a cluster of clay rooftops at dawn.');

    $out = $this->invoke(['prompt' => $prompt]);
    $payload = json_decode($out, TRUE);
    $this->assertSame('media_result', $payload['__widget__'] ?? NULL, $out);

    // The MADE title/alt land on the item — NOT the raw prompt (Law 11).
    $id = (int) explode(':', $payload['payload']['token'])[1];
    $media = Media::load($id);
    $this->assertSame('Golden rooftop morning', $media->label());
    $this->assertSame('Sunlight spills across a cluster of clay rooftops at dawn.', $media->get('field_media_image')->alt);
    $this->assertStringNotContainsString($prompt, $media->label());
    $this->assertStringNotContainsString($prompt, (string) $media->get('field_media_image')->alt);
  }

  public function testFallsBackToPromptWhenTitleReplyIsUnusable(): void {
    $this->bindImageRole();
    // echoai is the chat model but NO reply is scripted → it echoes "Hello world…",
    // which is not JSON → the tool must fall back to the prompt-derived strings and
    // still mint the image (generation never blocks on titling).
    $this->useEchoForTitleCall();

    $out = $this->invoke(['prompt' => 'a warm sunrise over terracotta rooftops']);
    $payload = json_decode($out, TRUE);
    $this->assertSame('media_result', $payload['__widget__'] ?? NULL, $out);

    $id = (int) explode(':', $payload['payload']['token'])[1];
    $media = Media::load($id);
    // Fallback: name/alt seeded from the prompt (today's behavior preserved).
    $this->assertStringContainsString('warm sunrise', $media->label());
    $this->assertInstanceOf(Media::class, $media);
  }

  public function testTextToImageMintsMediaAndReturnsWidget(): void {
    $this->bindImageRole();
    $out = $this->invoke(['prompt' => 'a warm sunrise over terracotta rooftops']);
    $payload = json_decode($out, TRUE);

    $this->assertSame('media_result', $payload['__widget__'] ?? NULL);
    $this->assertSame('generate', $payload['payload']['mode']);
    $this->assertNull($payload['payload']['source']);
    $this->assertMatchesRegularExpression('/^media:\d+$/', $payload['payload']['token']);
    // The alt/name seeded from the prompt; the bytes really landed.
    $this->assertStringContainsString('warm sunrise', $payload['payload']['alt']);
    $id = (int) explode(':', $payload['payload']['token'])[1];
    $this->assertInstanceOf(Media::class, Media::load($id));
    $this->assertGreaterThan(0, $payload['payload']['width']);
  }

  public function testImageToImageEditsAnExistingItem(): void {
    $this->bindImageRole();
    // Seed a source image to edit.
    $path = 'public://source.png';
    file_put_contents($path, base64_decode(
      'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='
    ));
    $file = File::create(['uri' => $path, 'status' => 1]);
    $file->save();
    $source = Media::create([
      'bundle' => 'image',
      'name' => 'Source',
      'field_media_image' => ['target_id' => $file->id(), 'alt' => 'Source alt'],
    ]);
    $source->save();

    $out = $this->invoke([
      'prompt' => 'make it warmer',
      'source' => 'media:' . $source->id(),
    ]);
    $payload = json_decode($out, TRUE);

    $this->assertSame('media_result', $payload['__widget__'] ?? NULL);
    $this->assertSame('edit', $payload['payload']['mode']);
    $this->assertSame('media:' . $source->id(), $payload['payload']['source']);
    // A NEW item — non-destructive: a different id from the source.
    $newId = (int) explode(':', $payload['payload']['token'])[1];
    $this->assertNotSame((int) $source->id(), $newId);
    $this->assertInstanceOf(Media::class, Media::load($newId));
  }

  public function testRejectsEmptyPrompt(): void {
    $this->bindImageRole();
    $out = $this->invoke(['prompt' => '   ']);
    $this->assertStringStartsWith('Error:', $out);
  }

  public function testErrorsWhenImageRoleUnbound(): void {
    // No binding → the tool refuses (the same state that keeps the rail dark).
    $out = $this->invoke(['prompt' => 'anything']);
    $this->assertStringStartsWith('Error:', $out);
    $this->assertStringContainsString('no image model', strtolower($out));
  }

  public function testRefusesWithoutPermission(): void {
    $this->bindImageRole();
    // Drop to a user without the capability.
    $this->setUpCurrentUser();
    $out = $this->invoke(['prompt' => 'anything']);
    $this->assertStringStartsWith('Error:', $out);
    $this->assertStringContainsString('permission', $out);
  }

}
