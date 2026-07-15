<?php

declare(strict_types=1);

namespace Drupal\aincient_mail\Plugin\Mail;

use Drupal\aincient_mail\Mailer;
use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Mail\Attribute\Mail;
use Drupal\Core\Mail\MailFormatHelper;
use Drupal\Core\Mail\MailInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Mime\Email;

/**
 * The system-wide mail backend for Atelier, on top of the Symfony mailer.
 *
 * Registered as `system.mail:interface.default` by aincient_mail_install(), so
 * EVERY email Drupal already sends — password resets, account activation,
 * update notices — is transparently converted into a Symfony Email and handed
 * to {@see \Drupal\aincient_mail\Mailer} for delivery, with zero changes to any
 * caller. Without this plugin the mailer service would be an island that core
 * never calls.
 *
 * This is NOT the legacy PHP `mail()` path and NOT the `symfony_mailer` contrib
 * module: it is a thin plugin we own. `format()`/`mail()` mirror core's
 * experimental `symfony_mailer` plugin (GPL) — the difference is where the
 * transport comes from: our Mailer builds it from `aincient_mail.settings` with
 * secrets resolved from Key/State, so nothing lands in `system.mail` config.
 */
#[Mail(
  id: 'atelier_mail',
  label: new TranslatableMarkup('Atelier mail'),
)]
final class AtelierMail implements MailInterface, ContainerFactoryPluginInterface {

  /**
   * Headers that may carry multiple comma-separated addresses.
   *
   * @see \Symfony\Component\Mime\Header\Headers::HEADER_CLASS_MAP
   */
  private const MAILBOX_LIST_HEADERS = ['from', 'to', 'reply-to', 'cc', 'bcc'];

  /**
   * Headers Symfony sets from the body itself — never copy these across.
   */
  private const SKIP_HEADERS = ['content-type', 'content-transfer-encoding'];

  public function __construct(
    private readonly Mailer $mailer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($container->get('aincient_mail.mailer'));
  }

  /**
   * {@inheritdoc}
   */
  public function format(array $message): array {
    foreach ($message['body'] as &$part) {
      $part = $part instanceof MarkupInterface
        ? MailFormatHelper::htmlToText($part)
        : MailFormatHelper::wrapMail($part);
    }
    unset($part);

    $message['body'] = implode("\n\n", $message['body']);

    return $message;
  }

  /**
   * {@inheritdoc}
   */
  public function mail(array $message): bool {
    $email = new Email();

    $headers = $email->getHeaders();
    foreach ($message['headers'] as $name => $value) {
      if (in_array(strtolower((string) $name), self::SKIP_HEADERS, TRUE)) {
        continue;
      }
      if (in_array(strtolower((string) $name), self::MAILBOX_LIST_HEADERS, TRUE)) {
        // Split on commas, ignoring commas inside double quotes.
        $value = str_getcsv((string) $value, escape: '\\');
      }
      $headers->addHeader($name, $value);
    }

    $recipients = array_map(trim(...), str_getcsv((string) $message['to'], escape: '\\'));

    $email
      ->to(...$recipients)
      ->subject((string) $message['subject'])
      ->text((string) $message['body']);

    return $this->mailer->send($email);
  }

}
