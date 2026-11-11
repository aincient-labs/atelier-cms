<?php

declare(strict_types=1);

namespace Aincient\Inference;

/**
 * Resolves a model role ({@see ModelRoles}) to the provider + model bound to it.
 *
 * The two questions the inference seams ask of the role layer. In Drupal
 * {@see \Drupal\aincient_core\ModelRoleResolver} answers them from
 * `aincient_core.model_roles` config; that class also owns binding, projection
 * and profiles, which inference never needs.
 */
interface RoleBindingResolverInterface {

  /**
   * A role's binding, falling back to the default role's.
   *
   * @param string $role
   *   A role id; an unknown id resolves as the default role.
   *
   * @return array{provider_id: string, model_id: string}
   *   Empty strings when nothing is bound.
   */
  public function resolve(string $role): array;

  /**
   * The EXPLICIT image-role binding, or NULL — no fallback chain.
   *
   * @return array{provider_id: string, model_id: string}|null
   *   The binding, or NULL when the image role is unbound.
   */
  public function imageBinding(): ?array;

}
