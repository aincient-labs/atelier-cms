<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Inference;

use Drupal\aincient_core\Inference\Exception\ProviderConfigurationException;
use Drupal\aincient_core\ModelRoleResolver;
use Drupal\aincient_core\ModelRoles;

/**
 * Turns what a NODE stores into the provider and model an inference runs on.
 *
 * Extracted from {@see SymfonyAiReasoner} unchanged, because a second node type
 * now stores the same two fields ({@see \Drupal\aincient_flows\Plugin\FlowDropNodeProcessor\SimpleChat},
 * the brand specialists' chat node) and the resolution ORDER is the part that
 * must not drift between them: a node that resolved differently depending on
 * which processor read it would be a silently wrong model, which is worse than a
 * clear failure — and impossible to notice from the output.
 *
 * Nothing is guessed from "first available", which is the one behaviour of the
 * `drupal/ai`-backed predecessor deliberately not carried over.
 */
final class ModelTargetResolver {

  public function __construct(
    private readonly ModelRoleResolver $roles,
  ) {}

  /**
   * Resolves a node's `operation_type` + `model` to a provider and model id.
   *
   * Order: an explicit `provider:model` on the node, then the AIncient role the
   * node names (`aincient_role:task`), then the `task` role as the everyday
   * default. A bare (un-namespaced) model id overrides the role's MODEL but keeps
   * the role's provider — the pre-migration behaviour for such ids.
   *
   * @param string $operationType
   *   The node's operation type, e.g. `aincient_role:task` or `chat`.
   * @param string $model
   *   The node's model field: `provider:model`, a bare model id, or ''.
   *
   * @return array{0: string, 1: string}
   *   The provider id and model id.
   *
   * @throws \Drupal\aincient_core\Inference\Exception\ProviderConfigurationException
   *   When no model can be resolved.
   */
  public function resolve(string $operationType, string $model = ''): array {
    $explicit = trim($model);
    if ($explicit !== '' && str_contains($explicit, ':')) {
      [$providerId, $modelId] = explode(':', $explicit, 2);
      if ($providerId !== '' && $modelId !== '') {
        return [$providerId, $modelId];
      }
    }

    $role = $this->roleFrom($operationType);
    $binding = $this->roles->resolve($role);
    $providerId = trim((string) ($binding['provider_id'] ?? ''));
    $modelId = trim((string) ($binding['model_id'] ?? ''));

    if ($providerId === '' || $modelId === '') {
      throw new ProviderConfigurationException(sprintf(
        'No model is bound to the "%s" role. Connect an AI provider, or bind a model in the console.',
        $role,
      ));
    }

    if ($explicit !== '' && !str_contains($explicit, ':')) {
      $modelId = $explicit;
    }

    return [$providerId, $modelId];
  }

  /**
   * The qualified default model, from the `task` role binding.
   */
  public function defaultQualifiedModel(): ?string {
    $binding = $this->roles->resolve(ModelRoles::TASK);
    $providerId = trim((string) ($binding['provider_id'] ?? ''));
    $modelId = trim((string) ($binding['model_id'] ?? ''));
    return ($providerId !== '' && $modelId !== '') ? $providerId . ':' . $modelId : NULL;
  }

  /**
   * The operation-type options a node may be configured with.
   *
   * Advertises the AIncient roles so a node stored as `aincient_role:task`
   * carries a valid enum member.
   *
   * @return array<int, array{value: string, label: string}>
   *   The options.
   */
  public function operationTypeOptions(): array {
    $options = [['value' => 'chat', 'label' => 'Chat']];
    foreach (ModelRoles::ids() as $role) {
      $options[] = [
        'value' => 'aincient_role:' . $role,
        'label' => (string) (ModelRoles::definitions()[$role]['label'] ?? $role),
      ];
    }
    return $options;
  }

  /**
   * Maps a node's operation type onto an AIncient model role.
   *
   * `aincient_role:<role>` names one directly; plain `chat` (and anything else)
   * means the everyday `task` tier.
   */
  private function roleFrom(string $operationType): string {
    $operationType = trim($operationType);
    if (str_starts_with($operationType, 'aincient_role:')) {
      $role = substr($operationType, strlen('aincient_role:'));
      if (ModelRoles::isRole($role)) {
        return $role;
      }
    }
    return ModelRoles::TASK;
  }

}
