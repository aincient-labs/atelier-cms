<?php

declare(strict_types=1);

namespace Drupal\aincient_chat\Chat;

/**
 * Hybrid router: manual override wins; otherwise route automatically.
 *
 * Single lane: by default every turn goes through the FlowDrop "operator"
 * workflow, where the intent is decided by a Drupal-AI intent-router node and
 * executed via an allow-listed capability. A pinned flow from the UI overrides
 * the default — `flowdrop` (HITL ChoiceNode demo) and `onboarding` (the no-AI
 * first-run wizard) are reachable that way. (The legacy drupal/ai `agent`
 * tool-loop was retired once the FlowDrop operator loop became the one lane.)
 */
final class HybridChatRouter implements ChatRouterInterface {

  private const FLOWS = ['flowdrop', 'operator', 'onboarding'];

  /**
   * {@inheritdoc}
   */
  public function route(string $message, ?string $override = NULL): RouteDecision {
    // The "hybrid" lever: a pinned flow from the UI wins.
    if ($override !== NULL && in_array($override, self::FLOWS, TRUE)) {
      return new RouteDecision($override, 'manual override');
    }

    // Default: the single lane — intent decided + executed via FlowDrop.
    return new RouteDecision('operator', 'default (FlowDrop operator)');
  }

}
