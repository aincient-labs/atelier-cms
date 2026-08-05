<?php

declare(strict_types=1);

namespace Drupal\aincient_gateway_test;

use Drupal\aincient_core\Inference\Exception\ProviderConfigurationException;
use Drupal\aincient_core\Inference\ProviderAdapterInterface;
use Symfony\AI\Platform\PlatformInterface;

/**
 * A connectable provider that chats and cannot draw — the gateway shape.
 *
 * WHY THIS EXISTS. The install that reported issue #12 had connected exactly one
 * thing: a LiteLLM proxy, through `openai_compatible`. Chat worked, and the image
 * role offered nothing with no way to fix it from the wizard. Reproducing that
 * state needs a provider that is CONNECTED, lists chat models without a network,
 * and is not image-capable — which no existing test adapter is
 * ({@see \Drupal\aincient_inference_test\ScriptedAdapter} draws, by design).
 *
 * Deliberately reports `isProxy() === FALSE` and `AUTH_KEY_ENDPOINT`, because that
 * is what a LiteLLM endpoint reached as `openai_compatible` really reports — which
 * is exactly why the console's copy cannot be keyed off `isProxy()`.
 */
final class GatewayAdapter implements ProviderAdapterInterface {

  /**
   * The provider id tests connect.
   */
  public const PROVIDER_ID = 'gateway_test';

  /**
   * The State key holding this provider's credential (the real convention).
   */
  public const CREDENTIAL_KEY = 'aincient.gateway_test_api_key';

  /**
   * The State key holding this provider's base URL (the real convention).
   *
   * An AUTH_KEY_ENDPOINT provider counts as connected only when BOTH are stored,
   * so a test that connects this one has to store both — exactly as the wizard's
   * two-field connect step does.
   */
  public const ENDPOINT_KEY = 'aincient.gateway_test_endpoint';

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return self::PROVIDER_ID;
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return 'Gateway test endpoint';
  }

  /**
   * {@inheritdoc}
   */
  public function description(): string {
    return 'Forwards chat to whatever the endpoint serves. Cannot make pictures.';
  }

  /**
   * {@inheritdoc}
   */
  public function servesChat(): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function authShape(): string {
    return self::AUTH_KEY_ENDPOINT;
  }

  /**
   * {@inheritdoc}
   */
  public function isProxy(): bool {
    // FALSE on purpose — see the class docblock. A real LiteLLM endpoint reached
    // through `openai_compatible` answers the same way.
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function translateOptions(array $options): array {
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function createPlatform(string $credential, string $endpoint = ''): PlatformInterface {
    // Nothing in the tests this serves takes a turn — the point is what the
    // console OFFERS. Failing loudly beats returning a platform that would answer
    // nothing.
    throw new ProviderConfigurationException('The gateway test adapter serves pickers, never turns.');
  }

  /**
   * {@inheritdoc}
   */
  public function listChatModels(string $credential, string $endpoint = ''): array {
    return trim($credential) === '' ? [] : ['gateway-chat' => 'Gateway chat'];
  }

}
