<?php

declare(strict_types=1);

namespace Drupal\aincient_chat\Chat;

use Psr\Log\LoggerInterface;

/**
 * Drops a finished turn's tier-B scratchpad (DECISIONS 0379/0382/0383).
 *
 * Tier B is the per-turn tool traffic: scope `pipeline`, key `scratchpad`,
 * backend `entity`, ttl 86400 — addressed by config in the agent workflows'
 * conversation_buffer / conversation read nodes. It is self-invalidating (the
 * next turn is a new pipeline, so the old address is unreachable), which makes
 * this storage hygiene only: payload-heavy records should not sit for the full
 * TTL once the turn that wrote them is over.
 *
 * Only a TERMINAL turn is swept. A turn paused on an interrupt (HITL) or on its
 * run budget continues in the SAME pipeline, and the scratchpad is the agent's
 * memory of what it already did this turn — dropping it there would make the
 * resumed agent repeat itself.
 *
 * Best effort by contract: a failure here is logged and swallowed, never
 * surfaced to the turn. FlowDrop's memory manager is resolved lazily so this
 * adds no cross-module constructor dependency (the aincient_chat kernel
 * container does not necessarily enable FlowDrop).
 */
final class TurnScratchpadSweeper {

  /**
   * The tier-B address (the one home outside the workflow config).
   */
  public const SCOPE = 'pipeline';
  public const KEY = 'scratchpad';
  public const BACKEND = 'entity';

  /**
   * TurnResult statuses after which the pipeline will not run again.
   *
   * Literals, not TurnResult::STATUS_*, so this class loads without FlowDrop.
   */
  private const TERMINAL_STATUSES = ['completed', 'failed', 'cancelled'];

  public function __construct(
    private readonly LoggerInterface $logger,
    private readonly TurnPipelines $pipelines,
  ) {}

  /**
   * Drops the scratchpad when the turn's status is terminal.
   *
   * @param string $status
   *   A TurnResult status.
   * @param string|null $pipelineId
   *   The turn's pipeline id.
   */
  public function dropIfTerminal(string $status, ?string $pipelineId): void {
    if (in_array($status, self::TERMINAL_STATUSES, TRUE)) {
      $this->drop($pipelineId);
    }
  }

  /**
   * Drops the scratchpad records of a turn's pipeline tree. Never throws.
   *
   * The agent engine (0463) runs as a sub-workflow, so its scratchpad is
   * addressed by the ENGINE's pipeline id, not the turn's: drop every pipeline
   * below the given one too, or engine scratchpads wait out the TTL.
   *
   * @param string|null $pipelineId
   *   The turn's pipeline id; NULL or empty is a no-op (never the global
   *   bucket).
   */
  public function drop(?string $pipelineId): void {
    if ($pipelineId === NULL || $pipelineId === '') {
      return;
    }
    try {
      if (!\Drupal::hasService('flowdrop_memory.manager')) {
        return;
      }
      $memory = \Drupal::service('flowdrop_memory.manager');
      foreach ($this->pipelines->withDescendants($pipelineId) as $id) {
        $memory->delete(self::SCOPE, $id, self::KEY, self::BACKEND);
      }
    }
    catch (\Throwable $e) {
      $this->logger->warning('Could not drop the scratchpad of pipeline @id: @m', [
        '@id' => $pipelineId,
        '@m' => $e->getMessage(),
      ]);
    }
  }

}
