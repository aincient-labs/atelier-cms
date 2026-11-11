<?php

declare(strict_types=1);

namespace Aincient\Inference;

/**
 * Where a provider's STORED credential lives — the half the wizard writes.
 *
 * {@see PlatformRegistry} asks this after the environment
 * (`ATELIER_<PROVIDER>_API_KEY` / `_ENDPOINT`), which it reads itself and which
 * always wins. The Drupal implementation reads the `aincient.<provider>_api_key`
 * / `_endpoint` State keys; another host supplies its own store.
 */
interface CredentialStoreInterface {

  /**
   * The stored secret for a provider, or '' when none is stored.
   */
  public function apiKey(string $providerId): string;

  /**
   * The stored base URL for a provider, or '' for the vendor default.
   */
  public function endpoint(string $providerId): string;

}
