<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_chat\Kernel;

use Drupal\aincient_chat\Controller\AccountController;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Symfony\Component\HttpFoundation\Request;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The console's self-service "My Account" API (DECISIONS 0157, Tier 2).
 *
 * Covers the security contract that lets a React pane edit the signed-in user
 * without reimplementing Drupal's protections in JS: changing a protected field
 * (email/password) demands a server-verified current password for a normal
 * user, an admin may skip it, non-protected fields (timezone) never demand it,
 * and typed-data validation (email format) still gates the write.
 *
 * @group aincient
 * @covers \Drupal\aincient_chat\Controller\AccountController
 */
#[RunTestsInSeparateProcesses]
final class AccountControllerTest extends KernelTestBase {

  use UserCreationTrait;

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
    'file',
    'key',
    'ai',
    'aincient_core',
    'workflows',
    'content_moderation',
    'aincient_pages',
    'aincient_chat',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('user_role');
    $this->installEntitySchema('file');
    // The earned display name lives in user.data (study 02, Plate 15).
    $this->installSchema('user', ['users_data']);
    $this->installConfig(['user']);
    // Burn uid 1: the first user is the permission-bypassing superuser, which
    // would make every hasPermission() true and mask the current-password gate.
    // Subsequent createUser() calls then mint ordinary users.
    $this->createUser();
  }

  /**
   * Resolves the controller from the container (so `current_user` is honoured).
   */
  private function controller(): AccountController {
    return $this->container->get('class_resolver')
      ->getInstanceFromDefinition(AccountController::class);
  }

  /**
   * A POST /aincient/account call as the current user, decoded to [status, body].
   *
   * @return array{0: int, 1: array}
   */
  private function save(array $payload): array {
    $request = Request::create('/aincient/account', 'POST', [], [], [], [], json_encode($payload));
    $response = $this->controller()->save($request);
    return [$response->getStatusCode(), json_decode($response->getContent(), TRUE)];
  }

  /**
   * GET carries the editable snapshot and flags current-password for non-admins.
   */
  public function testGetSnapshot(): void {
    $account = $this->createUser([], 'replica', FALSE);
    $account->setEmail('replica@example.com')->save();
    $this->setCurrentUser($account);

    $data = json_decode($this->controller()->get()->getContent(), TRUE);

    $this->assertSame('replica@example.com', $data['mail']);
    $this->assertSame('', $data['name'], 'No earned name until the owner offers one.');
    $this->assertTrue($data['requiresCurrentPassword'], 'A normal user must re-auth for protected fields.');
    $this->assertNotEmpty($data['timezones'], 'Timezone options are offered.');
    $this->assertArrayHasKey('viewer', $data);
  }

  /**
   * The display name saves without re-auth (it's presentation, not credentials)
   * and is sanitized maître-d' style.
   */
  public function testDisplayNameSaves(): void {
    $account = $this->createUser([], 'machine', FALSE);
    $this->setCurrentUser($account);

    [$status, $body] = $this->save(['name' => '  Shibin  Das ']);
    $this->assertSame(200, $status);
    $this->assertSame('Shibin Das', $body['name']);
    $this->assertSame('Shibin Das', $body['viewer']['name']);

    // A paste accident (an email) stores NO name rather than echoing it back.
    [$status, $body] = $this->save(['name' => 'shibin@example.com']);
    $this->assertSame(200, $status);
    $this->assertSame('', $body['name']);

    // An explicit blank clears the name.
    [$status, $body] = $this->save(['name' => '']);
    $this->assertSame(200, $status);
    $this->assertSame('', $body['name']);
  }

  /**
   * A normal user cannot change email without the correct current password.
   */
  public function testEmailChangeRequiresCurrentPassword(): void {
    $account = $this->createUser([], 'gwen', FALSE);
    $account->setPassword('right-pass')->save();
    $this->setCurrentUser($account);

    // Missing current password.
    [$status, $body] = $this->save(['mail' => 'gwen@new.example.com']);
    $this->assertSame(422, $status);
    $this->assertArrayHasKey('currentPass', $body['errors']);

    // Wrong current password.
    [$status, $body] = $this->save(['mail' => 'gwen@new.example.com', 'currentPass' => 'wrong']);
    $this->assertSame(422, $status);
    $this->assertArrayHasKey('currentPass', $body['errors']);

    // Unchanged in storage.
    $reloaded = $this->reloadUser((int) $account->id());
    $this->assertNotSame('gwen@new.example.com', $reloaded->getEmail());
  }

  /**
   * With the correct current password, the email change goes through.
   */
  public function testEmailChangeWithCurrentPassword(): void {
    $account = $this->createUser([], 'peter', FALSE);
    $account->setPassword('right-pass')->save();
    $this->setCurrentUser($account);

    [$status, $body] = $this->save(['mail' => 'peter@new.example.com', 'currentPass' => 'right-pass']);

    $this->assertSame(200, $status);
    $this->assertTrue($body['ok']);
    $this->assertSame('peter@new.example.com', $this->reloadUser((int) $account->id())->getEmail());
  }

  /**
   * A malformed email is rejected by typed-data validation, keyed to `mail`.
   */
  public function testInvalidEmailRejected(): void {
    $account = $this->createUser([], 'mj', FALSE);
    $account->setPassword('right-pass')->save();
    $this->setCurrentUser($account);

    [$status, $body] = $this->save(['mail' => 'not-an-email', 'currentPass' => 'right-pass']);

    $this->assertSame(422, $status);
    $this->assertArrayHasKey('mail', $body['errors']);
  }

  /**
   * A password change re-authed with the current password takes effect.
   */
  public function testPasswordChange(): void {
    $account = $this->createUser([], 'harry', FALSE);
    $account->setPassword('old-pass')->save();
    $this->setCurrentUser($account);

    [$status] = $this->save(['newPass' => 'brand-new-pass', 'currentPass' => 'old-pass']);
    $this->assertSame(200, $status);

    $checker = $this->container->get('password');
    $hash = $this->reloadUser((int) $account->id())->getPassword();
    $this->assertTrue($checker->check('brand-new-pass', $hash), 'The new password verifies.');
    $this->assertFalse($checker->check('old-pass', $hash), 'The old password no longer verifies.');
  }

  /**
   * Timezone is not a protected field — it saves with no current password.
   */
  public function testTimezoneNeedsNoCurrentPassword(): void {
    $account = $this->createUser([], 'flash', FALSE);
    $this->setCurrentUser($account);

    [$status, $body] = $this->save(['timezone' => 'Europe/Berlin']);

    $this->assertSame(200, $status);
    $this->assertSame('Europe/Berlin', $body['timezone']);
    $this->assertSame('Europe/Berlin', $this->reloadUser((int) $account->id())->getTimeZone());
  }

  /**
   * A user with `administer users` may change email without a current password
   * (mirrors AccountForm), and GET reports the field as not required.
   */
  public function testAdminSkipsCurrentPassword(): void {
    $admin = $this->createUser(['administer users'], 'nick', FALSE);
    $this->setCurrentUser($admin);

    $data = json_decode($this->controller()->get()->getContent(), TRUE);
    $this->assertFalse($data['requiresCurrentPassword']);

    [$status, $body] = $this->save(['mail' => 'nick@shield.example.com']);
    $this->assertSame(200, $status);
    $this->assertTrue($body['ok']);
    $this->assertSame('nick@shield.example.com', $this->reloadUser((int) $admin->id())->getEmail());
  }

  /**
   * Loads a user fresh from storage (bypassing the entity cache).
   */
  private function reloadUser(int $uid): \Drupal\user\UserInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('user');
    $storage->resetCache([$uid]);
    return $storage->load($uid);
  }

}
