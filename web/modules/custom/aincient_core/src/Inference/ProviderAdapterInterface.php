<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Inference;

use Symfony\AI\Platform\PlatformInterface;

/**
 * The contract every inference provider must satisfy to be usable by Atelier.
 *
 * WHY THIS EXISTS. `drupal/ai`'s provider plugins are maintained by many
 * different companies and nothing in that module can require them to agree, so
 * they diverge in ways that are invisible until they break a request:
 * `isUsable()` returning TRUE with no key stored, `getConfiguredModels()`
 * ignoring the operation type, one provider validating over the network and
 * another straight from config, host providers reading their endpoint from saved
 * config while key providers accept a runtime override. Our onboarding layer
 * accumulated nine hardcoded per-provider maps and five documented behavioural
 * workarounds absorbing exactly that. (Full inventory:
 * `plans/drupal-ai-coupling.md`.)
 *
 * This interface is the answer: a NARROW contract we own, whose implementations
 * we own, with one test suite every adapter must pass. Conformance stops being
 * something we hope for and becomes something we enforce. An adapter that cannot
 * answer these questions honestly does not ship.
 *
 * Adapters are thin by design — the wire protocol belongs to `symfony/ai`'s
 * bridges, which are co-located in one repository under one CI run. An adapter
 * translates our questions into that library's construction and nothing more; it
 * must contain no request/response shaping and no vendor payload building.
 *
 * @see \Drupal\aincient_core\Inference\PlatformRegistry
 */
interface ProviderAdapterInterface {

  /**
   * Authenticates with an API key alone (Anthropic, Gemini, Mistral).
   */
  public const AUTH_KEY = 'api_key';

  /**
   * Authenticates with an API key AND a caller-supplied base URL.
   *
   * The shape `drupal/ai`'s provider interface could not express, which is why
   * `ai_provider_litellm` is offered in our wizard today with no way to set its
   * host. Covers every OpenAI-compatible service (DeepSeek, Groq, vLLM, a
   * LiteLLM proxy, LM Studio).
   */
  public const AUTH_KEY_ENDPOINT = 'api_key_endpoint';

  /**
   * Authenticates with a base URL alone, no secret (Ollama).
   */
  public const AUTH_HOST = 'host';

  /**
   * The stable machine id for this provider.
   *
   * Used as the provider half of every `provider:model` pair we persist, so it
   * MUST remain stable across releases — changing it orphans stored role
   * bindings. Kept equal to the historical `drupal/ai` plugin id wherever one
   * existed, so existing `aincient_core.model_roles` config keeps resolving.
   */
  public function id(): string;

  /**
   * The human label shown in the console (e.g. "Anthropic").
   */
  public function label(): string;

  /**
   * One sentence telling an operator what choosing this provider gets them.
   *
   * The onboarding picker and the models form render this next to the label. It
   * lives on the adapter for the same reason {@see self::authShape()} does: the
   * alternative is a provider-id => copy map on our side, which is the shape this
   * contract exists to delete. It also has to be the adapter's answer because
   * only the adapter knows what it actually covers — the OpenAI-compatible one
   * stands in for half a dozen vendors, and an operator looking for Mistral has
   * to be able to find it there.
   */
  public function description(): string;

  /**
   * Whether this provider should be OFFERED for chat work.
   *
   * Almost always TRUE — every adapter's platform can chat, because that is the
   * one transport `symfony/ai` gives us and a vision turn is a chat turn with an
   * image part. It exists for the provider that CAN chat but must not be offered
   * for it: {@see Adapter\NanoBananaAdapter} is a second id over the same Google
   * key as {@see Adapter\GeminiAdapter}, so listing it as a chat provider
   * duplicates all thirty Gemini models under a second label and invites a role
   * bound to the image id for text work. "Can" and "should be offered for" are
   * different questions, and only the provider can answer the second.
   */
  public function servesChat(): bool;

  /**
   * Which credential shape this provider needs.
   *
   * @return self::AUTH_KEY|self::AUTH_KEY_ENDPOINT|self::AUTH_HOST
   *   One of the AUTH_* constants. Declared by the adapter rather than looked up
   *   in a hardcoded list on our side — this is the `HOST_PROVIDERS` map (which
   *   we currently maintain in duplicate) becoming the provider's own answer.
   */
  public function authShape(): string;

  /**
   * Builds a configured platform for a credential.
   *
   * @param string $credential
   *   The API key, or '' for a host-authenticated provider.
   * @param string $endpoint
   *   The base URL, or '' to use the vendor default. Required (non-empty) when
   *   {@see self::authShape()} is AUTH_KEY_ENDPOINT or AUTH_HOST.
   *
   * @throws \Drupal\aincient_core\Inference\Exception\ProviderConfigurationException
   *   When the credential/endpoint combination cannot yield a usable platform.
   */
  public function createPlatform(string $credential, string $endpoint = ''): PlatformInterface;

  /**
   * Lists the chat models this credential can actually reach.
   *
   * MUST be a real round-trip against the credential, or a catalogue this
   * adapter can stand behind — never an unverified echo of local config. This is
   * the single conformance rule that matters most: our onboarding treats "models
   * came back" as proof a credential works, and
   * `ai_provider_openai_compatible::getConfiguredModels()` reading its answer
   * from a config list is what makes any non-empty string validate as a working
   * key there.
   *
   * An adapter that cannot enumerate remotely MUST return [] on a bad
   * credential rather than a static list.
   *
   * @return array<string, string>
   *   Model id => human label. Empty when the credential does not work.
   */
  public function listChatModels(string $credential, string $endpoint = ''): array;

  /**
   * Whether the model ids from this provider carry a vendor namespace.
   *
   * TRUE for aggregating proxies (OpenRouter, LiteLLM) whose ids look like
   * `anthropic/claude-sonnet-5`. Replaces `ModelRoles::PROXY_PROVIDERS`, which
   * is a list we maintain about other people's modules.
   */
  public function isProxy(): bool;

  /**
   * Renames our neutral request options to this provider's spelling.
   *
   * WHY THE ADAPTER OWNS THIS. `symfony/ai`'s bridges do NOT normalise the
   * options array — the Gemini bridge drops it verbatim into Gemini's
   * `generationConfig` (`Gemini\ModelClient::request()`), so an OpenAI-shaped
   * `max_tokens` comes back as a hard 400: `Invalid JSON payload received.
   * Unknown name "max_tokens" at 'generation_config'`. Gemini's field is
   * `maxOutputTokens`. Anthropic's really is `max_tokens`. There is no shared
   * spelling to standardise on, only a dialect per provider — and a dialect is
   * exactly the thing the provider itself knows.
   *
   * The alternative was a `match ($providerId)` in the two callers
   * ({@see \Drupal\aincient_core\Inference\SymfonyAiReasoner} and
   * {@see \Drupal\aincient_core\Inference\AiGateway}), which is the per-provider
   * map this whole contract exists to delete — and which would have had to be
   * written twice, so the second copy would be the one that rots.
   *
   * This is NOT the payload building the interface docblock forbids: an adapter
   * still shapes no request and reads no response. It renames keys in OUR
   * vocabulary (`max_tokens`, `temperature`, `tools`) to the vendor's, and the
   * bridge does everything else.
   *
   * @param array<string, mixed> $options
   *   Options in Atelier's neutral vocabulary.
   *
   * @return array<string, mixed>
   *   The same options under the names this provider accepts. An adapter whose
   *   provider already speaks our vocabulary returns them unchanged.
   */
  public function translateOptions(array $options): array;

}
