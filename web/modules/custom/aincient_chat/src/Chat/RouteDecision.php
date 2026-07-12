<?php

declare(strict_types=1);

namespace Drupal\aincient_chat\Chat;

/**
 * The outcome of routing a chat turn to a backend flow (topology §3, §4a).
 */
final class RouteDecision {

  public function __construct(
    // 'operator' | 'flowdrop' | 'onboarding' (see HybridChatRouter::FLOWS).
    public readonly string $flow,
    public readonly string $reason = '',
  ) {}

}
