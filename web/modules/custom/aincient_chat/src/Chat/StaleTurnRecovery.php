<?php

declare(strict_types=1);

namespace Drupal\aincient_chat\Chat;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\flowdrop_pipeline\Entity\FlowDropPipelineInterface;
use Drupal\flowdrop_session\Constants\SessionStatus;
use Drupal\flowdrop_session\Entity\FlowDropSessionInterface;
use Psr\Log\LoggerInterface;

/**
 * Releases a console thread whose turn died without ending.
 *
 * A turn is a serialized unit: FlowDrop refuses a second one while the session
 * is RUNNING ({@see \Drupal\flowdrop_session\Service\SessionTurnService}, which
 * gates on `$session->getStatus()` alone). That guard is right about a live
 * turn and wrong about a dead one — if the PHP process disappears mid-graph the
 * status stays RUNNING with nobody to clear it, and every later message in that
 * thread is refused. The thread is bricked, and the wording ("give it a moment
 * and try again") promises a recovery that never comes.
 *
 * Observed on a hosted demo (DECISIONS 0333): the reasoning node completed in
 * 52s, the process vanished before the next job was scheduled, and the pipeline
 * sat at `Running: 0, Pending: 0, Failed: 0` with eleven idle jobs — no work in
 * flight, no failure, no way forward.
 *
 * FlowDrop already recovers this in `hook_cron` (`recoverStuckSessions()`, 600s
 * threshold). That is the right mechanism in the wrong place for an interactive
 * console: the appliance runs Drupal's automated cron every **3 hours**, and
 * only on a page request, so a 600-second threshold is honoured up to 18×
 * later than it reads. This service is that same repair, moved to the door —
 * the read-path repair that BufferNormalizer already performs on a damaged
 * conversation buffer (DECISIONS 0270), applied here to the lock that would
 * otherwise stop the buffer repair from ever running.
 *
 * @see \Drupal\aincient_flows\Conversation\BufferNormalizer
 *
 * It deliberately does NOT resume the dead turn. Resuming means re-entering a
 * partially-run graph, and a job left RUNNING by a vanished owner cannot be
 * replayed safely: nothing records whether its side effects landed, so a
 * re-run could apply the same page ops twice. Releasing the lock composes with
 * what already exists instead — the buffer repairs on read, and the user's next
 * message is a clean turn.
 */
final class StaleTurnRecovery {

  /**
   * Seconds a RUNNING session must be untouched before it can be released.
   *
   * Not a turn budget — the liveness check below is what decides whether work
   * is in flight, and it holds for a turn of any length because a node that is
   * working has a RUNNING job for as long as it works. This threshold only
   * covers the moments when liveness is legitimately blind:
   *
   * - the sub-second gap between one job completing and the next being
   *   scheduled, where a healthy pipeline momentarily has nothing running;
   * - the deferred-sync path, which flips the session RUNNING and schedules the
   *   work after the response, so for an instant a live turn owns no jobs at
   *   all.
   *
   * 60s is orders of magnitude above both and still 10× tighter than cron's
   * 600s. The cost is stated plainly: a user who retries within a minute of the
   * crash still gets the refusal, and only their next attempt goes through.
   */
  private const STALE_AFTER = 60;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Releases the session's turn lock if no turn is really running.
   *
   * @param string $sessionId
   *   The FlowDrop session id.
   *
   * @return bool
   *   TRUE if a stale lock was released, so the caller may retry the turn.
   *   FALSE when the session is fine, genuinely busy, or too fresh to judge.
   */
  public function recoverIfStale(string $sessionId): bool {
    $session = $this->loadSession($sessionId);
    if ($session === NULL) {
      return FALSE;
    }
    // Only a RUNNING session holds the lock this repairs. AWAITING_INPUT is a
    // deliberate pause whose resume IS the next message, and the turn guard
    // lets it through already.
    if ($session->getStatus() !== SessionStatus::Running->value) {
      return FALSE;
    }
    if (time() - $session->getChangedTime() < self::STALE_AFTER) {
      return FALSE;
    }
    if ($this->hasWorkInFlight($session)) {
      return FALSE;
    }

    $session->setStatus(SessionStatus::Idle->value)->save();
    // WARNING, not info: reaching here means a turn died without ending, which
    // is a bug somewhere upstream of the lock. The recovery keeps the thread
    // usable; it does not make the crash acceptable, and this line is the only
    // trace of it on an appliance with no dblog.
    $this->logger->warning('Released a stale turn lock on session @session: status was running, last touched @age seconds ago, and no job was running or pending. A turn ended without clearing its own status.', [
      '@session' => $sessionId,
      '@age' => time() - $session->getChangedTime(),
    ]);

    return TRUE;
  }

  /**
   * Whether any pipeline of this session still has work to do.
   *
   * RUNNING or PENDING jobs mean a worker owns this turn — including for the
   * whole of a slow model call, which is why a long turn is never mistaken for
   * a dead one. IDLE jobs are deliberately NOT work in flight: an idle job is
   * waiting for a dependency, and when nothing is running or pending, nothing
   * will ever satisfy it. That combination — idle successors behind a completed
   * frontier, with no runnable work — is exactly the wedged shape.
   */
  private function hasWorkInFlight(FlowDropSessionInterface $session): bool {
    $storage = $this->entityTypeManager->getStorage('flowdrop_pipeline');
    $ids = $storage->getQuery()
      ->condition('session_id', $session->id())
      // Access bypassed: this is the system deciding whether a lock is dead,
      // not a user reading somebody's pipelines.
      ->accessCheck(FALSE)
      ->execute();
    if ($ids === []) {
      // A RUNNING session that owns no pipeline at all is the deferred-sync
      // window; the staleness threshold above is what makes this safe to read
      // as dead rather than not-yet-started.
      return FALSE;
    }

    foreach ($storage->loadMultiple($ids) as $pipeline) {
      if (!$pipeline instanceof FlowDropPipelineInterface) {
        continue;
      }
      if ($pipeline->hasRunningJobs() || $pipeline->hasPendingJobs()) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Loads a session, tolerating a build where FlowDrop is not installed.
   *
   * The chat kernel-test container does not enable FlowDrop, and the dispatcher
   * degrades rather than fails when it is absent; this stays consistent with
   * that rather than making the entity type a hard dependency.
   */
  private function loadSession(string $sessionId): ?FlowDropSessionInterface {
    if (!$this->entityTypeManager->hasDefinition('flowdrop_session')) {
      return NULL;
    }
    $session = $this->entityTypeManager
      ->getStorage('flowdrop_session')
      ->load($sessionId);

    return $session instanceof FlowDropSessionInterface ? $session : NULL;
  }

}
