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
 * Tests the generate_image capability (the Media studio's Nano Banana rail).
 *
 * Runs on the scripted inference provider ({@see ScriptedInferenceTrait}) bound to
 * the `image` model role, so the whole tool path is exercised — role resolution →
 * capability check → platform invoke → result unpacking → createFromBytes →
 * widget envelope — without a live image API key.
 *
 * The scripted provider answers an image turn the way Gemini does: a
 * MultiPartResult carrying the model's chatter AND the image bytes. So these
 * tests also pin that the bytes survive that shape, which is the shape a
 * half-read result union silently loses.
 *
 * @group aincient
 */
#[RunTestsInSeparateProcesses]
final class GenerateImageTest extends KernelTestBase {

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

    // A user who may generate images.
    $this->setUpCurrentUser([], ['administer aincient pages']);
  }

  /** Run the tool with the given context, returning its readable output. */
  private function invoke(array $context): string {
    $tool = $this->container->get('plugin.manager.aincient.capabilities')
      ->createInstance('aincient_pages:generate_image');
    foreach ($context as $name => $value) {
      $tool->setContextValue($name, $value);
    }
    $tool->execute();    return $tool->getReadableOutput();
  }

  /** Bind the image role to the scripted image model. */
  private function bindImageRole(): void {
    $this->connectScriptedProvider();
    $this->bindScriptedRole(ModelRoles::IMAGE, 'scripted-image');
    $this->assertNotNull($this->container->get('aincient_core.model_role_resolver')->imageBinding());
  }

  /** Route the FAST role (the title call) to the scripted chat model. */
  private function useScriptedTitleCall(): void {
    $this->bindScriptedRole(ModelRoles::FAST);
  }

  /**
   * Script the title model's reply.
   *
   * Scripted by VALUE rather than by matching the request, because what the
   * product promises is that a usable reply becomes the item's title and alt — not
   * that a particular instruction string was assembled. (The old echoai fixture
   * matched on request equality, so it duplicated
   * {@see GenerateImage::madeNameAndAlt}'s prompt and had to be kept in step with
   * it.)
   */
  private function scriptTitleReply(string $title, string $alt): void {
    $this->scriptInferenceText((string) json_encode(['title' => $title, 'alt' => $alt]));
  }

  public function testMakesATitleAndAltFromTheTitleModel(): void {
    $this->bindImageRole();
    $this->useScriptedTitleCall();
    $prompt = 'a warm sunrise over terracotta rooftops';
    $this->scriptTitleReply('Golden rooftop morning', 'Sunlight spills across a cluster of clay rooftops at dawn.');

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
    // The title model answers with prose, not JSON → the tool must fall back to
    // the prompt-derived strings and still mint the image (generation never blocks
    // on titling).
    $this->useScriptedTitleCall();
    $this->scriptInferenceText('Sure! Here are some ideas for a title.');

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
    // The source really travelled: an edit is a prompt AND an image part. Asserted
    // because "edit" that silently generates from scratch is a plausible failure
    // that every other assertion here would pass.
    $this->assertContains('Image', $this->lastInferenceCall()['parts']);
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

  /**
   * A bound provider with no key stored is a DIFFERENT refusal from unbound.
   *
   * The remedies differ — connect a provider vs. bind a different model — and the
   * new backend can tell them apart because it reads the stored credential rather
   * than asking a plugin whether it feels usable. A keyless install must not offer
   * a rail that fails on first use.
   */
  public function testRefusesWhenTheImageProviderHasNoCredential(): void {
    // Bound, but deliberately never connected.
    $this->bindScriptedRole(ModelRoles::IMAGE, 'scripted-image');

    $out = $this->invoke(['prompt' => 'anything']);
    $this->assertStringStartsWith('Error:', $out);
    $this->assertStringNotContainsString('no image model', strtolower($out));
    $this->assertStringContainsString("can't generate images", $out);
  }

  /**
   * A provider that can draw but cannot EDIT gets the "describe it" remedy.
   *
   * The advice a NULL could never carry, and the reason the image status is three
   * values rather than a boolean.
   */
  public function testOffersToGenerateAfreshWhenTheProviderCannotEdit(): void {
    $this->bindImageRole();
    \Drupal::state()->set('aincient_inference_test.supports_editing', FALSE);
    $seed = $this->seedSourceImage();

    $out = $this->invoke(['prompt' => 'make it warmer', 'source' => $seed]);
    $this->assertStringStartsWith('Error:', $out);
    $this->assertStringContainsString("can't edit an existing image", $out);

    // Text→image on the very same provider still works: the refusal is about the
    // MODE, not the provider.
    $this->assertSame(
      'media_result',
      json_decode($this->invoke(['prompt' => 'a fresh sunrise']), TRUE)['__widget__'] ?? NULL,
    );
  }

  /**
   * A provider that fails mid-call says so — it never returns quietly.
   *
   * The DECISIONS 0278/0279 shape: a failed turn that reads as an empty success is
   * unactionable and undebuggable. The tool must show the human an error.
   */
  public function testSurfacesAProviderFailureAsAnError(): void {
    $this->bindImageRole();
    \Drupal::state()->set('aincient_inference_test.fail', TRUE);

    $out = $this->invoke(['prompt' => 'a warm sunrise']);
    $this->assertStringStartsWith('Error:', $out);
    $this->assertStringContainsString('scripted_test', $out);
  }

  /**
   * Seeds an image media item and returns its `media:<id>` token.
   */
  private function seedSourceImage(): string {
    $path = 'public://seed-source.png';
    file_put_contents($path, base64_decode(
      'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='
    ));
    $file = File::create(['uri' => $path, 'status' => 1]);
    $file->save();
    $media = Media::create([
      'bundle' => 'image',
      'name' => 'Seed source',
      'field_media_image' => ['target_id' => $file->id(), 'alt' => 'Seed alt'],
    ]);
    $media->save();
    return 'media:' . $media->id();
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
