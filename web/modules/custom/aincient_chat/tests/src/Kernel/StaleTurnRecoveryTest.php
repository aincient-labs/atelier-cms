<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_chat\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\aincient_chat\Chat\StaleTurnRecovery;
use Drupal\flowdrop_job\Constants\JobStatus;
use Drupal\flowdrop_pipeline\Constants\PipelineStatus;
use Drupal\flowdrop_session\Constants\SessionStatus;

/**
 * Tests that a dead turn's lock is released and a live one is never touched.
 *
 * The bug this guards (DECISIONS 0333): FlowDrop's turn guard refuses a second
 * turn while the session is RUNNING, reading status alone. A turn whose process
 * dies mid-graph leaves that status set with nobody to clear it, so the thread
 * refuses every later message — permanently, since the appliance's automated
 * cron (which carries FlowDrop's own 600s recovery) runs every 3 hours.
 *
 * Both directions matter here and the second matters more: releasing a lock that
 * a live turn still holds would let two turns write the same conversation
 * buffer, which is a worse bug than the one being fixed. So the liveness cases
 * below are not symmetry for its own sake — they pin the safety property.
 *
 * @covers \Drupal\aincient_chat\Chat\StaleTurnRecovery
 * @group aincient_chat
 */
final class StaleTurnRecoveryTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'options',
    'filter',
    'key',
    'flowdrop_ui_components',
    'flowdrop',
    'flowdrop_node_category',
    'flowdrop_node_type',
    'flowdrop_node_processor',
    'flowdrop_workflow',
    'flowdrop_orchestration',
    'flowdrop_runtime',
    'flowdrop_pipeline',
    'flowdrop_job',
    'flowdrop_session',
    'flowdrop_interrupt',
    'flowdrop_memory',
    'aincient_core',
    'aincient_chat',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('flowdrop_workflow');
    $this->installEntitySchema('flowdrop_node_type');
    $this->installEntitySchema('flowdrop_pipeline');
    $this->installEntitySchema('flowdrop_job');
    $this->installEntitySchema('flowdrop_session');
    $this->installEntitySchema('flowdrop_session_message');
    $this->installEntitySchema('flowdrop_interrupt');
    // Jobs and pipelines are bundleable; both modules ship a `default` bundle,
    // and creating either without one is an EntityStorageException.
    $this->installConfig(['system', 'flowdrop_node_processor', 'flowdrop_job', 'flowdrop_pipeline']);
  }

  /**
   * The wedged shape is released: idle successors, nothing runnable.
   *
   * This is the observed production state reproduced exactly — a completed
   * reasoning job, an idle successor waiting on a dependency that will never be
   * satisfied, and a pipeline still labelled Running.
   */
  public function testReleasesALockNoJobCanStillBeHolding(): void {
    $session = $this->session(SessionStatus::Running, stale: TRUE);
    $this->pipeline($session->id(), PipelineStatus::Running, [JobStatus::Completed, JobStatus::Idle]);

    $this->assertTrue($this->recovery()->recoverIfStale((string) $session->id()));
    $this->assertSame(
      SessionStatus::Idle->value,
      $this->reload($session)->getStatus(),
      'A released session must be Idle so the next message starts a clean turn.',
    );
  }

  /**
   * A RUNNING job means a worker owns the turn — however long it takes.
   *
   * The case that makes a naive timeout wrong: a single reasoning call can run
   * for the best part of a minute (52s in the incident), and a slow turn must
   * never be mistaken for a dead one.
   */
  public function testLeavesATurnAloneWhileAJobIsRunning(): void {
    $session = $this->session(SessionStatus::Running, stale: TRUE);
    $this->pipeline($session->id(), PipelineStatus::Running, [JobStatus::Running]);

    $this->assertFalse($this->recovery()->recoverIfStale((string) $session->id()));
    $this->assertSame(SessionStatus::Running->value, $this->reload($session)->getStatus());
  }

  /**
   * A PENDING job is queued work, so the turn is alive too.
   */
  public function testLeavesATurnAloneWhileAJobIsPending(): void {
    $session = $this->session(SessionStatus::Running, stale: TRUE);
    $this->pipeline($session->id(), PipelineStatus::Running, [JobStatus::Pending]);

    $this->assertFalse($this->recovery()->recoverIfStale((string) $session->id()));
    $this->assertSame(SessionStatus::Running->value, $this->reload($session)->getStatus());
  }

  /**
   * A freshly-touched session is never released, whatever its jobs look like.
   *
   * Covers the two windows where liveness is legitimately blind: the gap
   * between one job completing and the next being scheduled, and the
   * deferred-sync path that flips the session RUNNING before any job exists.
   */
  public function testLeavesAFreshSessionAloneEvenWithNothingRunnable(): void {
    $session = $this->session(SessionStatus::Running, stale: FALSE);
    $this->pipeline($session->id(), PipelineStatus::Running, [JobStatus::Idle]);

    $this->assertFalse($this->recovery()->recoverIfStale((string) $session->id()));
    $this->assertSame(SessionStatus::Running->value, $this->reload($session)->getStatus());
  }

  /**
   * AWAITING_INPUT is a deliberate pause, not a dead turn.
   *
   * The next user message IS the resume, and FlowDrop's guard already lets it
   * through — so releasing it here would be meddling with a healthy HITL pause.
   */
  public function testLeavesAnAwaitingInputSessionAlone(): void {
    $session = $this->session(SessionStatus::AwaitingInput, stale: TRUE);

    $this->assertFalse($this->recovery()->recoverIfStale((string) $session->id()));
    $this->assertSame(SessionStatus::AwaitingInput->value, $this->reload($session)->getStatus());
  }

  /**
   * An idle session holds no lock, so there is nothing to release.
   */
  public function testLeavesAnIdleSessionAlone(): void {
    $session = $this->session(SessionStatus::Idle, stale: TRUE);

    $this->assertFalse($this->recovery()->recoverIfStale((string) $session->id()));
  }

  /**
   * An unknown session id is a no-op, never an exception.
   */
  public function testUnknownSessionIsANoOp(): void {
    $this->assertFalse($this->recovery()->recoverIfStale('404'));
  }

  /**
   * The service under test.
   */
  private function recovery(): StaleTurnRecovery {
    $recovery = $this->container->get('aincient_chat.stale_turn_recovery');
    $this->assertInstanceOf(StaleTurnRecovery::class, $recovery);

    return $recovery;
  }

  /**
   * A session in a given status, optionally older than the stale threshold.
   */
  private function session(SessionStatus $status, bool $stale) {
    $storage = $this->container->get('entity_type.manager')->getStorage('flowdrop_session');
    $session = $storage->create([
      'name' => 'console:thr_test',
      'status' => $status->value,
    ]);
    $session->save();
    // `changed` is maintained by EntityChangedTrait on save, so age has to be
    // written after the save that would otherwise reset it.
    $session->set('changed', $stale ? time() - 3600 : time())->save();

    return $session;
  }

  /**
   * A pipeline bound to a session, owning jobs in the given statuses.
   *
   * The reference runs pipeline → job (a multi-value `job_id`), not job →
   * pipeline, so the jobs are created first and linked on the way in.
   *
   * @param string|int|null $sessionId
   *   The session this pipeline belongs to.
   * @param \Drupal\flowdrop_pipeline\Constants\PipelineStatus $status
   *   The pipeline's own status.
   * @param array<\Drupal\flowdrop_job\Constants\JobStatus> $jobStatuses
   *   One job is created per status.
   */
  private function pipeline(string|int|null $sessionId, PipelineStatus $status, array $jobStatuses = []) {
    $entityTypeManager = $this->container->get('entity_type.manager');
    $jobStorage = $entityTypeManager->getStorage('flowdrop_job');

    $jobIds = [];
    foreach ($jobStatuses as $jobStatus) {
      $job = $jobStorage->create([
        'bundle' => 'default',
        'label' => 'Test job',
        'status' => $jobStatus->value,
      ]);
      $job->save();
      $jobIds[] = $job->id();
    }

    $pipeline = $entityTypeManager->getStorage('flowdrop_pipeline')->create([
      'bundle' => 'default',
      'label' => 'Test pipeline',
      'status' => $status->value,
      'session_id' => $sessionId,
      'job_id' => $jobIds,
    ]);
    $pipeline->save();

    return $pipeline;
  }

  /**
   * Re-reads an entity so assertions see persisted state.
   *
   * Without the cache reset an assertion could pass against the in-memory
   * object the service mutated, whether or not the save actually landed.
   */
  private function reload($entity) {
    $storage = $this->container->get('entity_type.manager')->getStorage($entity->getEntityTypeId());
    $storage->resetCache([$entity->id()]);

    return $storage->load($entity->id());
  }

}
