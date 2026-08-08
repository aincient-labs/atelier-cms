<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_chat\Kernel;

use Drupal\aincient_chat\Controller\AccountController;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Symfony\Component\HttpFoundation\File\UploadedFile;
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
    'image',
    'key',
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
    // The saved-file path in saveAvatarFile() creates a File entity, whose
    // save() unconditionally checks file_usage — needed even though no
    // upload path here actually registers usage.
    $this->installSchema('file', ['file_usage']);
    // The earned display name lives in user.data (study 02, Plate 15).
    $this->installSchema('user', ['users_data']);
    $this->installConfig(['user']);
    // The avatar upload endpoint targets the standard profile's `user_picture`
    // image field, which a bare kernel install doesn't ship — install it the
    // same way profiles/standard/config/install does (field.storage +
    // field.field), so `$account->hasField('user_picture')` is true.
    FieldStorageConfig::create([
      'field_name' => 'user_picture',
      'entity_type' => 'user',
      'type' => 'image',
      'settings' => ['uri_scheme' => 'public'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'user_picture',
      'entity_type' => 'user',
      'bundle' => 'user',
      'label' => 'Picture',
      'settings' => [
        'file_directory' => 'pictures',
        'file_extensions' => 'png gif jpg jpeg webp',
        'max_filesize' => '',
        'max_resolution' => '',
        'min_resolution' => '',
      ],
    ])->save();
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
   * A POST /atelier/account call as the current user, decoded to [status, body].
   *
   * @return array{0: int, 1: array}
   */
  private function save(array $payload): array {
    $request = Request::create('/atelier/account', 'POST', [], [], [], [], json_encode($payload));
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
   * A valid image upload succeeds and is re-encoded through GD: a `.jpeg`
   * source lands as a canonical `.jpg` on disk (proves re-encoding ran, not
   * a byte-for-byte copy of the upload).
   */
  public function testAvatarUploadSucceedsAndReencodes(): void {
    // 'access content' is what EntityReferenceSupportsValidReferencesConstraint
    // needs to view the newly-saved public file when validating the
    // user_picture reference — not a real product requirement, just what the
    // public file-access handler checks.
    $account = $this->createUser(['access content'], 'ripley', FALSE);
    $this->setCurrentUser($account);

    $path = $this->createTempImage('jpeg');
    $upload = new UploadedFile($path, 'me.jpeg', 'image/jpeg', NULL, TRUE);
    $request = new Request([], [], [], [], ['file' => $upload]);

    $response = $this->controller()->uploadAvatar($request);
    $body = json_decode($response->getContent(), TRUE);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertTrue($body['ok']);
    $this->assertNotEmpty($body['avatarUrl']);

    $reloaded = $this->reloadUser((int) $account->id());
    $file = \Drupal\file\Entity\File::load($reloaded->get('user_picture')->target_id);
    $this->assertNotNull($file, 'The uploaded avatar was saved as a managed file.');
    $this->assertSame('jpg', pathinfo($file->getFileUri(), PATHINFO_EXTENSION), 'A .jpeg source is re-encoded to the canonical .jpg extension.');

    unlink($path);
  }

  /**
   * A non-image upload is rejected with a per-field 422, never stored.
   */
  public function testAvatarUploadRejectsNonImage(): void {
    $account = $this->createUser([], 'weyland', FALSE);
    $this->setCurrentUser($account);

    $path = $this->fileSystemTempFile('png');
    file_put_contents($path, 'this is not an image');
    $upload = new UploadedFile($path, 'fake.png', 'image/png', NULL, TRUE);
    $request = new Request([], [], [], [], ['file' => $upload]);

    $response = $this->controller()->uploadAvatar($request);
    $body = json_decode($response->getContent(), TRUE);

    $this->assertSame(422, $response->getStatusCode());
    $this->assertArrayHasKey('avatar', $body['errors']);
    $this->assertNotEmpty($body['errors']['avatar']);

    $reloaded = $this->reloadUser((int) $account->id());
    $this->assertTrue($reloaded->get('user_picture')->isEmpty(), 'A rejected upload never lands on the account.');

    unlink($path);
  }

  /**
   * No multipart `file` at all is a 400, not a validator error.
   */
  public function testAvatarUploadMissingFileIs400(): void {
    $account = $this->createUser([], 'bishop', FALSE);
    $this->setCurrentUser($account);

    $response = $this->controller()->uploadAvatar(new Request());

    $this->assertSame(400, $response->getStatusCode());
  }

  /**
   * A site with no `user_picture` field on the account is a 404, not a crash.
   */
  public function testAvatarUploadNoFieldIs404(): void {
    FieldConfig::loadByName('user', 'user', 'user_picture')->delete();

    $account = $this->createUser([], 'hicks', FALSE);
    $this->setCurrentUser($account);

    $response = $this->controller()->uploadAvatar(new Request());

    $this->assertSame(404, $response->getStatusCode());
  }

  /**
   * Builds a real, valid image on disk via GD and returns its path.
   */
  private function createTempImage(string $format): string {
    $path = $this->fileSystemTempFile($format);
    $im = imagecreatetruecolor(16, 16);
    imagejpeg($im, $path);
    imagedestroy($im);
    return $path;
  }

  /**
   * A throwaway temp file path with the given extension.
   */
  private function fileSystemTempFile(string $extension): string {
    return sys_get_temp_dir() . '/' . uniqid('avatar-test-', TRUE) . '.' . $extension;
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
