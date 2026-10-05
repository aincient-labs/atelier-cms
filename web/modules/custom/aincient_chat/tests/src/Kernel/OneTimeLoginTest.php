<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_chat\Kernel;

use Drupal\aincient_chat\Controller\OneTimeLoginController;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\OneTimeAuthentication;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * One-time login links opened in a browser that is already signed in.
 *
 * Core 403s `user.reset.login` for any signed-in user; the manager's "Open
 * console" link hit that whenever the browser already had a session.
 *
 * @group aincient
 * @covers \Drupal\aincient_chat\Controller\OneTimeLoginController
 * @covers \Drupal\aincient_chat\Routing\OneTimeLoginRouteSubscriber
 */
#[RunTestsInSeparateProcesses]
final class OneTimeLoginTest extends KernelTestBase {

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
    $this->installConfig(['user']);
    // Burn uid 1 (the superuser) so the users below are ordinary.
    $this->createUser();
  }

  private function controller(): OneTimeLoginController {
    return $this->container->get('class_resolver')
      ->getInstanceFromDefinition(OneTimeLoginController::class);
  }

  /**
   * The route admits signed-in users and is served by our controller.
   */
  public function testRouteIsOpenedToSignedInUsers(): void {
    $route = $this->container->get('router.route_provider')->getRouteByName('user.reset.login');
    $this->assertFalse($route->hasRequirement('_user_is_logged_in'));
    $this->assertSame(OneTimeLoginController::class . '::login', $route->getDefault('_controller'));
  }

  /**
   * The link's own user goes straight on, without consuming it.
   */
  public function testSameUserIsRedirected(): void {
    $user = $this->createUser(['use aincient operator console']);
    $this->setCurrentUser($user);

    $response = $this->controller()->login((string) $user->id(), (string) time(), 'irrelevant', Request::create('/'));
    $this->assertInstanceOf(RedirectResponse::class, $response);
    $this->assertStringEndsWith('/atelier', $response->getTargetUrl());
  }

  /**
   * Another user's valid link asks to switch; an invalid one never names them.
   */
  public function testOtherUserIsAskedToSwitch(): void {
    $current = $this->createUser([], 'alice');
    $target = $this->createUser([], 'bob');
    $this->setCurrentUser($current);
    // A link may not be from the future: stamp it at request time, not time().
    $timestamp = $this->container->get('datetime.time')->getRequestTime();
    $hash = $this->container->get(OneTimeAuthentication::class)->generateHmac($target, $timestamp);

    $build = $this->controller()->login((string) $target->id(), (string) $timestamp, $hash, Request::create('/'));
    $this->assertSame('aincient_one_time_login_switch', $build['form_id']['#value']);
    $this->assertStringContainsString('bob', (string) $build['message']['#value']);

    $build = $this->controller()->login((string) $target->id(), (string) $timestamp, 'forged', Request::create('/'));
    $this->assertArrayNotHasKey('#form_id', $build);
    $this->assertStringNotContainsString('bob', (string) $build['message']['#value'] . (string) $build['back']['#title']);
  }

}
