<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Inference;

use Drupal\aincient_core\Exception\AiProviderFailure;

/**
 * Remembers the provider failure this request raised, so a surface can classify.
 *
 * WHY THIS EXISTS AT ALL. A provider fault is raised deep inside a FlowDrop node
 * ({@see ProviderCall}). FlowDrop's NodeRuntimeService catches it, keeps only
 * `$e->getMessage()` — re-wrapped as "Node execution failed for <node>: …" — and
 * dispatches `JobCompletedEvent` with a job entity that carries a STRING, not a
 * throwable. By the time the console's stream relay
 * ({@see \Drupal\aincient_chat\EventSubscriber\NodeProgressSubscriber}) sees the
 * failure, the typed exception (and with it the machine-readable `kind`) is gone.
 *
 * Reading the kind back out of that string would mean matching our own copy —
 * one reworded sentence and every failure silently becomes `unknown`. So the
 * kind travels beside the message instead: this service holds the failure object
 * for the rest of the request, and the relay asks whether the job's error text is
 * the one it recorded.
 *
 * Request-scoped by construction: a plain shared service with in-memory state,
 * never persisted, and only ever holding the LAST failure — a turn that raised
 * two of them has already ended, and the one that ended it is the one the reader
 * is looking at.
 *
 * {@see self::matching()} is the whole safety property. It is not "give me the
 * last kind": an unrelated node that fails later in the same request must NOT
 * inherit a provider's classification, or a graph mistake renders as "reconnect
 * your provider". The recorded sentence must actually be inside the job's error
 * message for the kind to travel.
 */
final class ProviderFailureLog {

  /**
   * The last provider failure raised in this request, if any.
   */
  private ?AiProviderFailure $last = NULL;

  /**
   * Records a failure on its way out of {@see ProviderCall}.
   */
  public function record(AiProviderFailure $failure): void {
    $this->last = $failure;
  }

  /**
   * The recorded failure, when this error message is that failure's.
   *
   * @param string $errorMessage
   *   A job's error message, as FlowDrop stored it — typically
   *   "Node execution failed for <node>: <our sentence>".
   *
   * @return \Drupal\aincient_core\Exception\AiProviderFailure|null
   *   The failure whose sentence this message carries, or NULL when this error
   *   is something else (a graph mistake, a tool blowing up, a plain bug).
   */
  public function matching(string $errorMessage): ?AiProviderFailure {
    if ($this->last === NULL || $errorMessage === '') {
      return NULL;
    }
    $sentence = $this->last->getMessage();

    return $sentence !== '' && str_contains($errorMessage, $sentence)
      ? $this->last
      : NULL;
  }

}
