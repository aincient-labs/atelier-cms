<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_chat\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\aincient_chat\Controller\ConsoleController;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\Role;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The console account flyout's identity payload.
 *
 * Covers the two behaviours that keep the operator off Drupal's /user/N page:
 * the `viewer` card carries only AIncient-meaningful roles (never the locked
 * "Authenticated user"), and the account menu drops the "My account"
 * (user.page) link the card supersedes.
 *
 * @group aincient
 * @covers \Drupal\aincient_chat\Controller\ConsoleController
 */
#[RunTestsInSeparateProcesses]
final class ConsoleViewerTest extends KernelTestBase {

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
    // The earned display name lives in user.data (study 02, Plate 14).
    $this->installSchema('user', ['users_data']);
    $this->installConfig(['user']);
  }

  /**
   * The reflected private helper of a container-resolved controller.
   */
  private function invokePrivate(string $method): mixed {
    $controller = $this->container->get('class_resolver')
      ->getInstanceFromDefinition(ConsoleController::class);
    $ref = new \ReflectionMethod($controller, $method);
    $ref->setAccessible(TRUE);
    return $ref->invoke($controller);
  }

  /**
   * `viewer.roles` surfaces the assigned AIncient role, never "Authenticated".
   */
  public function testViewerRolesExcludeLockedRole(): void {
    Role::create(['id' => 'content_editor', 'label' => 'Content editor'])->save();
    $account = $this->createUser([], 'jo', FALSE, ['roles' => ['content_editor']]);
    $this->setCurrentUser($account);

    $viewer = $this->invokePrivate('viewer');

    // Names are EARNED (study 02, Plate 14): the machine username never
    // appears in chrome — with no offered name, `name` is empty and the email
    // leads (the initial follows the email).
    $this->assertSame('', $viewer['name']);
    $this->assertSame(['Content editor'], $viewer['roles']);
    $this->assertNotContains('Authenticated user', $viewer['roles']);
    $this->assertArrayNotHasKey('status', $viewer, 'The can-only-say-one-thing status pill is retired.');
    $this->assertArrayNotHasKey('memberFor', $viewer, 'Tenure arithmetic is retired.');
    $this->assertNotEmpty($viewer['since']);
    $this->assertSame(mb_strtoupper(mb_substr((string) $viewer['email'], 0, 1)), $viewer['initial']);
  }

  /**
   * An offered display name leads the card; the administrator reads as Owner.
   */
  public function testViewerEarnedNameAndOwnerRole(): void {
    Role::create(['id' => 'administrator', 'label' => 'Administrator'])->save();
    $account = $this->createUser([], 'admin', FALSE, ['roles' => ['administrator']]);
    $this->setCurrentUser($account);
    $card = $this->container->get('aincient_chat.viewer_card');

    $this->assertSame('Shibin Das', $card->setDisplayName($account, '  Shibin   Das  '));
    $viewer = $this->invokePrivate('viewer');
    $this->assertSame('Shibin Das', $viewer['name']);
    $this->assertSame('S', $viewer['initial']);
    $this->assertSame(['Owner'], $viewer['roles'], 'Owner words, not "Administrator".');

    // A paste accident stores NO name rather than echoing it back.
    $this->assertSame('', $card->setDisplayName($account, 'me@example.com'));
    $this->assertSame('', $card->displayName($account));
  }

  /**
   * The account menu drops the "My account" (user.page) profile link.
   */
  public function testAccountMenuDropsMyAccount(): void {
    $account = $this->createUser();
    $this->setCurrentUser($account);

    $items = $this->invokePrivate('accountMenuItems');
    $urls = array_column($items, 'url');
    $titles = array_column($items, 'title');

    $this->assertNotContains('/user', $urls, 'The /user profile link is filtered out.');
    $this->assertNotContains('My account', $titles);
  }

}
