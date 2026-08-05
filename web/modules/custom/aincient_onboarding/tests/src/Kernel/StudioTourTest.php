<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_onboarding\Kernel;

use Drupal\aincient_core\Capability\ExecutableCapabilityInterface;
use Drupal\aincient_core\Capability\CapabilityManager;
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
  private function tour(): ExecutableCapabilityInterface {
    $manager = $this->container->get('plugin.manager.aincient.capabilities');
    assert($manager instanceof CapabilityManager);
    $plugin = $manager->createInstance('aincient_onboarding:studio_tour');
    assert($plugin instanceof ExecutableCapabilityInterface);
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
   * Naming ONE room narrows the map to it — the handoff case.
   */
  public function testRoomsArgumentNarrowsToOneRoom(): void {
    $this->setCurrentUser($this->createUser(['use aincient operator console']));

    $plugin = $this->tour();
    $plugin->setContextValue('rooms', ['content']);
    $plugin->execute();
    $envelope = json_decode($plugin->getReadableOutput(), TRUE);

    $this->assertSame(['content'], array_column($envelope['payload']['rooms'], 'key'));
    // Still a status line: the one-card render is the same contract, not a
    // second widget shape.
    $this->assertArrayHasKey('status', $envelope['payload']['rooms'][0]);
    $this->assertSame('studio_tour', $envelope['__widget__']);
  }

  /**
   * A bare string (a looser provider, or a string port) is coerced to a list.
   */
  public function testRoomsArgumentAcceptsAStringOrJson(): void {
    $this->setCurrentUser($this->createUser(['use aincient operator console']));

    foreach (['globals', '["globals"]'] as $arg) {
      $plugin = $this->tour();
      $plugin->setContextValue('rooms', $arg);
      $plugin->execute();
      $envelope = json_decode($plugin->getReadableOutput(), TRUE);
      $this->assertSame(['globals'], array_column($envelope['payload']['rooms'], 'key'), $arg);
    }
  }

  /**
   * The display names a model reaches for resolve to room keys; a subset keeps
   * the canonical display order rather than the order the model listed.
   */
  public function testRoomsArgumentAcceptsDisplayNamesAndOrdersCanonically(): void {
    $this->setCurrentUser($this->createUser(['use aincient operator console']));

    $plugin = $this->tour();
    $plugin->setContextValue('rooms', ['Globals', 'Pages', 'Library']);
    $plugin->execute();
    $envelope = json_decode($plugin->getReadableOutput(), TRUE);

    $this->assertSame(
      ['content', 'media', 'globals'],
      array_column($envelope['payload']['rooms'], 'key')
    );
  }

  /**
   * Unknown keys are IGNORED; an argument naming nothing we know falls back to
   * the whole map (a full map is truthful, an empty widget is a dead end).
   */
  public function testUnknownRoomsAreIgnoredAndDegradeToTheWholeMap(): void {
    $this->setCurrentUser($this->createUser(['use aincient operator console']));

    // One good key beside two inventions: the good one wins, alone.
    $plugin = $this->tour();
    $plugin->setContextValue('rooms', ['content', 'kitchen', ['nested']]);
    $plugin->execute();
    $envelope = json_decode($plugin->getReadableOutput(), TRUE);
    $this->assertSame(['content'], array_column($envelope['payload']['rooms'], 'key'));

    // Nothing recognisable at all → all four, exactly as the no-argument call.
    $plugin = $this->tour();
    $plugin->setContextValue('rooms', ['kitchen', 'garage']);
    $plugin->execute();
    $envelope = json_decode($plugin->getReadableOutput(), TRUE);
    $this->assertSame(
      ['content', 'media', 'design_system', 'globals'],
      array_column($envelope['payload']['rooms'], 'key')
    );

    // An empty list is the same non-answer as omitting the argument.
    $plugin = $this->tour();
    $plugin->setContextValue('rooms', []);
    $plugin->execute();
    $envelope = json_decode($plugin->getReadableOutput(), TRUE);
    $this->assertCount(4, $envelope['payload']['rooms']);
  }

  /**
   * The `rooms` param is OPTIONAL and projects as an array in the tool schema —
   * without both, the model either can't omit it or can't fill it.
   */
  public function testRoomsParamIsOptionalAndProjectsAsAnArray(): void {
    $definitions = $this->tour()->getContextDefinitions();

    $this->assertArrayHasKey('rooms', $definitions);
    $this->assertFalse($definitions['rooms']->isRequired());
    $this->assertSame('list', $definitions['rooms']->getDataType());
    // The item shape several providers require for an array param.
    $this->assertSame(
      'string',
      $definitions['rooms']->getConstraints()['SimpleToolItems']['type'] ?? NULL
    );
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
