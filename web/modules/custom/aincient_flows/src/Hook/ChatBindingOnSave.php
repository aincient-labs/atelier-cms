<?php

declare(strict_types=1);

namespace Drupal\aincient_flows\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\flowdrop_playground\Settings\PlaygroundChatMover;
use Drupal\flowdrop_workflow\WorkflowDefinitionInterface;

/**
 * Gives a chat-shaped workflow its chat binding when it is saved without one.
 *
 * FlowDrop 2.7 binds a chat turn through the workflow's Playground settings and
 * REFUSES a message to a workflow that has none. Up to 2.6 the Chat Input / Chat
 * Output nodes were the binding. The 2.7 upgrade moves the binding for every
 * workflow that exists at upgrade time, but a flow an owner builds afterwards
 * is saved without one unless they apply the editor's remedy — and the console
 * would then answer every message with a generic error. Keeping 2.6's behaviour
 * for owners is the point (umbrella DECISIONS: FlowDrop 2.7 chat binding).
 *
 * Uses FlowDrop's own mover, the same code the upgrade and the editor's remedy
 * run, so the binding is exactly what the owner would get by clicking it. A
 * no-op when the workflow already has Playground settings, has no chat nodes,
 * or is being written by a config import (an import must land verbatim).
 * Resolved lazily: flowdrop_playground is not a dependency of this module.
 */
final class ChatBindingOnSave {

  #[Hook('flowdrop_workflow_presave')]
  public function presave(WorkflowDefinitionInterface $workflow): void {
    if ($workflow->isSyncing() || !\Drupal::hasService(PlaygroundChatMover::class)) {
      return;
    }
    try {
      \Drupal::service(PlaygroundChatMover::class)->move($workflow);
    }
    catch (\InvalidArgumentException $e) {
      // A chat setup the mover cannot express (half-wired chat nodes): leave
      // it for the editor's doctor, which names the problem.
      \Drupal::logger('aincient_flows')->notice('Workflow @id saved without a chat binding: @m', [
        '@id' => $workflow->id(),
        '@m' => $e->getMessage(),
      ]);
    }
  }

}
