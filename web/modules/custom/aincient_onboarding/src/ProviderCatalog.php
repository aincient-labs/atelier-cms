<?php

declare(strict_types=1);

namespace Drupal\aincient_onboarding;

use Drupal\ai\AiProviderPluginManager;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Enumerates the chat-capable AI providers offered in the onboarding wizard.
 *
 * This is the data behind the wizard's "Choose your AI provider" step. The list
 * is sourced from drupal/ai's provider plugin system — every installed provider
 * module (ai_provider_anthropic, ai_provider_openai, …) that supports chat shows
 * up here, so the picker stays in sync with what the site can actually use
 * without any hard-coded list.
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
   * Operation type the console runs on — providers must support chat.
   */
  private const OPERATION_TYPE = 'chat';

  /**
   * The image-generation operation type (the Media studio's AI rail).
   */
  private const IMAGE_OPERATION_TYPE = 'text_to_image';

  /**
   * Provider plugin ids that authenticate with a host URL, not an API key.
   *
   * Mirrors {@see \Drupal\aincient_onboarding\ProviderConnector::HOST_PROVIDERS}.
   */
  private const HOST_PROVIDERS = ['ollama'];

  /**
   * Provider plugin ids hidden from the onboarding picker.
   *
   * OpenRouter is an aggregator that proxies hundreds of upstream models and
   * returns its entire catalog (unfiltered by modality) — a poor first-run
   * experience — so it's kept out of onboarding for now. The module stays
   * installed, so any existing config keeps working and it can be re-surfaced
   * later; it's simply not offered as a starting point.
   */
  private const HIDDEN_PROVIDERS = ['openrouter'];

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
    private readonly AiProviderPluginManager $providerManager,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Chat-capable providers for the picker, recommended slot first.
   *
   * Lists installed providers regardless of whether they're configured yet
   * (`setup = FALSE`) — the whole point of onboarding is to configure one — and
   * flags which are already usable so the UI can show a "Connected" state.
   *
   * @return list<array{id: string, label: string, description: string, auth: string, recommended: bool, sponsored: bool, usable: bool}>
   *   One row per provider.
   */
  public function chatProviders(): array {
    $recommended = $this->recommendedProviderId();

    $providers = [];
    foreach ($this->providerManager->getProvidersForOperationType(self::OPERATION_TYPE, FALSE) as $id => $definition) {
      $providers[] = [
        'id' => $id,
        'label' => (string) ($definition['label'] ?? $id),
        'description' => (string) ($definition['description'] ?? ''),
        // How the connect step authenticates this provider: an API key, or a
        // server URL (host providers like Ollama, which take no key).
        'auth' => in_array($id, self::HOST_PROVIDERS, TRUE) ? 'host' : 'api_key',
        'recommended' => $id === $recommended,
        // Reserved for the promotion manifest; never paid-placement in v1.
        'sponsored' => FALSE,
        'usable' => $this->isUsable($id),
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
   * Unlike {@see self::chatProviders()} (chat-only, one row per plugin), this
   * merges the chat and image pools and collapses key groups
   * ({@see ProviderConnector::KEY_GROUPS}) into a single row per primary — so
   * Google appears once as "Google Gemini" with BOTH chat and image lit, since
   * `gemini` and `nanobanana` share one key. Each row carries what it can do so
   * the wizard can badge "Chat"/"Image", and whether it's already usable. Hidden
   * providers ({@see self::HIDDEN_PROVIDERS}) are excluded.
   *
   * @return list<array{id: string, label: string, description: string, auth: string, capabilities: array{chat: bool, image: bool}, recommended: bool, sponsored: bool, usable: bool}>
   */
  public function providers(): array {
    $chatIds = array_keys($this->providerManager->getProvidersForOperationType(self::OPERATION_TYPE, FALSE));
    $imageIds = array_keys($this->providerManager->getProvidersForOperationType(self::IMAGE_OPERATION_TYPE, FALSE));
    $definitions = $this->providerManager->getDefinitions();
    $recommended = $this->recommendedProviderId();

    // Reverse the key-group map: member id => the primary it's presented under.
    $primaryOf = [];
    foreach (ProviderConnector::KEY_GROUPS as $primary => $members) {
      foreach ($members as $member) {
        $primaryOf[$member] = $primary;
      }
    }

    $rows = [];
    foreach (array_unique([...$chatIds, ...$imageIds]) as $id) {
      $primary = $primaryOf[$id] ?? $id;
      if (in_array($id, self::HIDDEN_PROVIDERS, TRUE) || in_array($primary, self::HIDDEN_PROVIDERS, TRUE)) {
        continue;
      }
      if (!isset($rows[$primary])) {
        $meta = self::GROUP_META[$primary] ?? NULL;
        $definition = $definitions[$primary] ?? $definitions[$id] ?? [];
        $rows[$primary] = [
          'id' => $primary,
          'label' => $meta['label'] ?? (string) ($definition['label'] ?? $primary),
          'description' => $meta['description'] ?? (string) ($definition['description'] ?? ''),
          'auth' => in_array($primary, self::HOST_PROVIDERS, TRUE) ? 'host' : 'api_key',
          'capabilities' => ['chat' => FALSE, 'image' => FALSE],
          'recommended' => $primary === $recommended,
          'sponsored' => FALSE,
          'usable' => FALSE,
        ];
      }
      if (in_array($id, $chatIds, TRUE)) {
        $rows[$primary]['capabilities']['chat'] = TRUE;
      }
      if (in_array($id, $imageIds, TRUE)) {
        $rows[$primary]['capabilities']['image'] = TRUE;
      }
      if ($this->isUsable($id, self::OPERATION_TYPE) || $this->isUsable($id, self::IMAGE_OPERATION_TYPE)) {
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

  /**
   * Whether a provider is already configured and ready to use for an op type.
   */
  private function isUsable(string $id, string $operationType = self::OPERATION_TYPE): bool {
    try {
      return $this->providerManager->createInstance($id)->isUsable($operationType);
    }
    catch (\Throwable) {
      // A provider that can't even instantiate isn't usable — never fatal here.
      return FALSE;
    }
  }

}
