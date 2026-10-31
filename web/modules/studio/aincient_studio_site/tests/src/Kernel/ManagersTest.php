<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_studio_site\Kernel;

use Drupal\aincient_studio_site\Controller\ManagersController;
use Drupal\aincient_studio_site\Engine\EnginePairings;
use Drupal\aincient_studio_site\Engine\RevokeRefused;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpFoundation\Request;

/**
 * The Settings studio's Managers section (Manager Instances M4: m4-1, m4-3).
 *
 * The reader refuses anything but a small regular JSON list of typed
 * pairings; the revoke writes exactly `{"id":"…"}` to the inbox, atomically,
 * for a non-primary pairing that is in the list, and nothing else; both
 * routes are for holders of the restricted permission only. Paths point at a
 * temp dir through settings, so no engine volume is needed.
 *
 * @group aincient
 */
#[RunTestsInSeparateProcesses]
final class ManagersTest extends KernelTestBase {

  use UserCreationTrait;

  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'node',
    'key',
    'file',
    'image',
    'workflows',
    'content_moderation',
    'aincient_core',
    'aincient_pages',
    'aincient_export',
    'aincient_chat',
    'aincient_studio_site',
  ];

  private const OWNER = 'a1b2c3d4e5f6';
  private const PHONE = 'zz9900xxyyww';

  private string $tmpRoot;
  private string $outbox;
  private string $inbox;

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installSchema('system', ['sequences']);
    $this->tmpRoot = sys_get_temp_dir() . '/atelier-managers-' . bin2hex(random_bytes(6));
    $this->outbox = $this->tmpRoot . '/outbox';
    $this->inbox = $this->tmpRoot . '/inbox';
    mkdir($this->outbox, 0755, TRUE);
    mkdir($this->inbox, 0770, TRUE);
    $this->setSetting('atelier_engine_outbox', $this->outbox);
    $this->setSetting('atelier_engine_inbox', $this->inbox);
  }

  protected function tearDown(): void {
    exec('rm -rf ' . escapeshellarg($this->tmpRoot));
    parent::tearDown();
  }

  /**
   * The engine's own `PairingView` list, as `publish_pairings` writes it.
   */
  private function publish(?array $pairings = NULL): void {
    $pairings ??= [
      ['id' => self::OWNER, 'name' => 'Owner’s MacBook', 'created' => 1790000000, 'last_used' => 1790003600, 'via' => 'install code', 'primary' => TRUE],
      ['id' => self::PHONE, 'name' => '<script>alert(1)</script>', 'created' => 1790100000, 'last_used' => 1790100000, 'via' => 'approved by ' . self::OWNER, 'primary' => FALSE],
    ];
    file_put_contents($this->outbox . '/pairings.json', json_encode($pairings));
  }

  private function service(): EnginePairings {
    return $this->container->get('aincient_studio_site.engine_pairings');
  }

  public function testNoOutboxMeansNoEngine(): void {
    $this->setSetting('atelier_engine_outbox', $this->tmpRoot . '/missing');
    $this->assertFalse($this->service()->enabled());
    $this->assertSame(['state' => 'absent', 'pairings' => []], $this->service()->read());
  }

  public function testAnOutboxWithoutTheListIsPending(): void {
    $this->assertTrue($this->service()->enabled());
    $this->assertSame('pending', $this->service()->read()['state']);
  }

  public function testTheListIsReadWithTypedFields(): void {
    $this->publish();
    $read = $this->service()->read();
    $this->assertSame('ok', $read['state']);
    $this->assertCount(2, $read['pairings']);
    $this->assertSame([
      'id' => self::OWNER,
      'name' => 'Owner’s MacBook',
      'primary' => TRUE,
      'created' => 1790000000,
      'last_used' => 1790003600,
      'via' => 'install code',
    ], $read['pairings'][0]);
  }

  /**
   * Malformed, mistyped, oversized and non-regular files are all an honest
   * error, never a partial list.
   */
  public function testUnsafeOrMalformedListsAreRefused(): void {
    $path = $this->outbox . '/pairings.json';
    $cases = [
      'not json' => '{nope',
      'not a list' => '{"id":"a1b2c3d4e5f6"}',
      'bad id' => json_encode([['id' => 'ABC', 'name' => 'x', 'created' => 1, 'last_used' => 1, 'via' => '', 'primary' => FALSE]]),
      'id with u' => json_encode([['id' => 'uuuuuuuuuuuu', 'name' => 'x', 'created' => 1, 'last_used' => 1, 'via' => '', 'primary' => FALSE]]),
      'string primary' => json_encode([['id' => self::PHONE, 'name' => 'x', 'created' => 1, 'last_used' => 1, 'via' => '', 'primary' => 'yes']]),
      'string created' => json_encode([['id' => self::PHONE, 'name' => 'x', 'created' => '1', 'last_used' => 1, 'via' => '', 'primary' => FALSE]]),
      'empty name' => json_encode([['id' => self::PHONE, 'name' => '', 'created' => 1, 'last_used' => 1, 'via' => '', 'primary' => FALSE]]),
      'oversized' => '[' . str_repeat(' ', EnginePairings::MAX_BYTES) . ']',
    ];
    foreach ($cases as $label => $bytes) {
      file_put_contents($path, $bytes);
      $read = $this->service()->read();
      $this->assertSame('error', $read['state'], $label);
      $this->assertSame([], $read['pairings'], $label);
      $this->assertNotEmpty($read['error'], $label);
    }

    // A symlink, even to a valid list, is not read.
    unlink($path);
    $elsewhere = $this->tmpRoot . '/elsewhere.json';
    file_put_contents($elsewhere, '[]');
    symlink($elsewhere, $path);
    $read = $this->service()->read();
    $this->assertSame('error', $read['state']);
    $this->assertStringContainsString('not a regular file', $read['error']);

    // Nor a directory, nor a FIFO (which would otherwise block the request).
    unlink($path);
    mkdir($path);
    $this->assertSame('error', $this->service()->read()['state']);
    rmdir($path);
    if (function_exists('posix_mkfifo')) {
      posix_mkfifo($path, 0644);
      $this->assertSame('error', $this->service()->read()['state']);
    }

    // A symlinked outbox is not an engine.
    $this->setSetting('atelier_engine_outbox', $this->tmpRoot . '/linked');
    symlink($this->outbox, $this->tmpRoot . '/linked');
    $this->assertSame('absent', $this->service()->read()['state']);
  }

  /**
   * The revoke writes exactly the engine's request, atomically, and logs who.
   */
  public function testRevokeWritesTheExactRequestAndLogs(): void {
    $this->publish();
    $logger = new class extends AbstractLogger {

      /** @var list<array{string, string, array}> */
      public array $records = [];

      public function log($level, \Stringable|string $message, array $context = []): void {
        $this->records[] = [(string) $level, (string) $message, $context];
      }

    };
    $service = new EnginePairings($logger);
    $this->assertSame(['id' => self::PHONE, 'name' => '<script>alert(1)</script>'], $service->requestRevoke(self::PHONE, 'admin (uid 1)'));
    $this->assertSame('{"id":"' . self::PHONE . '"}', file_get_contents($this->inbox . '/revoke.request'));
    $this->assertSame(0660, fileperms($this->inbox . '/revoke.request') & 0777);
    // No temp file left behind; only the request.
    $this->assertSame(['revoke.request'], array_values(array_diff(scandir($this->inbox), ['.', '..'])));
    $this->assertCount(1, $logger->records);
    $this->assertSame('notice', $logger->records[0][0]);
    $this->assertSame('admin (uid 1)', $logger->records[0][2]['%user']);
    $this->assertSame(self::PHONE, $logger->records[0][2]['%id']);

    // A second revoke while the first is still waiting is refused, not lost.
    try {
      $service->requestRevoke(self::PHONE, 'admin (uid 1)');
      $this->fail('A pending request was overwritten.');
    }
    catch (RevokeRefused $e) {
      $this->assertSame(409, $e->status);
    }
  }

  public function testRevokeRefusesThePrimaryBadAndUnknownIds(): void {
    $this->publish();
    $cases = [
      [self::OWNER, 403],
      ['', 400],
      ['../../etc/passwd', 400],
      ['A1B2C3D4E5F6', 400],
      ['a1b2c3d4e5f6x', 400],
      ['bbbbbbbbbbbb', 404],
    ];
    foreach ($cases as [$id, $status]) {
      try {
        $this->service()->requestRevoke($id, 'admin');
        $this->fail("Revoke of '$id' was written.");
      }
      catch (RevokeRefused $e) {
        $this->assertSame($status, $e->status, $id);
      }
      $this->assertFileDoesNotExist($this->inbox . '/revoke.request', $id);
    }

    // With the list unreadable, nothing is written either.
    file_put_contents($this->outbox . '/pairings.json', '{nope');
    try {
      $this->service()->requestRevoke(self::PHONE, 'admin');
      $this->fail('Revoked against an unreadable list.');
    }
    catch (RevokeRefused $e) {
      $this->assertSame(409, $e->status);
    }
    $this->assertFileDoesNotExist($this->inbox . '/revoke.request');
  }

  /**
   * A planted symlink at the request path is replaced, never written through.
   */
  public function testRevokeNeverWritesThroughAPlantedLink(): void {
    $this->publish();
    $victim = $this->tmpRoot . '/victim';
    file_put_contents($victim, 'keep');
    symlink($victim, $this->inbox . '/revoke.request');
    try {
      $this->service()->requestRevoke(self::PHONE, 'admin');
      $this->fail('Wrote while something sat at the request path.');
    }
    catch (RevokeRefused $e) {
      $this->assertSame(409, $e->status);
    }
    $this->assertSame('keep', file_get_contents($victim));
  }

  /**
   * The controller answers in JSON and the routes are admin-only: the
   * Settings studio's permission alone is not enough, and the write needs the
   * CSRF header token.
   */
  public function testRoutesAndController(): void {
    $this->publish();
    $this->container->get('router.builder')->rebuild();
    $provider = $this->container->get('router.route_provider');
    $list = $provider->getRouteByName('aincient_studio_site.managers');
    $this->assertSame('/atelier/managers', $list->getPath());
    $this->assertSame(['GET'], $list->getMethods());
    $revoke = $provider->getRouteByName('aincient_studio_site.managers_revoke');
    $this->assertSame('/atelier/managers/revoke', $revoke->getPath());
    $this->assertSame(['POST'], $revoke->getMethods());
    $this->assertSame('TRUE', $revoke->getRequirement('_csrf_request_header_token'));
    foreach ([$list, $revoke] as $route) {
      $this->assertSame('use aincient studio settings,administer atelier managers', $route->getRequirement('_permission'));
      $this->assertSame('settings', $route->getRequirement('_aincient_studio_enabled'));
    }

    // uid 1 is created first so the users below are not the super user.
    $this->createUser();
    $access = $this->container->get('access_manager');
    $admin = $this->createUser(['use aincient studio settings', 'administer atelier managers']);
    $settings_only = $this->createUser(['use aincient studio settings']);
    $nobody = $this->createUser(['use aincient operator console']);
    foreach (['aincient_studio_site.managers', 'aincient_studio_site.managers_revoke'] as $name) {
      $this->assertTrue($access->checkNamedRoute($name, [], $admin), $name);
      $this->assertFalse($access->checkNamedRoute($name, [], $settings_only), $name);
      $this->assertFalse($access->checkNamedRoute($name, [], $nobody), $name);
    }

    $this->setCurrentUser($admin);
    $controller = ManagersController::create($this->container);
    $listed = json_decode((string) $controller->list()->getContent(), TRUE);
    $this->assertSame('ok', $listed['state']);
    // JSON-escaped for HTML contexts: the raw tag never appears in the body.
    $this->assertStringNotContainsString('<script>', (string) $controller->list()->getContent());
    $this->assertSame('<script>alert(1)</script>', $listed['pairings'][1]['name']);
    // No secret-shaped field ever leaves: the engine's view has none, and the
    // reader copies only the typed fields.
    $this->assertSame(['id', 'name', 'primary', 'created', 'last_used', 'via'], array_keys($listed['pairings'][0]));

    $refused = $controller->revoke(Request::create('/atelier/managers/revoke', 'POST', [], [], [], [], json_encode(['id' => self::OWNER])));
    $this->assertSame(403, $refused->getStatusCode());
    $this->assertFalse(json_decode((string) $refused->getContent(), TRUE)['ok']);
    $this->assertFileDoesNotExist($this->inbox . '/revoke.request');

    $done = $controller->revoke(Request::create('/atelier/managers/revoke', 'POST', [], [], [], [], json_encode(['id' => self::PHONE])));
    $this->assertSame(200, $done->getStatusCode());
    $this->assertTrue(json_decode((string) $done->getContent(), TRUE)['ok']);
    $this->assertSame('{"id":"' . self::PHONE . '"}', file_get_contents($this->inbox . '/revoke.request'));
  }

}
