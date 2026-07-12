<?php

declare(strict_types=1);

namespace Drupal\aincient_onboarding\Plugin\AiFunctionCall;

use Drupal\aincient_onboarding\ProviderCatalog;
use Drupal\aincient_onboarding\ProviderConnector;
use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Onboarding capability: open the in-chat "connect AI" panel.
 *
 * Emits a generative-UI widget envelope (`{"__widget__": "onboarding",
 * "payload": …}`) instead of prose. The dispatcher harvests the envelope out of
 * the workflow's tool results and renders the `onboarding` widget inline — a
 * card with an API-key field, a chat-model picker, and a "Connect" button that
 * POSTs to {@see \Drupal\aincient_onboarding\Controller\OnboardingController}.
 *
 * This runs inside the DETERMINISTIC onboarding workflow (no AI node) because a
 * fresh site has no usable key yet, so the agent can't run. Once the key is
 * validated and stored, the console drops back to its normal AI-driven routing.
 */
#[FunctionCall(
  id: 'aincient_onboarding:onboarding_panel',
  function_name: 'aincient_onboarding_panel',
  name: 'Connect AI (onboarding)',
  description: 'Show the first-run "connect AI" panel in the chat: a field to paste your AI provider credential and a Connect button that validates and stores it, then picks a model for each AIncient role. Call this when AI is not yet configured on the site. Takes no arguments — it renders the panel.',
)]
final class OnboardingPanel extends FunctionCallBase implements ExecutableFunctionCallInterface {

  /**
   * The onboarding provider connector.
   */
  protected ProviderConnector $connector;

  /**
   * The provider catalog (resolves which provider this panel targets).
   */
  protected ProviderCatalog $catalog;

  /**
   * The current user.
   */
  protected AccountInterface $currentUser;

  /**
   * The readable output.
   */
  protected string $result = '';

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->connector = $container->get('aincient_onboarding.provider_connector');
    $instance->catalog = $container->get('aincient_onboarding.provider_catalog');
    $instance->currentUser = $container->get('current_user');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function execute(): void {
    if (!$this->currentUser->hasPermission('administer site configuration')) {
      $this->result = 'Error: you do not have permission to configure AI for this site. Ask a site administrator to connect AI.';
      return;
    }

    // This compact in-chat card connects ONE provider — the recommended one if
    // set, else the first installed chat provider. The full-screen wizard is the
    // multi-provider + per-role path; the card keeps a single model-less field
    // and lets the role layer pick a sensible model per role on connect.
    $target = $this->targetProvider();
    if ($target === NULL) {
      $this->result = 'Error: no AI provider is installed yet. Add a provider module (for example Anthropic, OpenAI, or Ollama) and try again.';
      return;
    }

    $payload = [
      'saveUrl' => Url::fromRoute('aincient_onboarding.save')->toString(),
      'provider' => $target['id'],
      'providerLabel' => $target['label'],
      // 'api_key' | 'host' — the card renders the right field + placeholder.
      'auth' => $target['auth'],
      'configured' => $this->connector->isConfigured(),
    ];

    $this->result = (string) json_encode([
      '__widget__' => 'onboarding',
      'payload' => $payload,
      'summary' => sprintf(
        "Let's connect AI. Paste your %s credential below to bring the console to life — it's validated before anything is saved.",
        $target['label'],
      ),
    ]);
  }

  /**
   * The provider this card connects: recommended if set, else first installed.
   *
   * @return array{id: string, label: string, auth: string}|null
   *   NULL when no chat provider is installed.
   */
  private function targetProvider(): ?array {
    $providers = $this->catalog->chatProviders();
    if ($providers === []) {
      return NULL;
    }
    $recommended = $this->catalog->recommendedProviderId();
    if ($recommended !== '') {
      foreach ($providers as $provider) {
        if ($provider['id'] === $recommended) {
          return $provider;
        }
      }
    }
    return $providers[0];
  }

  /**
   * {@inheritdoc}
   */
  public function getReadableOutput(): string {
    return $this->result;
  }

}
