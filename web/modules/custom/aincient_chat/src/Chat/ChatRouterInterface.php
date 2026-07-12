<?php

declare(strict_types=1);

namespace Drupal\aincient_chat\Chat;

/**
 * Decides which backend flow handles a chat turn.
 */
interface ChatRouterInterface {

  /**
   * Route a message.
   *
   * @param string $message
   *   The user's message.
   * @param string|null $override
   *   An optional flow the UI has pinned (the "hybrid" manual override). When
   *   set to a known flow it wins; otherwise routing is automatic.
   */
  public function route(string $message, ?string $override = NULL): RouteDecision;

}
