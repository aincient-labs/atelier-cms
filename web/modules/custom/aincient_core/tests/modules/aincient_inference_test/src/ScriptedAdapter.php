<?php

declare(strict_types=1);

namespace Drupal\aincient_inference_test;

use Drupal\aincient_core\Inference\Exception\ProviderConfigurationException;
use Drupal\aincient_core\Inference\ImageGenerationAdapterInterface;
use Drupal\Core\State\StateInterface;
use Symfony\AI\Platform\PlatformInterface;

/**
 * A provider whose answers a test writes into State.
 *
 * WHY A TEST ADAPTER AND NOT A MOCK. The capabilities under test
 * (`generate_image`, `generate_alt_text`, `propose_media_name`) reach the AI
 * through the container, so the only way to exercise the REAL path — role
 * resolution, the connected-credential check, the platform invoke, the result
 * union — is to be a real adapter registered by the real tag. These tests used
 * `drupal/ai`'s bundled `echoai` provider for the same reason; this is its
 * replacement on the new backend.
 *
 * It claims image capability so the image role can bind to it, and it is
 * "connected" exactly when a test stores a credential under the conventional
 * State key ({@see self::CREDENTIAL_KEY}) — the same chain a real provider uses,
 * so the unbound / unusable / ready distinction is testable rather than mocked
 * away.
 */
final class ScriptedAdapter implements ImageGenerationAdapterInterface {

  /**
   * The provider id tests bind roles to.
   */
  public const PROVIDER_ID = 'scripted_test';

  /**
   * The State key holding this provider's credential (the real convention).
   */
  public const CREDENTIAL_KEY = 'aincient.scripted_test_api_key';

  /**
   * The State key naming the ONLY credential enumeration accepts.
   *
   * Unset (the default) means any non-empty credential enumerates, which is what
   * the capability tests want — they care about the turn, not the key. A test that
   * is pinning credential VALIDATION sets this, and then a wrong key comes back
   * empty exactly as a live provider's 401 would. That is the behaviour onboarding
   * reads as "this key does not work", so it has to be expressible without a
   * network.
   */
  public const VALID_CREDENTIAL_KEY = 'aincient_inference_test.valid_credential';

  /**
   * The State key holding the text the platform should answer with.
   */
  public const TEXT_KEY = 'aincient_inference_test.text';

  /**
   * The State key holding the image bytes the platform should answer with.
   */
  public const IMAGE_KEY = 'aincient_inference_test.image';

  /**
   * The State key recording what the platform was last invoked with.
   */
  public const LAST_CALL_KEY = 'aincient_inference_test.last_call';

  /**
   * The State key holding the token usage the platform should report.
   *
   * UNSET BY DEFAULT, on purpose: a platform that files no `token_usage`
   * metadata is a real provider state (a streamed call, a bridge with no
   * extractor), and it is the state that must stay distinguishable from "the
   * call was free". Tests that pin the metering row set it; tests that don't
   * care get the honest silence.
   *
   * Shape: an array of nullable ints keyed `prompt`, `completion`, `thinking`,
   * `cache_read`, `cache_creation`, `total`.
   */
  public const USAGE_KEY = 'aincient_inference_test.usage';

  /**
   * The State key that, when TRUE, makes the platform fail like a live provider.
   */
  public const FAIL_KEY = 'aincient_inference_test.fail';

  /**
   * The State key holding the finish reason the platform reports, if any.
   *
   * UNSET BY DEFAULT for the same reason as {@see self::USAGE_KEY}: a provider
   * that reports no finish reason is a real state, and it is the state that must
   * keep behaving exactly as it did before truncation was detected at all.
   *
   * Shape: `['case' => <FinishReasonCase value>, 'raw' => <provider wording>]` —
   * stored as strings because State is serialised, and the platform stands in for
   * a bridge, which is the layer that does this mapping.
   */
  public const FINISH_REASON_KEY = 'aincient_inference_test.finish_reason';

  public function __construct(
    private readonly StateInterface $state,
  ) {}

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
    return 'Scripted test provider';
  }

  /**
   * {@inheritdoc}
   */
  public function description(): string {
    return 'Answers whatever the test wrote into State.';
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
    return self::AUTH_KEY;
  }

  /**
   * {@inheritdoc}
   */
  public function isProxy(): bool {
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
    if (trim($credential) === '') {
      throw new ProviderConfigurationException('No credential is stored for the scripted test provider.');
    }
    return new ScriptedPlatform($this->state);
  }

  /**
   * {@inheritdoc}
   */
  public function listChatModels(string $credential, string $endpoint = ''): array {
    return $this->accepts($credential) ? ['scripted-chat' => 'Scripted chat'] : [];
  }

  /**
   * {@inheritdoc}
   */
  public function supportsImageEditing(): bool {
    // TRUE unless a test says otherwise, so the "can generate but cannot edit"
    // remedy path has something to assert against.
    return (bool) ($this->state->get('aincient_inference_test.supports_editing', TRUE));
  }

  /**
   * {@inheritdoc}
   */
  public function listImageModels(string $credential, string $endpoint = ''): array {
    return $this->accepts($credential) ? ['scripted-image' => 'Scripted image'] : [];
  }

  /**
   * Whether enumeration should answer for this credential.
   *
   * @see self::VALID_CREDENTIAL_KEY
   */
  private function accepts(string $credential): bool {
    $credential = trim($credential);
    if ($credential === '') {
      return FALSE;
    }
    $required = trim((string) $this->state->get(self::VALID_CREDENTIAL_KEY, ''));
    return $required === '' || $required === $credential;
  }

}
