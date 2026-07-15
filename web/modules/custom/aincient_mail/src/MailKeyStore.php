<?php

declare(strict_types=1);

namespace Drupal\aincient_mail;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;

/**
 * Stores transport secrets the way the rest of Atelier stores credentials.
 *
 * A direct mirror of aincient_onboarding's ProviderConnector::storeApiKey():
 * the secret value goes into Drupal State (on the appliance's persistent
 * volume), a `key` entity with the `state` provider is created/updated to point
 * at that State key, and only the entity's machine name is written into
 * `aincient_mail.settings`. So `drush cex` captures at most
 * `api_key: mail_default_key`, never the secret itself.
 */
final class MailKeyStore {

  /**
   * State key → (config field, key entity id, human label) for each secret.
   */
  private const SECRETS = [
    'aincient.mail_api_key' => ['api_key', Mailer::API_KEY_ENTITY, 'Mail API key'],
    'aincient.mail_smtp_password' => ['smtp_password', Mailer::SMTP_KEY_ENTITY, 'Mail SMTP password'],
  ];

  public function __construct(
    private readonly StateInterface $state,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Store the API-provider key (SendGrid, Postmark, …).
   */
  public function storeApiKey(string $secret): void {
    $this->store('aincient.mail_api_key', $secret);
  }

  /**
   * Store the SMTP password.
   */
  public function storeSmtpPassword(string $secret): void {
    $this->store('aincient.mail_smtp_password', $secret);
  }

  /**
   * Whether an API key has already been stored.
   */
  public function hasApiKey(): bool {
    return trim((string) $this->state->get('aincient.mail_api_key', '')) !== '';
  }

  /**
   * Whether an SMTP password has already been stored.
   */
  public function hasSmtpPassword(): bool {
    return trim((string) $this->state->get('aincient.mail_smtp_password', '')) !== '';
  }

  /**
   * Write a secret to State + a `key` entity, and the entity name into config.
   */
  private function store(string $stateKey, string $secret): void {
    [$configField, $keyId, $label] = self::SECRETS[$stateKey];

    $this->state->set($stateKey, trim($secret));

    $storage = $this->entityTypeManager->getStorage('key');
    /** @var \Drupal\key\KeyInterface|null $entity */
    $entity = $storage->load($keyId);
    if ($entity === NULL) {
      $entity = $storage->create([
        'id' => $keyId,
        'label' => $label,
        'key_type' => 'authentication',
      ]);
    }
    $entity->set('key_provider', 'state');
    $entity->set('key_provider_settings', ['state_key' => $stateKey]);
    $entity->save();

    $this->configFactory->getEditable('aincient_mail.settings')
      ->set($configField, $keyId)
      ->save();
  }

}
