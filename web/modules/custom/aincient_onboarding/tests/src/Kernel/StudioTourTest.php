<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_onboarding\Kernel;

use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\FunctionCallPluginManager;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the studio-tour capability (the `studio_tour` gen-UI widget emitter).
 *
 * The tour's status counts degrade to NULL/'' when an entity type isn't
 * installed (node/media are absent here), so the envelope contract — widget
 * name, room keys, optional video block, permission gate — is what's under
 * test, not the counting.
 *
 * @group aincient
 */
#[RunTestsInSeparateProcesses]
final class StudioTourTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'key',
    'ai',
    'aincient_core',
    // Defines `use aincient operator console` (the tour's gate).
    'aincient_chat',
    'aincient_onboarding',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['aincient_onboarding']);
    // Burn uid 1 so created users below are honest, permission-checked users.
    $this->createUser();
  }

  /**
   * Instantiates the capability under test.
   */
  private function tour(): ExecutableFunctionCallInterface {
    $manager = $this->container->get('plugin.manager.ai.function_calls');
    assert($manager instanceof FunctionCallPluginManager);
    $plugin = $manager->createInstance('aincient_onboarding:studio_tour');
    assert($plugin instanceof ExecutableFunctionCallInterface);
    return $plugin;
  }

  /**
   * A console user gets the envelope: widget name, room keys, no video.
   */
  public function testEmitsTourEnvelope(): void {
    $this->setCurrentUser($this->createUser(['use aincient operator console']));

    $plugin = $this->tour();
    $plugin->execute();
    $envelope = json_decode($plugin->getReadableOutput(), TRUE);

    $this->assertIsArray($envelope);
    $this->assertSame('studio_tour', $envelope['__widget__']);
    $this->assertNotSame('', (string) $envelope['summary']);
    $keys = array_column($envelope['payload']['rooms'], 'key');
    $this->assertSame(['content', 'media', 'design_system', 'globals'], $keys);
    $this->assertArrayNotHasKey('video', $envelope['payload']);
  }

  /**
   * A configured tour video rides along in the payload.
   */
  public function testVideoBlockFromSettings(): void {
    $this->setCurrentUser($this->createUser(['use aincient operator console']));
    $this->config('aincient_onboarding.settings')
      ->set('tour_video_url', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ')
      ->set('tour_video_title', 'Meet your studio')
      ->save();

    $plugin = $this->tour();
    $plugin->execute();
    $envelope = json_decode($plugin->getReadableOutput(), TRUE);

    $this->assertSame([
      'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
      'title' => 'Meet your studio',
    ], $envelope['payload']['video']);
  }

  /**
   * Without console access the tour refuses instead of leaking site stats.
   */
  public function testPermissionGate(): void {
    $this->setCurrentUser($this->createUser([]));

    $plugin = $this->tour();
    $plugin->execute();

    $this->assertStringStartsWith('Error:', $plugin->getReadableOutput());
  }

}
