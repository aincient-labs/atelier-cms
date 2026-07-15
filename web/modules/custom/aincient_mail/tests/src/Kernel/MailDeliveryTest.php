<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_mail\Kernel;

use Drupal\aincient_mail\Mailer;
use Drupal\aincient_mail\MailKeyStore;
use Drupal\KernelTests\KernelTestBase;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Email;

/**
 * @group aincient_mail
 *
 * @coversDefaultClass \Drupal\aincient_mail\Mailer
 */
final class MailDeliveryTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'key', 'aincient_mail'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system', 'aincient_mail']);
  }

  /**
   * The secret lands in State; only the key entity's name reaches config.
   */
  public function testSecretNeverTouchesConfig(): void {
    /** @var \Drupal\aincient_mail\MailKeyStore $store */
    $store = $this->container->get('aincient_mail.key_store');
    $store->storeApiKey('SG.super-secret-value');

    // Secret is in State.
    $this->assertSame('SG.super-secret-value', $this->container->get('state')->get('aincient.mail_api_key'));

    // Config holds only the key entity's machine name — never the secret.
    $config = $this->config('aincient_mail.settings');
    $this->assertSame('mail_default_key', $config->get('api_key'));
    $this->assertStringNotContainsString('super-secret-value', (string) json_encode($config->getRawData()));

    // The key entity is state-backed and points at our State key.
    $key = $this->container->get('key.repository')->getKey('mail_default_key');
    $this->assertNotNull($key);
    $this->assertSame('state', $key->getKeyProvider()->getPluginId());
    $this->assertSame('SG.super-secret-value', $key->getKeyValue());

    $this->assertTrue($store->hasApiKey());
  }

  /**
   * SMTP settings resolve to a real Symfony ESMTP transport.
   */
  public function testSmtpTransportBuilds(): void {
    $this->config('aincient_mail.settings')
      ->set('transport_type', 'smtp')
      ->set('host', 'smtp.example.com')
      ->set('port', 2525)
      ->set('username', 'mailer@example.com')
      ->save();
    $this->container->get('aincient_mail.key_store')->storeSmtpPassword('hunter2');

    /** @var \Drupal\aincient_mail\Mailer $mailer */
    $mailer = $this->container->get('aincient_mail.mailer');
    $transport = $mailer->buildTransport();

    $this->assertInstanceOf(EsmtpTransport::class, $transport);
  }

  /**
   * From defaults to system.site mail; empty falls back to the sentinel.
   *
   * @covers ::applyDefaultFrom
   */
  public function testFromDefaultsToSiteIdentity(): void {
    /** @var \Drupal\aincient_mail\Mailer $mailer */
    $mailer = $this->container->get('aincient_mail.mailer');
    $apply = (new \ReflectionClass($mailer))->getMethod('applyDefaultFrom');
    $apply->setAccessible(TRUE);

    // With a site mail set, that is the From.
    $this->config('system.site')->set('mail', 'ops@atelier.test')->set('name', 'Atelier')->save();
    $email = new Email();
    $apply->invoke($mailer, $email);
    $this->assertSame('ops@atelier.test', $email->getFrom()[0]->getAddress());
    $this->assertSame('Atelier', $email->getFrom()[0]->getName());

    // With no site mail, it falls back to the sentinel rather than throwing.
    $this->config('system.site')->set('mail', '')->save();
    $blank = new Email();
    $apply->invoke($mailer, $blank);
    $this->assertSame('noreply@atelier.local', $blank->getFrom()[0]->getAddress());
  }

  /**
   * An explicit From on the message is never overwritten by the default.
   */
  public function testExplicitFromWins(): void {
    $this->config('system.site')->set('mail', 'ops@atelier.test')->save();
    /** @var \Drupal\aincient_mail\Mailer $mailer */
    $mailer = $this->container->get('aincient_mail.mailer');
    $apply = (new \ReflectionClass($mailer))->getMethod('applyDefaultFrom');
    $apply->setAccessible(TRUE);

    $email = (new Email())->from('someone@else.test');
    $apply->invoke($mailer, $email);
    $this->assertSame('someone@else.test', $email->getFrom()[0]->getAddress());
  }

}
