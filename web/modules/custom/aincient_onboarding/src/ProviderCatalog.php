<?php

declare(strict_types=1);

namespace Drupal\aincient_onboarding;

use Drupal\aincient_core\Inference\ProviderInventory;
use Drupal\aincient_core\ModelRecommendations;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Enumerates the chat-capable AI providers offered in the onboarding wizard.
 *
 * This is the data behind the wizard's "Choose your AI provider" step. The list
 * comes from {@see ProviderInventory} — one row per inference adapter Atelier
 * ships — so the picker stays in sync with what the site can actually use
 * without any hard-coded list.
 *
 * IT USED TO OFFER MORE THAN THE PRODUCT COULD HONOUR. Sourced from `drupal/ai`'s
 * plugin system, this list included every installed provider module: Mistral,
 * OpenAI, Ollama and OpenRouter alongside Anthropic and Google. None of those
 * four had an inference adapter, so connecting one stored a key and left the
 * operator with a console that could not answer — the picker was advertising
 * capability the product does not have. Sourcing it from the adapter set is what
 * makes the offer true.
 *
 * That cuts both ways, and three of those four are the proof: `ollama`, `openai`
 * and `mistral` each came back the day an adapter was written for it, with
 * nothing in this file changed. A provider appears here because the product can
 * serve it, and for no other reason — `openrouter` is still absent for exactly
 * the same reason it was.
 *
 * No provider is recommended by default — the distribution is fully neutral, so
 * the picker highlights nothing until an operator (or the future sponsorship
 * layer) sets one. The `recommended` slot is driven by local config
 * (`aincient_onboarding.settings: recommended_provider`, empty by default).
 * This is the seam that layer plugs into: a curated, remotely-served promotion
 * manifest would set the recommended/sponsored provider here WITHOUT the
 * commercial arrangement ever living in the GPL distribution (see the
 * onboarding proposal, §5). The `sponsored` flag is reserved for that — always
 * FALSE in v1 — so the UI can render an honest "Sponsored"/"Partner" label the
 * moment it's used.
 */
final class ProviderCatalog {

  /**
   * Provider ids hidden from the onboarding picker. Currently none.
   *
   * The rule this enforces is "never offer a row that cannot succeed". It held
   * exactly one id — `openai_compatible`, which needs an API key AND a base URL
   * ({@see \Drupal\aincient_core\Inference\ProviderAdapterInterface::AUTH_KEY_ENDPOINT})
   * against a connect step that rendered one field. The step renders both fields
   * now, so the id came out and the whole `api_key_endpoint` shape — DeepSeek,
   * Groq, OpenRouter, a LiteLLM proxy, vLLM, LM Studio — is offered like any
   * other.
   *
   * The seam stays because the rule outlives its first instance: a shape we can
   * serve but not yet collect belongs here rather than in the picker, and being
   * absent from the picker never means absent from the INVENTORY (the models form
   * still lists such a provider's models, and it stays connectable headlessly).
   */
  private const HIDDEN_PROVIDERS = [];

  /**
   * Display metadata for key groups (see ProviderConnector::KEY_GROUPS).
   *
   * A key group is presented as ONE picker row under its primary id; this map
   * gives that row a group-level label + description that reflects the combined
   * capabilities, instead of just the primary plugin's own copy. Keyed by
   * primary id.
   *
   * @var array<string, array{label: string, description: string}>
   */
  private const GROUP_META = [
    'gemini' => [
      'label' => 'Google Gemini',
      'description' => 'Gemini for chat and vision, plus Nano Banana image generation — all from one Google AI Studio key.',
    ],
  ];

  public function __construct(
    private readonly ProviderInventory $providerManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ModelRecommendations $recommendations,
    private readonly ProviderConnector $connector,
  ) {}

  /**
   * Chat-capable providers for the picker, recommended slot first.
   *
   * Lists servable providers regardless of whether they are connected yet — the
   * whole point of onboarding is to connect one — and flags which already have a
   * credential so the UI can show a "Connected" state.
   *
   * @return list<array{id: string, label: string, description: string, auth: string, recommended: bool, sponsored: bool, usable: bool}>
   *   One row per provider.
   */
  public function chatProviders(): array {
    $recommended = $this->recommendedProviderId();

    $providers = [];
    foreach ($this->providerManager->providersWith(ProviderInventory::CHAT) as $id => $row) {
      if (in_array($id, self::HIDDEN_PROVIDERS, TRUE)) {
        continue;
      }
      $providers[] = [
        'id' => $id,
        'label' => $row['label'],
        'description' => $row['description'],
        // How the connect step authenticates this provider: an API key, or a
        // server URL. The provider's own answer now — this used to be a
        // HOST_PROVIDERS list maintained here AND in the connector.
        'auth' => $row['auth'],
        'recommended' => $id === $recommended,
        // Reserved for the promotion manifest; never paid-placement in v1.
        'sponsored' => FALSE,
        // A stored credential, not the provider's opinion of itself: `isUsable()`
        // claimed three unkeyed providers were ready and denied one that was.
        'usable' => $row['connected'],
      ];
    }

    // Recommended first, then alphabetical by label — a stable, sensible order
    // the user can scan. Spaceship on a tuple: recommended (TRUE sorts first),
    // then label ascending.
    usort($providers, static function (array $a, array $b): int {
      return [$b['recommended'], $a['label']] <=> [$a['recommended'], $b['label']];
    });

    return $providers;
  }

  /**
   * Providers for the multi-connect picker, with per-capability flags.
   *
   * Unlike {@see self::chatProviders()} (chat-only, one row per provider), this
   * covers every provider whatever it can do, and collapses key groups
   * ({@see ProviderConnector::KEY_GROUPS}) into a single row per primary — so
   * Google appears once as "Google Gemini" with BOTH chat and image lit, since
   * `gemini` and `nanobanana` share one key. Each row carries what it can do so
   * the wizard can badge "Chat"/"Image", and whether it's already usable. Hidden
   * providers ({@see self::HIDDEN_PROVIDERS}) are excluded.
   *
   * @return list<array{id: string, label: string, description: string, auth: string, capabilities: array{chat: bool, image: bool}, recommended: bool, recommendation: string, sponsored: bool, usable: bool}>
   */
  public function providers(): array {
    $inventory = $this->providerManager->providers();
    $recommended = $this->recommendedProviderId();

    // Reverse the key-group map: member id => the primary it's presented under.
    $primaryOf = [];
    foreach (ProviderConnector::KEY_GROUPS as $primary => $members) {
      foreach ($members as $member) {
        $primaryOf[$member] = $primary;
      }
    }

    $rows = [];
    foreach ($inventory as $id => $row) {
      $primary = $primaryOf[$id] ?? $id;
      if (in_array($id, self::HIDDEN_PROVIDERS, TRUE) || in_array($primary, self::HIDDEN_PROVIDERS, TRUE)) {
        continue;
      }
      if (!isset($rows[$primary])) {
        $meta = self::GROUP_META[$primary] ?? NULL;
        // The primary's own row when it has one, else this member's — a key group
        // is presented under its primary id even if only a member is registered.
        $source = $inventory[$primary] ?? $row;
        $rows[$primary] = [
          'id' => $primary,
          'label' => $meta['label'] ?? $source['label'],
          'description' => $meta['description'] ?? $source['description'],
          'auth' => $source['auth'],
          'capabilities' => [ProviderInventory::CHAT => FALSE, ProviderInventory::IMAGE => FALSE],
          'recommended' => $primary === $recommended,
          // Our curated guidance label (recommended | tested | not-recommended,
          // or '' when we've said nothing) — distinct from the `recommended`
          // highlight seam, which is the single promoted slot.
          'recommendation' => $this->recommendations->providerRecommendation($primary),
          'sponsored' => FALSE,
          'usable' => FALSE,
        ];
      }
      // Capabilities accumulate ACROSS the group: one Google key gives the row
      // both chat (gemini) and image (nanobanana).
      foreach ([ProviderInventory::CHAT, ProviderInventory::IMAGE] as $capability) {
        if (!empty($row['capabilities'][$capability])) {
          $rows[$primary]['capabilities'][$capability] = TRUE;
        }
      }
      // "Connected" means a credential is actually stored. Checked per member so
      // a key-group row lights up when any member is keyed.
      if ($row['connected']) {
        $rows[$primary]['usable'] = TRUE;
      }
    }

    $rows = array_values($rows);
    usort($rows, static function (array $a, array $b): int {
      return [$b['recommended'], $a['label']] <=> [$a['recommended'], $b['label']];
    });

    return $rows;
  }

  /**
   * The merged model catalogue enumerated from every connected provider's key.
   *
   * Walks {@see self::providers()}, and for each row already usable (a key is
   * present) asks the connector to list its models from the STORED credential —
   * no key re-entry. The per-provider chat/image maps are merged into one,
   * matching the `{chat, image}` shape {@see ProviderConnector::connectAndStore()}
   * returns, so a page-load catalogue and a fresh connect are interchangeable.
   *
   * @param list<array<string, mixed>>|null $providers
   *   The provider rows, when the caller already has them (they cost API probes);
   *   NULL to enumerate them here.
   *
   * @return array{chat: array<string, string>, image: array<string, string>}
   */
  public function storedCatalog(?array $providers = NULL): array {
    $chat = [];
    $image = [];
    foreach ($providers ?? $this->providers() as $row) {
      // Only enumerate providers that already have a key — a keyless provider
      // would otherwise trigger a pointless (and slow) failing API round-trip.
      if (empty($row['usable'])) {
        continue;
      }
      $models = $this->connector->modelsForStored((string) $row['id']);
      $chat += $models['chat'];
      $image += $models['image'];
    }
    return ['chat' => $chat, 'image' => $image];
  }

  /**
   * The provider id that gets the highlighted "Recommended" slot, or ''.
   *
   * Empty by default — a neutral distribution highlights no vendor. Only the
   * `recommended_provider` setting (or, later, the promotion manifest that feeds
   * it) lights this up.
   */
  public function recommendedProviderId(): string {
    return (string) $this->configFactory
      ->get('aincient_onboarding.settings')
      ->get('recommended_provider');
  }

}
