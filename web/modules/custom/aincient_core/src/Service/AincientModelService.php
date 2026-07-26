<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Service;

use Drupal\aincient_core\ModelRoleResolver;
use Drupal\aincient_core\ModelRoles;
use Drupal\flowdrop_ai_provider\Service\AiModelService;
use Drupal\key\KeyRepositoryInterface;

/**
 * Routes FlowDrop chat nodes through AIncient's semantic model roles.
 *
 * FlowDrop's AI provider (>= 1.1.0) lets each Chat / Simple Chat node pick an
 * "operation type" that decides which model resolves when the node's Model
 * field is left empty. Resolution and the editor's option list both funnel
 * through two methods on flowdrop_ai_provider.model_service:
 * resolveModel() and getOperationTypeOptions().
 *
 * This subclass is swapped in for that service by AincientCoreServiceProvider
 * and overrides exactly those two methods so that:
 *   - the per-node select offers ONLY AIncient model roles (reasoning / task /
 *     fast), not raw drupal/ai operation types, and
 *   - selecting a role resolves to the provider+model bound to it in
 *     aincient_core.model_roles — configured from one place (the Models
 *     settings form, `drush aincient:model-set`, or the manager CLI).
 *
 * Everything else delegates to the parent unchanged, so this is fully
 * backward-compatible: a node left at the stock 'chat' default (or any real
 * drupal/ai operation type) resolves exactly as before.
 */
final class AincientModelService extends AiModelService {

  /**
   * Prefix marking an operation-type id as an AIncient model role.
   *
   * Namespaced so a role id can never collide with a real or pseudo drupal/ai
   * operation type, and so resolveModel() can detect one with a cheap prefix
   * check.
   */
  public const ROLE_PREFIX = 'aincient_role:';

  /**
   * The model-role resolver, injected via a setter by the service provider.
   *
   * Setter (not constructor) injection keeps us decoupled from the parent's
   * constructor signature, so an upstream change to AiModelService's
   * dependencies doesn't break the swap.
   */
  protected ?ModelRoleResolver $roleResolver = NULL;

  /**
   * The key repository, injected via a setter by the service provider.
   *
   * Only used to answer "can this provider authenticate at all?" — see
   * {@see self::providerCanAuthenticate()}. Setter injection for the same
   * reason as the resolver above.
   */
  protected ?KeyRepositoryInterface $keyRepository = NULL;

  /**
   * Sets the model-role resolver.
   */
  public function setRoleResolver(ModelRoleResolver $resolver): void {
    $this->roleResolver = $resolver;
  }

  /**
   * Sets the key repository.
   */
  public function setKeyRepository(KeyRepositoryInterface $repository): void {
    $this->keyRepository = $repository;
  }

  /**
   * {@inheritdoc}
   */
  public function getOperationTypeOptions(?string $actual_type = NULL): array {
    // Only chat-family nodes consume this; for any non-chat actual type fall
    // back to the stock behaviour.
    if ($actual_type !== NULL && $actual_type !== 'chat') {
      return parent::getOperationTypeOptions($actual_type);
    }

    $options = [];
    foreach (ModelRoles::definitions() as $id => $definition) {
      $options[] = [
        'value' => self::ROLE_PREFIX . $id,
        'label' => $definition['label'],
      ];
    }
    return $options;
  }

  /**
   * {@inheritdoc}
   *
   * The native reason node's backend ({@see
   * \Drupal\flowdrop_ai_provider\Service\Reasoning\ChatReasoner}) resolves a
   * node's model through this method (NOT resolveModel()): an empty per-node
   * Model field falls back to getDefaultModelForOperationType($operation_type),
   * whose result is then fed to getModel(). Teach it about our roles so a
   * reason node left at `aincient_role:{reasoning,task,fast}` resolves to the
   * bound model, matching resolveModel()'s role branch. Non-role operation
   * types (incl. the stock 'chat') delegate unchanged.
   */
  public function getDefaultModelForOperationType(string $operation_type): ?string {
    if ($this->roleResolver === NULL || !str_starts_with($operation_type, self::ROLE_PREFIX)) {
      return parent::getDefaultModelForOperationType($operation_type);
    }
    $role = substr($operation_type, strlen(self::ROLE_PREFIX));
    $binding = $this->roleResolver->resolve($role);
    $resolved_model = (string) ($binding['model_id'] ?? '');
    // Role unbound (no provider connected yet) → site chat default, so a reason
    // node still resolves on a freshly onboarded install.
    return $resolved_model !== '' ? $resolved_model : parent::getDefaultModelForOperationType('chat');
  }

  /**
   * {@inheritdoc}
   */
  public function resolveModel(string $operation_type, string $model_id = ''): ?array {
    // Not one of our roles (incl. the stock 'chat' default) → unchanged.
    if ($this->roleResolver === NULL || !str_starts_with($operation_type, self::ROLE_PREFIX)) {
      return parent::resolveModel($operation_type, $model_id);
    }

    // An explicit per-node model id always wins, matching parent precedence.
    if ($model_id !== '') {
      return parent::resolveModel('chat', $model_id);
    }

    $role = substr($operation_type, strlen(self::ROLE_PREFIX));
    $binding = $this->roleResolver->resolve($role);
    $resolved_model = (string) ($binding['model_id'] ?? '');

    // Role unbound (no provider connected yet) → site chat default, so the
    // node still works on a freshly onboarded install.
    if ($resolved_model === '') {
      return parent::resolveModel('chat', '');
    }

    // The role binding chose a concrete model; let the parent build the
    // model config in the shape the chat nodes expect.
    return parent::getModel($resolved_model, 'chat');
  }

  /**
   * {@inheritdoc}
   *
   * Keeps a model on the provider it was bound to.
   *
   * The parent resolves a provider by looking a BARE model id up in a map
   * merged from every provider's catalogue, last writer winning
   * ({@see AiModelService::getModelsForOperationType()}). Two providers
   * enumerate their catalogue without needing a key — OpenRouter's `/models`
   * is a public endpoint, Anthropic's list is cached — so on a proxy-only site
   * they are in that merge regardless of whether the operator ever connected
   * them, and they claim every model name they happen to share with the proxy.
   * The request then goes to a provider with no key, and because
   * `OpenAiBasedProviderClientBase::createClient()` logs the missing-key
   * failure instead of rethrowing it, it goes out with NO Authorization header
   * at all — surfacing as the remote's "Missing Authentication header" rather
   * than as a setup error. A LiteLLM proxy serving `claude-sonnet-5` lost it to
   * Anthropic; `anthropic/claude-opus-5` went to OpenRouter. Only names nothing
   * else enumerates (a bare `gpt-4`) survived, which is why a proxy site looked
   * like "only gpt-4 works".
   *
   * We already know better: the operator bound each role to a `provider:model`
   * pair, and the reason node cannot pass that provider on — it reduces the
   * node to a model id and asks this method to re-derive the rest (see
   * ChatReasoner::resolveModelConfig(), which is private, so honouring the
   * binding has to happen here). So a binding that names this model is
   * authoritative about who serves it.
   *
   * Failing a binding, we still refuse to hand back a provider that cannot
   * authenticate while a connected one serves the same model — the guard
   * `ai`'s own `isUsable()` means to apply but never does, because
   * `hasAuthentication()` returns TRUE unconditionally there.
   */
  public function getModel(string $model_id, string $operation_type = ''): ?array {
    if ($model_id === '') {
      return parent::getModel($model_id, $operation_type);
    }

    $bound = $this->providerBoundTo($model_id);
    if ($bound !== '') {
      return [
        'id' => $model_id,
        'name' => $model_id,
        'provider' => $bound,
        'operation_type' => $operation_type ?: 'chat',
      ];
    }

    $config = parent::getModel($model_id, $operation_type);
    if ($config === NULL) {
      return NULL;
    }

    if (!$this->providerCanAuthenticate((string) ($config['provider'] ?? ''))) {
      $connected = $this->connectedProviderServing($model_id, $operation_type ?: 'chat');
      if ($connected !== '') {
        $config['provider'] = $connected;
      }
    }

    return $config;
  }

  /**
   * The provider a role binding names for this exact model id.
   *
   * Reads the bindings themselves rather than
   * {@see ModelRoleResolver::resolve()}, which falls back to the site default
   * when a role is unbound — that fallback
   * would make an unbound role look like a binding for whatever model the site
   * default happens to name.
   *
   * Two roles bound to the same model on different providers is a contradiction
   * the operator has to resolve; we take the first in role order, which is at
   * least deterministic.
   *
   * @return string
   *   The bound provider id, or '' when no binding names this model.
   */
  private function providerBoundTo(string $model_id): string {
    if ($this->roleResolver === NULL) {
      return '';
    }
    foreach ($this->roleResolver->roles() as $role) {
      if ($role['model_id'] === $model_id && $role['provider_id'] !== '') {
        return (string) $role['provider_id'];
      }
    }
    return '';
  }

  /**
   * Whether a provider could authenticate if we sent it a request.
   *
   * A provider that declares no `api_key` at all is keyless BY DESIGN (a local
   * runtime like Ollama), so it passes. One that names a key which resolves to
   * nothing does not: that is the case that silently sends an unauthenticated
   * request.
   *
   * Failing closed is safe here because the only consequence is that we go
   * looking for a connected provider, and we substitute one only if it actually
   * serves the model — otherwise the parent's answer stands.
   *
   * NOTE: the manager hands back a ProviderProxy, which forwards getConfig()
   * through __call() rather than declaring it — so this must call the method
   * and catch, never ask method_exists().
   */
  private function providerCanAuthenticate(string $provider_id): bool {
    if ($provider_id === '' || $this->keyRepository === NULL) {
      // Nothing to check against — don't second-guess the parent.
      return TRUE;
    }
    try {
      $provider = $this->aiProviderManager->createInstance($provider_id);
      $key_name = (string) ($provider->getConfig()->get('api_key') ?? '');
    }
    catch (\Throwable) {
      return FALSE;
    }
    if ($key_name === '') {
      return TRUE;
    }
    return (string) ($this->keyRepository->getKey($key_name)?->getKeyValue() ?? '') !== '';
  }

  /**
   * A provider the operator actually connected that serves this model.
   *
   * "Connected" means it appears in a role binding and can authenticate — the
   * only evidence we have, in our own vocabulary, that the operator set it up
   * on purpose. Bounded work: a site has one or two such providers, and each
   * catalogue lookup is cached by the AI layer.
   *
   * @return string
   *   The provider id, or '' when none of them serves the model.
   */
  private function connectedProviderServing(string $model_id, string $operation_type): string {
    if ($this->roleResolver === NULL) {
      return '';
    }
    $checked = [];
    foreach ($this->roleResolver->roles() as $role) {
      $provider_id = (string) $role['provider_id'];
      if ($provider_id === '' || isset($checked[$provider_id])) {
        continue;
      }
      $checked[$provider_id] = TRUE;
      if (!$this->providerCanAuthenticate($provider_id)) {
        continue;
      }
      try {
        $models = $this->aiProviderManager->createInstance($provider_id)
          ->getConfiguredModels($operation_type);
      }
      catch (\Exception) {
        continue;
      }
      if (isset($models[$model_id])) {
        return $provider_id;
      }
    }
    return '';
  }

}
