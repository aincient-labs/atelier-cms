<?php

declare(strict_types=1);

namespace Drupal\aincient_chat\Chat;

use Drupal\aincient_chat\Event\ChatEvent;

/**
 * A stand-in flow dispatcher: simulates an async/long-running dispatch so the
 * console's progress-stream UX can be shaped before a real backend lands.
 *
 * Mirrors the "frontend-first with a mock" approach used for the console: it
 * emits a realistic sequence of STATUS events (queued → working → finishing)
 * with small pauses so progress visibly streams over SSE, then a RESULT that
 * names itself as a stub. No real flow runs and nothing is enqueued.
 *
 * Replace by aliasing `aincient_chat.flow_dispatcher` to a real implementation
 * (FlowDrop trigger/job, or a Drupal AI flow) — the processor is unaffected.
 */
final class StubFlowDispatcher implements FlowDispatcherInterface {

  /**
   * Pause between simulated steps, in microseconds (0.4s).
   *
   * Purely cosmetic — it makes the staged STATUS events stream visibly rather
   * than arriving in one flush. A real dispatcher's pacing comes from actual
   * work, so this constant disappears with the stub.
   */
  private const STEP_DELAY = 400000;

  /**
   * {@inheritdoc}
   */
  public function dispatch(string $message, string $threadId, string $flow, ?string $workflow = NULL, array $clientContext = []): \Generator {
    $steps = [
      sprintf('Dispatching to the "%s" flow…', $flow),
      'Queued as a background job (simulated).',
      'Working… (the stub flow has no real steps yet).',
      'Finishing up…',
    ];
    foreach ($steps as $step) {
      yield ChatEvent::status($step, ['flow' => $flow]);
      usleep(self::STEP_DELAY);
    }

    yield ChatEvent::result(sprintf(
      'The "%s" flow isn\'t wired to a real backend yet — this is a stub that '
      . 'demonstrates the async dispatch and progress-stream shape. When the '
      . 'integration lands, this turn will run a real flow (FlowDrop or a Drupal '
      . 'AI flow) and stream genuine progress and results here.',
      $flow,
    ));
  }

}
