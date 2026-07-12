<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Service;

use Drupal\aincient_core\ModelRoleResolver;
use Drupal\aincient_core\ModelRoles;
use Drupal\flowdrop_ai_provider\Service\AiModelService;

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
   * Sets the model-role resolver.
   */
  public function setRoleResolver(ModelRoleResolver $resolver): void {
    $this->roleResolver = $resolver;
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

}
