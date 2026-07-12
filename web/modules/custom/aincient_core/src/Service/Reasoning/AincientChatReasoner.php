<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Service\Reasoning;

use Drupal\ai\AiProviderPluginManager;
use Drupal\flowdrop\DTO\Reason\ModelChoices;
use Drupal\flowdrop\DTO\Reason\ReasonRequest;
use Drupal\flowdrop\DTO\Reason\ReasonResult;
use Drupal\flowdrop\Service\Reasoning\ChatReasonerInterface;
use Drupal\flowdrop_ai_provider\Service\AiModelService;
use Drupal\flowdrop_ai_provider\Service\Reasoning\ChatReasoner;

/**
 * Role-aware reasoning backend for the native FlowDrop reason node.
 *
 * FlowDrop's `reason` node populates its own config schema (the Model enum and
 * the operation-type / model-role options) from whatever `flowdrop.chat_reasoner`
 * advertises via getModelChoices() — the extension seam the upstream author
 * left ("role-aware decorators can widen this"). The stock {@see ChatReasoner}
 * hardcodes the operation-type list to a single `chat` entry, so a reason node
 * stored with `operation_type: aincient_role:task` would carry a value that is
 * not a valid enum member and the ParameterResolver would reject it before the
 * node ever runs.
 *
 * {@see ChatReasoner} is `final`, so this is a delegating decorator by
 * COMPOSITION, not a subclass: it forwards reason() to an inner stock reasoner
 * (no translation is reimplemented) and overrides ONLY getModelChoices() to
 * advertise the AIncient model roles (reasoning / task / fast), sourced from the
 * decorated {@see \Drupal\aincient_core\Service\AincientModelService}. Role
 * resolution then works end to end because the inner reasoner resolves an empty
 * per-node model through getDefaultModelForOperationType(), which
 * AincientModelService teaches about `aincient_role:*`.
 *
 * Swapped in for `flowdrop.chat_reasoner` by {@see
 * \Drupal\aincient_core\AincientCoreServiceProvider}, mirroring the model_service
 * swap: guarded by hasDefinition() so aincient_core stays installable without
 * the FlowDrop AI provider (this class only loads when the service instantiates,
 * and a Symfony `decorates:` would instead hard-fail when the decorated service
 * is absent — e.g. minimal installs and unit tests).
 *
 * @see \Drupal\flowdrop_ai_provider\Service\Reasoning\ChatReasoner
 * @see \Drupal\aincient_core\Service\AincientModelService::getOperationTypeOptions()
 */
final class AincientChatReasoner implements ChatReasonerInterface {

  /**
   * The stock reasoner we delegate the real inference to.
   */
  private readonly ChatReasoner $inner;

  public function __construct(
    private readonly AiModelService $modelService,
    AiProviderPluginManager $aiProviderManager,
  ) {
    // Compose the stock reasoner from the same arguments the container passes
    // to flowdrop.chat_reasoner; it owns no other dependencies.
    $this->inner = new ChatReasoner($modelService, $aiProviderManager);
  }

  /**
   * {@inheritdoc}
   */
  public function reason(ReasonRequest $request): ReasonResult {
    return $this->inner->reason($request);
  }

  /**
   * {@inheritdoc}
   */
  public function getModelChoices(string $operationType = 'chat'): ModelChoices {
    $op = $operationType ?: 'chat';
    $models = array_keys($this->modelService->getAvailableModels($op));
    $default = $this->modelService->getDefaultModelForOperationType($op);
    // The operation-type list is where the reason node's Model Role dropdown
    // comes from. On the AIncient install this is the role list (reasoning /
    // task / fast); the base service returns only `chat` when no roles apply.
    return new ModelChoices(
      $models,
      $default,
      $this->modelService->getOperationTypeOptions('chat'),
    );
  }

}
