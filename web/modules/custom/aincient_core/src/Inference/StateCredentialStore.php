<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Inference;

use Aincient\Inference\CredentialStoreInterface;
use Drupal\Core\State\StateInterface;

/**
 * Stored provider credentials in Drupal State.
 *
 * The `aincient.<provider>_api_key` / `aincient.<provider>_endpoint` keys that
 * {@see \Drupal\aincient_onboarding\ProviderConnector} writes (and a headless
 * `drush state:set` install writes). The key names are permanent: the provider
 * id is the State key prefix a credential is stored under.
 */
final class StateCredentialStore implements CredentialStoreInterface {

  public function __construct(
    private readonly StateInterface $state,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function apiKey(string $providerId): string {
    return (string) $this->state->get('aincient.' . $providerId . '_api_key', '');
  }

  /**
   * {@inheritdoc}
   */
  public function endpoint(string $providerId): string {
    return (string) $this->state->get('aincient.' . $providerId . '_endpoint', '');
  }

}
