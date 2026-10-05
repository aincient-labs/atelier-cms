<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_mail\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Form\UserLoginForm;
use Drupal\user\Form\UserPasswordForm;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The sign-in and reset forms explain recovery while mail can't be delivered.
 *
 * @group aincient
 * @covers \Drupal\aincient_mail\Hook\PasswordRecoveryHint
 */
#[RunTestsInSeparateProcesses]
final class PasswordRecoveryHintTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'key', 'aincient_mail'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['system', 'user', 'aincient_mail']);
  }

  /**
   * On the Day-1 `local` transport: a note on sign-in, no send on reset.
   */
  public function testLocalTransportExplainsRecovery(): void {
    $login = $this->container->get('form_builder')->getForm(UserLoginForm::class);
    $this->assertArrayHasKey('ain_password_recovery', $login);
    $this->assertContains('config:aincient_mail.settings', $login['#cache']['tags']);

    $reset = $this->container->get('form_builder')->getForm(UserPasswordForm::class);
    $this->assertArrayHasKey('ain_password_recovery', $reset);
    $this->assertFalse($reset['name']['#access']);
    $this->assertFalse($reset['actions']['#access']);
  }

  /**
   * With a real transport the core forms are untouched.
   */
  public function testRealTransportLeavesFormsAlone(): void {
    $this->config('aincient_mail.settings')->set('transport_type', 'smtp')->save();

    $login = $this->container->get('form_builder')->getForm(UserLoginForm::class);
    $this->assertArrayNotHasKey('ain_password_recovery', $login);

    $reset = $this->container->get('form_builder')->getForm(UserPasswordForm::class);
    $this->assertArrayNotHasKey('ain_password_recovery', $reset);
    $this->assertNotFalse($reset['name']['#access'] ?? TRUE);
  }

}
