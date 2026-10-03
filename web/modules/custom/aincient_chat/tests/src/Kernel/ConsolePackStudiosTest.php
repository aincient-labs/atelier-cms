<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_chat\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\aincient_chat\Controller\ConsoleController;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The server half of the pack-studio mount boundary (DECISIONS 0448).
 *
 * A pack studio's rail is a built script our console build never saw, so the
 * shell has to tell the console where it is. The rules that matter: only a
 * user who may enter the studio, while it is switched on, gets its URL; the
 * URL carries the asset cache buster; and a built-in studio (an `entry`, not a
 * `script`) is never listed.
 *
 * Exercised through the fixture `aincient_pack_studio_test` — a manifest with
 * `ui.script` + `ui.style` — beside the built-in Components studio.
 *
 * @group aincient
 * @covers \Drupal\aincient_chat\Controller\ConsoleController
 * @covers \Drupal\aincient_chat\Studio\StudioBase
 */
#[RunTestsInSeparateProcesses]
final class ConsolePackStudiosTest extends KernelTestBase {

  use UserCreationTrait;

  private const ID = 'aincient_pack_studio_test';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'node',
    'key',
    'aincient_core',
    'workflows',
    'content_moderation',
    'aincient_pages',
    'aincient_chat',
    'aincient_studio_components',
    'aincient_pack_studio_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    // Burn uid 1: the superuser holds every permission, so it would make the
    // outsider case pass vacuously.
    $this->createUser([]);
  }

  /**
   * The manifest's `ui` reads come through the studio plugin.
   */
  public function testManifestReadsThrough(): void {
    $studio = $this->container->get('plugin.manager.aincient.studios')->get(self::ID);
    $this->assertNotNull($studio, 'A valid pack-studio manifest is discovered.');
    $this->assertSame('studio/studio.js', $studio->uiScript());
    $this->assertSame('studio/studio.css', $studio->uiStyle());
    $this->assertNull($studio->uiEntry());
    $this->assertSame('Leads', $studio->uiName());
    $this->assertSame('blocks', $studio->uiIcon());

    // What pack-validate grades the pack's own manifest with.
    $manager = $this->container->get('plugin.manager.aincient.studios');
    $this->assertSame([self::ID => []], $manager->manifestErrors(self::ID));
    $this->assertSame([], $manager->manifestErrors('aincient_chat'), 'No manifest, nothing to grade.');
  }

  /**
   * A member gets the pack studio's script and style, cache-busted; the
   * built-in Components studio is not listed.
   */
  public function testMemberGetsTheScript(): void {
    $this->setCurrentUser($this->createUser([
      'use aincient studio ' . self::ID,
      'use aincient studio components',
    ]));
    $pack = $this->packStudios();

    $this->assertSame([self::ID], array_keys($pack), 'Only the pack studio; Components ships an entry, not a script.');
    $this->assertSame('Leads', $pack[self::ID]['name']);
    $this->assertSame('blocks', $pack[self::ID]['icon']);
    $query = '?v=' . $this->container->get('asset.query_string')->get();
    $this->assertStringEndsWith('/aincient_pack_studio_test/studio/studio.js' . $query, $pack[self::ID]['script']);
    $this->assertStringEndsWith('/aincient_pack_studio_test/studio/studio.css' . $query, $pack[self::ID]['style']);
    $this->assertStringStartsWith('/', $pack[self::ID]['script'], 'Root-relative, so it is same-origin wherever the site lives.');
  }

  /**
   * No permission, no URL — and switched off, no URL even with it.
   */
  public function testGatedLikeStudioAccess(): void {
    $this->setCurrentUser($this->createUser([]));
    $this->assertSame([], $this->packStudios(), 'An outsider never sees the script URL.');

    $this->setCurrentUser($this->createUser(['use aincient studio ' . self::ID]));
    $this->assertArrayHasKey(self::ID, $this->packStudios());
    $this->container->get('aincient_chat.studio_switch')->setEnabled(self::ID, FALSE);
    $this->assertSame([], $this->packStudios(), 'A switched-off pack studio is out of the shell.');
  }

  /**
   * The controller's private `packStudios()` builder.
   *
   * @return array<string, array<string, mixed>>
   */
  private function packStudios(): array {
    $reflection = new \ReflectionMethod(ConsoleController::class, 'packStudios');
    return (array) $reflection->invoke(ConsoleController::create($this->container));
  }

}
