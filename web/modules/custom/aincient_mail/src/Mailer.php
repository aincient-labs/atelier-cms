<?php

declare(strict_types=1);

namespace Drupal\aincient_mail;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Utility\Error;
use Drupal\key\KeyRepositoryInterface;
use Symfony\Component\Mailer\Mailer as SymfonyMailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Sends mail through a transport built from aincient_mail.settings.
 *
 * The delivery half of the mail subsystem. It builds a native Symfony transport
 * from our own settings — resolving credentials from a Key entity (whose value
 * lives in Drupal State, never in config) — and sends a Symfony Email through
 * it. It deliberately does NOT read core's `system.mail:mailer_dsn`: that object
 * would carry the SMTP user/password in config, which `drush cex` would leak
 * into git. Keeping the transport config here is what lets `system.mail` stay
 * pristine (only `interface.default: atelier_mail`, no secrets).
 *
 * Three transports:
 * - `local`  → Day-1 default with no MTA in the appliance: log the message at
 *   `warning` (the "connect a transport" nudge) and drop it. NOT a real Symfony
 *   spool (that would mean async Messenger/queue delivery — out of scope).
 * - `smtp`   → `smtp://user:pass@host:port` from the SMTP fields.
 * - `api`    → a provider bridge DSN (e.g. `sendgrid+api://KEY@default`). Needs
 *   the matching `symfony/*-mailer` bridge package; an unsupported scheme fails
 *   cleanly and is logged, never fatal.
 *
 * The From defaults to `system.site` mail + name — which aincient_pages'
 * SiteInformationOverrider layers from the operator's brand identity — so sender
 * identity has a single source of truth (the Site information studio), and this
 * module is purely about delivery.
 */
final class Mailer {

  /**
   * The Key entity machine names our transport secrets are stored behind.
   */
  public const API_KEY_ENTITY = 'mail_default_key';
  public const SMTP_KEY_ENTITY = 'mail_smtp_password';

  /**
   * Last-resort From when neither the override nor system.site has an address.
   */
  private const FALLBACK_FROM = 'noreply@atelier.local';

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly KeyRepositoryInterface $keyRepository,
  ) {}

  /**
   * Send a pre-built Symfony Email.
   *
   * @param \Symfony\Component\Mime\Email $email
   *   The message. If it has no From, one is defaulted from system.site.
   * @param \Symfony\Component\Mailer\Transport\TransportInterface|null $transport
   *   An explicit transport to send through — used by the settings form's
   *   "send test" so an unsaved credential can be exercised. When NULL, the
   *   saved configuration decides (including the `local` log-and-drop path).
   *
   * @return bool
   *   TRUE if the message was handed to a transport (or intentionally dropped
   *   by `local`); FALSE if delivery threw. Never leaks an exception.
   */
  public function send(Email $email, ?TransportInterface $transport = NULL): bool {
    $this->applyDefaultFrom($email);

    $type = (string) ($this->settings()->get('transport_type') ?: 'local');
    if ($transport === NULL && $type === 'local') {
      $this->logger()->warning(
        'Email not delivered — no mail transport configured. To: @to, Subject: @subject. Connect a transport at /admin/config/aincient/mail.',
        ['@to' => $this->recipientList($email), '@subject' => $email->getSubject()],
      );
      return TRUE;
    }

    try {
      $transport ??= $this->buildTransport();
      (new SymfonyMailer($transport))->send($email);
      return TRUE;
    }
    catch (\Throwable $e) {
      Error::logException($this->logger(), $e);
      return FALSE;
    }
  }

  /**
   * Convenience: send a simple HTML notification.
   */
  public function sendNotification(string $to, string $subject, string $bodyHtml): bool {
    $email = (new Email())
      ->to($to)
      ->subject($subject)
      ->html($bodyHtml);
    return $this->send($email);
  }

  /**
   * Build a Symfony transport from the saved settings or raw override values.
   *
   * @param array<string, mixed>|null $raw
   *   Optional raw values (with already-resolved secrets) instead of the saved
   *   settings — the form passes the operator's just-typed, unsaved values here
   *   for the "send test" round-trip. Keys mirror {@see self::resolvedSettings()}.
   */
  public function buildTransport(?array $raw = NULL): TransportInterface {
    $v = $raw ?? $this->resolvedSettings();
    $type = (string) ($v['transport_type'] ?? 'local');
    return Transport::fromDsn($this->buildDsn($type, $v));
  }

  /**
   * The stored API key secret ('' if none) — for the form's test round-trip.
   */
  public function storedApiKey(): string {
    return $this->secret((string) $this->settings()->get('api_key'));
  }

  /**
   * The stored SMTP password secret ('' if none) — for the form's test round-trip.
   */
  public function storedSmtpPassword(): string {
    return $this->secret((string) $this->settings()->get('smtp_password'));
  }

  /**
   * The saved settings with Key-entity references resolved to their secrets.
   *
   * @return array<string, mixed>
   */
  private function resolvedSettings(): array {
    $c = $this->settings();
    return [
      'transport_type' => (string) ($c->get('transport_type') ?: 'local'),
      'api_provider' => (string) ($c->get('api_provider') ?: 'sendgrid'),
      'api_key' => $this->secret((string) $c->get('api_key')),
      'host' => (string) $c->get('host'),
      'port' => (int) $c->get('port'),
      'username' => (string) $c->get('username'),
      'smtp_password' => $this->secret((string) $c->get('smtp_password')),
      'encryption' => (string) ($c->get('encryption') ?: 'tls'),
    ];
  }

  /**
   * Build a Symfony transport DSN for a non-local transport type.
   *
   * @param array<string, mixed> $v
   *   Settings with resolved secrets (never Key machine names).
   */
  private function buildDsn(string $type, array $v): string {
    return match ($type) {
      'smtp' => $this->smtpDsn($v),
      'api' => $this->apiDsn($v),
      default => throw new \InvalidArgumentException(sprintf('No transport DSN for type "%s".', $type)),
    };
  }

  /**
   * @param array<string, mixed> $v
   */
  private function smtpDsn(array $v): string {
    $host = (string) ($v['host'] ?: 'localhost');
    $port = (int) ($v['port'] ?: 587);
    $user = (string) ($v['username'] ?? '');
    $pass = (string) ($v['smtp_password'] ?? '');
    // Symfony's EsmtpTransport negotiates STARTTLS/implicit TLS from the port,
    // so `encryption` is stored for future use but not encoded into the DSN.
    $auth = $user !== '' ? rawurlencode($user) . ':' . rawurlencode($pass) . '@' : '';
    return sprintf('smtp://%s%s:%d', $auth, $host, $port);
  }

  /**
   * @param array<string, mixed> $v
   */
  private function apiDsn(array $v): string {
    $key = rawurlencode((string) ($v['api_key'] ?? ''));
    return match ((string) ($v['api_provider'] ?? 'sendgrid')) {
      'postmark' => sprintf('postmark+api://%s@default', $key),
      default => sprintf('sendgrid+api://%s@default', $key),
    };
  }

  /**
   * Resolve a Key entity's machine name to its secret value ('' if unset).
   */
  private function secret(string $keyId): string {
    $keyId = trim($keyId);
    if ($keyId === '') {
      return '';
    }
    $key = $this->keyRepository->getKey($keyId);
    return $key !== NULL ? (string) $key->getKeyValue() : '';
  }

  /**
   * Default the From to the operator's site identity when the message has none.
   *
   * Symfony requires a From, but a fresh keyless install ships an empty
   * `site.mail`, so this falls back to a sentinel (and logs the nudge) rather
   * than throwing on the essential Day-1 mails.
   */
  private function applyDefaultFrom(Email $email): void {
    if ($email->getFrom() !== []) {
      return;
    }
    $site = $this->configFactory->get('system.site');
    $override = trim((string) ($this->settings()->get('from_override') ?? ''));
    $address = $override !== '' ? $override : trim((string) $site->get('mail'));
    $name = trim((string) $site->get('name'));

    if ($address === '') {
      $address = self::FALLBACK_FROM;
      $this->logger()->warning(
        'No sender address configured — falling back to @addr. Set one in Site information.',
        ['@addr' => $address],
      );
    }

    $email->from($name !== '' ? new Address($address, $name) : new Address($address));
  }

  /**
   * A human-readable recipient list for logging.
   */
  private function recipientList(Email $email): string {
    $addresses = array_map(
      static fn (Address $a): string => $a->getAddress(),
      $email->getTo(),
    );
    return $addresses === [] ? '(none)' : implode(', ', $addresses);
  }

  private function settings() {
    return $this->configFactory->get('aincient_mail.settings');
  }

  private function logger(): LoggerChannelInterface {
    return $this->loggerFactory->get('aincient_mail');
  }

}
