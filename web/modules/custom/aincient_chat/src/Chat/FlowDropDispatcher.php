<?php

declare(strict_types=1);

namespace Drupal\aincient_chat\Chat;

use Drupal\aincient_chat\Event\ChatEvent;
use Drupal\flowdrop_session\DTO\TurnOptions;
use Drupal\flowdrop_session\Exception\ConcurrentTurnException;
use Psr\Log\LoggerInterface;

/**
 * A real flow dispatcher backed by FlowDrop's session chat-turn verb.
 *
 * Each console turn runs through SessionTurnService::executeTurn() (wait
 * path): FlowDrop posts the user message, executes the session's workflow
 * under the session's pinned orchestrator, writes the assistant reply back to
 * the session, and returns a TurnResult whose status says how the turn ended:
 * - completed      → stream the assistant write-back as the RESULT.
 * - awaiting_input → the workflow paused at an interrupt node (HITL); emit an
 *                    INTERRUPT event. The console renders a widget and posts
 *                    the answer to the resolve endpoint, which resumes the
 *                    paused workflow ({@see resume()}).
 * - paused         → the run hit its iteration/time budget; surface that as a
 *                    plain reply (a "continue" affordance is future work — the
 *                    pipeline carries a resumable system Pause signal).
 * - failed         → an ERROR event.
 *
 * A second turn while one is still running is refused by FlowDrop with a
 * typed ConcurrentTurnException — surfaced as a friendly error instead of the
 * old fresh-session fallback.
 *
 * FlowDrop services are resolved lazily (not constructor-injected): the
 * aincient_chat kernel-test container does not enable FlowDrop, so a hard
 * dependency would break the suite. When the modules are absent the dispatch
 * degrades to a friendly RESULT instead of failing the turn.
 */
final class FlowDropDispatcher implements ResumableFlowDispatcherInterface {

  /**
   * The HITL demo lane (ChoiceNode pause/resume) — fixed, not configurable.
   */
  private const CHOICE_DEMO_WORKFLOW = 'aincient_choice_demo';

  /**
   * Deterministic first-run workflow that renders the "connect AI" panel.
   *
   * Pinned to the `onboarding` flow (bypasses the admin-exposed catalog) so the
   * chat controller can force it when no Anthropic key is configured — AI setup
   * is not an opt-in the admin has to expose.
   */
  private const ONBOARDING_WORKFLOW = 'aincient_onboarding';

  /**
   * The orchestrator pinned on every console session.
   *
   * Forces FlowDrop's stategraph orchestrator (cyclic agent loop: loop-back
   * edges + checkpoints) and bounds the loop, so the operator runs as an agent
   * regardless of the workflow's own config or the site default. The session
   * path already defaults to stategraph; this makes it explicit and caps
   * iterations.
   */
  private const ORCHESTRATOR_SETTINGS = [
    'type' => 'flowdrop_stategraph:stategraph',
    // NOTE: an "iteration" is an orchestrator WAVE (one ready-job dispatch
    // batch), not one agent loop — a full reason → gateway → invoke →
    // loop_back cycle spends ~4 waves, so 300 ≈ ~75 agent loops per turn.
    // This is a runaway BACKSTOP, not the everyday terminator: the loop's
    // normal exit is the boolean_gateway on `has_tool_calls` (agent is done).
    // @todo Replace this opaque wave-cap backstop with an explicit loop-count
    //   exit in the graph: extract the buffer `count` (ConversationAppend
    //   emits it, and it grows per loop, so it's a monotonic per-loop counter)
    //   via a Data Extractor → compare `count >= N` → boolean_gateway → END.
    //   That counts real agent loops (not waves) and is inspectable in the
    //   editor. Build it via the editor's Console/AI Assistant (don't hand-edit
    //   the workflow YAML); then this cap can drop back to a low safety value.
    'max_iterations' => 300,
    // Request-local checkpointer, EXPLICITLY: the operator's conversation
    // lives in the VISIBLE memory-backed buffer (the ConversationAppend
    // nodes / memory_read), not in a stategraph state channel — and job
    // outputs are entity-persisted — so a HITL resume needs no graph-state
    // restore. A persisted checkpoint would actively hurt: the orchestrator
    // resumes any NEW run from the thread's latest checkpoint, leaking
    // run-scoped state (iterationCount, isComplete) across turns (the
    // 2026-06-05 "same reply every turn" bug; upstream radar #8/#9).
    // Explicit (not omitted) because pin keys overwrite the stale `entity`
    // value stored on pre-existing console sessions, and because the local
    // contrib #8 patch defaults session runs to `entity`.
    'checkpointer_type' => 'memory',
    //
    // The loop's wall-clock budget in seconds (the stategraph maps the common
    // `timeout` setting to ExecutionConfig::maxExecutionTime). Session
    // settings WIN over the workflow/site default, so without this key the
    // loop would run unbudgeted. On breach the pipeline pauses with a
    // resumable system Pause signal (paused_reason=time_budget). The web
    // stack leaves headroom: PHP-FPM max_execution_time 600s, nginx
    // fastcgi_read_timeout 10m (see .ddev/php/timeouts.ini).
    'timeout' => 300,
  ];

  /**
   * FlowDrop services this dispatcher needs at runtime.
   */
  private const REQUIRED_SERVICES = [
    'flowdrop_session.service',
    'flowdrop_session.turn_service',
    'flowdrop_interrupt.manager',
  ];

  public function __construct(
    private readonly LoggerInterface $logger,
    private readonly WorkflowCatalog $catalog,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function dispatch(string $message, string $threadId, string $flow, ?string $workflow = NULL, array $clientContext = []): \Generator {
    foreach (self::REQUIRED_SERVICES as $sid) {
      if (!\Drupal::hasService($sid)) {
        yield ChatEvent::result('FlowDrop is not enabled, so this flow can\'t run yet.');
        return;
      }
    }

    $sessions = \Drupal::service('flowdrop_session.service');
    $turns = \Drupal::service('flowdrop_session.turn_service');
    $interrupts = \Drupal::service('flowdrop_interrupt.manager');

    $workflowId = $this->workflowForFlow($flow, $workflow);
    $workflow = \Drupal::entityTypeManager()
      ->getStorage('flowdrop_workflow')
      ->load($workflowId);
    if ($workflow === NULL) {
      $this->logger->warning('FlowDrop workflow @id not found.', ['@id' => $workflowId]);
      yield ChatEvent::result('That flow is not installed yet.');
      return;
    }

    yield ChatEvent::status('Starting the FlowDrop workflow…', ['flow' => $flow]);

    // One FlowDrop session per console thread (reused), so repeated turns
    // don't spawn a new session each time.
    $session = $this->sessionForThread($sessions, $interrupts, $workflow, $threadId);

    try {
      // The wait path executes the turn inline within this request (we hold
      // it open to stream SSE anyway) and returns the final stop status with
      // the assistant write-back ids.
      // Per-turn client context (the page studio's draft schema, the brand
      // console's preview draft, …) rides in as the workflow's declared
      // `variables` input — each agent maps it into a system-prompt
      // PromptTemplate node. Already filtered to non-empty by the controller.
      // It lives only in turn state — the persisted conversation buffer is
      // untouched, and no value leaks onto an ambient side channel.
      $inputs = $clientContext;
      $turn = $turns->executeTurn(
        (string) $session->id(),
        $message,
        new TurnOptions(wait: TRUE, inputs: $inputs),
      );
    }
    catch (ConcurrentTurnException) {
      yield ChatEvent::error('The operator is still working on your previous request — give it a moment and try again.');
      return;
    }
    catch (\Throwable $e) {
      // Wait-path execution failures propagate rather than folding into the
      // result; turn them into a chat error instead of a broken stream.
      $this->logger->error('FlowDrop turn failed: @m', ['@m' => $e->getMessage()]);
      yield ChatEvent::error('The flow hit an unexpected error. Please try again.');
      return;
    }

    switch ($turn->status) {
      case $turn::STATUS_AWAITING_INPUT:
        // The workflow paused at an interrupt node (HITL): the input request
        // IS the assistant's reply for this turn.
        $pending = $interrupts->getPendingInterruptsForSession((int) $session->id());
        if ($pending !== []) {
          $interrupt = reset($pending);
          yield ChatEvent::interrupt(
            $interrupt->uuid(),
            $interrupt->getMessage(),
            $interrupt->getSchema(),
            ['session_id' => (int) $session->id(), 'flow' => $flow],
          );
          // No RESULT — the turn pauses here. The console posts the answer to
          // the interrupt endpoint, which resumes the workflow and streams
          // the rest.
          return;
        }
        // Awaiting input but no pending interrupt to render — surface what
        // the session has rather than a dead stream.
        yield from $this->resultEvents($this->finalAssistantText($sessions, (int) $session->id()));
        return;

      case $turn::STATUS_PAUSED:
        $this->logger->notice('FlowDrop turn paused (pipeline @id, reason @reason).', [
          '@id' => $turn->pipelineId ?? '?',
          '@reason' => $this->pausedReason($turn->pipelineId),
        ]);
        yield ChatEvent::result(
          'The operator paused before finishing (it hit its run budget). '
          . 'Send a follow-up message to pick the task back up.'
        );
        return;

      case $turn::STATUS_FAILED:
        yield ChatEvent::error('The flow failed to complete. Please try again.');
        return;

      default:
        // STATUS_COMPLETED (and any future statuses) → the assistant
        // write-back is the result. A workflow may instead write back a widget
        // envelope, which resultEvents() unwraps into a tool-call + summary.
        //
        // When the operator runs as an agent, a workflow-as-tool's widget
        // envelope is NOT the final reply — it's buried in an Invoke node's
        // tool results and fed back to the agent, which answers in prose. Surface
        // those tool-produced widgets first (so the card renders inline), then
        // the agent's prose. The direct path has no Invoke jobs, so this is a
        // no-op there and resultEvents() still does the unwrapping.
        $text = $this->assistantText($turn->assistantMessageIds);
        if ($text === '') {
          $text = $this->finalAssistantText($sessions, (int) $session->id());
        }
        $widgets = $this->harvestTurnWidgets($turn->pipelineId);
        foreach ($widgets as $widget) {
          yield ChatEvent::toolCall($widget['widget'], $widget['payload']);
        }
        $this->persistTurnWidgets((int) $session->id(), $widgets, $turn->assistantMessageIds);
        yield from $this->resultEvents($text);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function resume(string $interruptId, mixed $response, string $threadId): \Generator {
    foreach (self::REQUIRED_SERVICES as $sid) {
      if (!\Drupal::hasService($sid)) {
        yield ChatEvent::error('FlowDrop is not enabled, so this flow can\'t resume.');
        return;
      }
    }

    $interrupts = \Drupal::service('flowdrop_interrupt.manager');
    $sessions = \Drupal::service('flowdrop_session.service');

    $interrupt = $interrupts->getInterrupt($interruptId);
    if ($interrupt === NULL) {
      yield ChatEvent::error('That choice has expired or was already answered.');
      return;
    }
    // Capture the session before resolving (the field persists either way).
    $sessionId = (int) ($interrupt->getSessionId() ?? 0);
    // Capture the paused pipeline NOW, before resolving: a workflow-as-tool that
    // produced a widget envelope runs in this same pipeline once resumed (the
    // operator's confirmation gate pauses here, then the Invoke node runs), and
    // the session's current_pipeline_id is cleared on completion — so this is the
    // only reliable handle for the post-resume widget harvest below.
    $pipelineId = $interrupt->getPipelineId();

    yield ChatEvent::status('Recording your choice…', ['flow' => 'flowdrop']);

    // Resolving dispatches an event that resumes the pipeline synchronously
    // (routed to the workflow's declared orchestrator via the executor
    // resolver, so a stategraph agent loop resumes as a stategraph).
    $interrupts->resolveInterrupt($interruptId, $response, (int) \Drupal::currentUser()->id());

    // The flow may pause again (chained HITL) or have finished.
    $pending = $sessionId !== 0 ? $interrupts->getPendingInterruptsForSession($sessionId) : [];
    if ($pending !== []) {
      $next = reset($pending);
      yield ChatEvent::interrupt(
        $next->uuid(),
        $next->getMessage(),
        $next->getSchema(),
        ['session_id' => $sessionId, 'flow' => 'flowdrop'],
      );
      return;
    }

    // Surface any tool-produced widgets first (e.g. an approved weather lookup),
    // then the agent's prose — mirrors the completed path in dispatch().
    $widgets = $this->harvestTurnWidgets($pipelineId);
    foreach ($widgets as $widget) {
      yield ChatEvent::toolCall($widget['widget'], $widget['payload']);
    }
    $this->persistTurnWidgets($sessionId, $widgets, []);
    yield from $this->resultEvents($this->finalAssistantText($sessions, $sessionId));
  }

  /**
   * The workflow id a console flow runs.
   *
   * The default lane runs the user's pick when it's in the admin-exposed
   * catalog, otherwise the configured default (`aincient_chat.settings`,
   * /admin/config/aincient-chat) — {@see WorkflowCatalog::resolve()} is the
   * gate, so an unexposed id can never be dispatched. The `/choose` demo lane
   * stays pinned. Note: the pick only matters for a NEW thread — an existing
   * thread reuses its session, and the session keeps the workflow it was
   * created with.
   */
  private function workflowForFlow(string $flow, ?string $requested): string {
    if ($flow === 'onboarding') {
      return self::ONBOARDING_WORKFLOW;
    }
    if ($flow === 'flowdrop') {
      return self::CHOICE_DEMO_WORKFLOW;
    }
    return $this->catalog->resolve($requested);
  }

  /**
   * The reusable FlowDrop session for a console thread, or a new one.
   *
   * Keyed 1:1 to the console thread (session name "console:<threadId>") so a
   * conversation maps to a single FlowDrop session instead of one per turn. Any
   * interrupt still pending from an abandoned earlier turn is cancelled so the
   * session is clean to reuse.
   */
  private function sessionForThread(object $sessions, object $interrupts, object $workflow, string $threadId): object {
    $name = 'console:' . $threadId;
    $storage = \Drupal::entityTypeManager()->getStorage('flowdrop_session');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('name', $name)
      ->sort('id', 'DESC')
      ->range(0, 1)
      ->execute();
    if ($ids !== []) {
      $session = $storage->load((int) reset($ids));
      if ($session !== NULL) {
        // Drop any abandoned pause so the session can take a new turn.
        $interrupts->cancelInterruptsForSession((int) $session->id());
        // Re-assert the pin on reused sessions. Only this dispatcher writes
        // console session settings, so the CURRENT pin is authoritative —
        // pin keys overwrite stale stored values (a session created under an
        // older cap/budget picks the new one up on its next turn); keys the
        // pin doesn't manage are preserved.
        $settings = $session->getOrchestratorSettings();
        $merged = self::ORCHESTRATOR_SETTINGS + $settings;
        if ($merged !== $settings) {
          $session->setOrchestratorSettings($merged)->save();
        }
        return $session;
      }
    }
    return $this->createPinnedSession($sessions, $workflow, $name);
  }

  /**
   * Create a console session with the agent-loop orchestrator pinned.
   *
   * Forces FlowDrop's stategraph orchestrator — the cyclic agent loop with
   * loop-back edges + checkpoints — and bounds the loop, overriding both the
   * workflow's own config and the site default. The session-level setting wins
   * (see SessionExecutionService::getOrchestratorSettings()), so the operator
   * workflow stays orchestrator-agnostic in config.
   */
  private function createPinnedSession(object $sessions, object $workflow, string $name): object {
    $session = $sessions->createSession($workflow, $name);
    $session->setOrchestratorSettings(self::ORCHESTRATOR_SETTINGS)->save();
    return $session;
  }

  /**
   * The concatenated text of the turn's assistant write-back messages.
   *
   * @param array<int, string> $messageIds
   *   Assistant message ids from the TurnResult.
   */
  private function assistantText(array $messageIds): string {
    if ($messageIds === []) {
      return '';
    }
    $storage = \Drupal::entityTypeManager()->getStorage('flowdrop_session_message');
    $texts = [];
    foreach ($messageIds as $id) {
      $message = $storage->load($id);
      if ($message !== NULL) {
        $text = trim((string) $message->getContent());
        if ($text !== '') {
          $texts[] = $text;
        }
      }
    }
    return implode("\n\n", $texts);
  }

  /**
   * Turn an assistant write-back into the chat events that render it.
   *
   * The generative-UI bridge: a workflow whose reply is a widget envelope
   * (`{"__widget__": <tool>, "payload": {…}}`) gets surfaced as a `tool_call`
   * frame carrying the structured payload as its `arguments` — so a registered
   * assistant-ui widget (e.g. `weather_card`) renders it inline — plus a plain
   * text RESULT (the summary) that persists as the readable turn. Any other
   * reply is a plain RESULT, exactly as before. Generic on `__widget__`, so the
   * same path serves future widgets, not just weather.
   *
   * @return array<int, \Drupal\aincient_chat\Event\ChatEvent>
   */
  private function resultEvents(string $text): array {
    $envelope = WidgetEnvelope::decode($text);
    if ($envelope === NULL) {
      return [ChatEvent::result($text)];
    }
    return [
      ChatEvent::toolCall($envelope['widget'], $envelope['payload']),
      ChatEvent::result($envelope['summary']),
    ];
  }

  /**
   * Widget envelopes any of a turn's tools produced.
   *
   * The tool-path arm of the generative-UI bridge. When the operator runs as an
   * agent, a workflow-as-tool that returns a `{"__widget__", "payload"}` envelope
   * does NOT surface it as the assistant reply — the envelope is recorded in an
   * Invoke node's tool results (a tool-role message fed back to the agent, which
   * then answers in prose). This walks the turn's pipeline jobs and pulls every
   * such envelope out of the Invoke results. The caller turns each into a live
   * `tool_call` frame (so the widget renders inline, independent of the agent's
   * prose) AND persists it on the session for reload {@see persistTurnWidgets}.
   * Generic on `__widget__`, like resultEvents().
   *
   * @return array<int, array{widget: string, payload: array, summary: string}>
   *   The widget envelopes the turn's tools produced (empty when none).
   */
  private function harvestTurnWidgets(?string $pipelineId): array {
    if ($pipelineId === NULL || $pipelineId === '') {
      return [];
    }
    $pipeline = \Drupal::entityTypeManager()
      ->getStorage('flowdrop_pipeline')
      ->load($pipelineId);
    if ($pipeline === NULL || !method_exists($pipeline, 'getJobs')) {
      return [];
    }
    $widgets = [];
    // The Brand merge node is deterministic and idempotent, but the stategraph
    // drains terminal-branch nodes across several waves — so it can leave more
    // than one identical brand_apply_slices job. Keep only the LAST one so the
    // turn shows exactly one merged brand card (the authoritative end state).
    $mergeWidget = NULL;
    foreach ($pipeline->getJobs() as $job) {
      $output = (string) ($job->get('output_data')->value ?? '');
      if ($output === '' || !str_contains($output, '__widget__')) {
        continue;
      }
      // The Brand merge node (brand_apply_slices) is NOT an Invoke: its output
      // is {"widget": "<brand_preview envelope>", …}, the turn's single
      // authoritative apply. Decode it directly — this is the PERSISTED source
      // of truth (Half B's live per-slice frames are transient, not harvested).
      if (method_exists($job, 'getMetadataValue')
        && $job->getMetadataValue('node_type_id') === 'aincient_flows_brand_apply_slices') {
        $data = json_decode($output, TRUE);
        $widgetJson = is_array($data) ? (string) ($data['widget'] ?? '') : '';
        $envelope = $widgetJson !== '' ? WidgetEnvelope::decode($widgetJson) : NULL;
        if ($envelope !== NULL) {
          $mergeWidget = $envelope;
        }
        continue;
      }
      // Cheap pre-filter: only Invoke results carry tool_results envelopes.
      if (!str_contains($output, 'tool_results')) {
        continue;
      }
      foreach ($this->widgetEnvelopesFromInvokeOutput($output) as $envelope) {
        $widgets[] = $envelope;
      }
    }
    if ($mergeWidget !== NULL) {
      $widgets[] = $mergeWidget;
    }
    return $widgets;
  }

  /**
   * Decode widget envelopes out of an Invoke node's serialized output.
   *
   * Invoke output is `{"tool_results": [{"name", "tool_call_id", "content"}, …]}`
   * where each `content` is itself a JSON string — typically the tool's
   * `{"message": "<envelope>", "status": …}`. Try the inner `message` first, then
   * the raw content, and keep whatever decodes to a valid envelope.
   *
   * @return array<int, array{widget: string, payload: array, summary: string}>
   */
  private function widgetEnvelopesFromInvokeOutput(string $output): array {
    $data = json_decode($output, TRUE);
    if (!is_array($data) || !is_array($data['tool_results'] ?? NULL)) {
      return [];
    }
    $envelopes = [];
    foreach ($data['tool_results'] as $result) {
      $content = $result['content'] ?? NULL;
      if (!is_string($content) || $content === '') {
        continue;
      }
      $inner = json_decode($content, TRUE);
      $candidates = [];
      if (is_array($inner) && isset($inner['message']) && is_string($inner['message'])) {
        $candidates[] = $inner['message'];
      }
      $candidates[] = $content;
      foreach ($candidates as $candidate) {
        $envelope = WidgetEnvelope::decode($candidate);
        if ($envelope !== NULL) {
          $envelopes[] = $envelope;
          break;
        }
      }
    }
    return $envelopes;
  }

  /**
   * Persist a turn's tool-produced widgets so they re-render on reload.
   *
   * Live, {@see harvestTurnWidgets} surfaces a tool-path widget as a transient
   * `tool_call` SSE frame — but the envelope lives only in the Invoke pipeline
   * job, and the persisted assistant message is the agent's plain prose. So on
   * reload nothing would tie the card to the thread. We stash each widget on the
   * session metadata under `ain_widgets`, keyed by the SEQUENCE NUMBER of the
   * turn's final assistant message, so {@see SessionThreadStore::threadTurns}
   * can re-attach it to that turn's bubble (the direct-path envelope, by
   * contrast, IS the assistant message and needs no metadata). The session is
   * re-loaded before the write so we don't clobber metadata the turn just wrote.
   *
   * @param int $sessionId
   *   The console session the turn ran on.
   * @param array<int, array{widget: string, payload: array, summary: string}> $widgets
   *   Harvested widget envelopes for the turn.
   * @param array<int, string> $assistantMessageIds
   *   The turn's assistant write-back ids (empty on the resume path).
   */
  private function persistTurnWidgets(int $sessionId, array $widgets, array $assistantMessageIds): void {
    if ($widgets === []) {
      return;
    }
    $seq = $this->targetAssistantSequence($assistantMessageIds, $sessionId);
    if ($seq === NULL) {
      return;
    }
    $storage = \Drupal::entityTypeManager()->getStorage('flowdrop_session');
    $session = $storage->load($sessionId);
    if ($session === NULL) {
      return;
    }
    $meta = $session->getMetadata();
    $meta['ain_widgets'][(string) $seq] = array_map(
      static fn(array $w): array => ['widget' => $w['widget'], 'payload' => $w['payload']],
      $widgets,
    );
    $session->setMetadata($meta)->save();
  }

  /**
   * The sequence number of the assistant message a turn's widgets belong to.
   *
   * The widgets attach to the turn's FINAL assistant message (the prose the
   * agent wrote after the tool ran), so they render in that same bubble. On the
   * dispatch path we get the turn's write-back ids; on the resume path (no ids)
   * we fall back to the session's latest assistant message, which is the prose
   * the just-resumed run wrote.
   *
   * @param array<int, string> $assistantMessageIds
   *   The turn's assistant write-back ids, if known.
   * @param int $sessionId
   *   The console session id (the resume-path fallback's lookup scope).
   */
  private function targetAssistantSequence(array $assistantMessageIds, int $sessionId): ?int {
    $storage = \Drupal::entityTypeManager()->getStorage('flowdrop_session_message');
    $sequences = [];
    foreach ($assistantMessageIds as $id) {
      $message = $storage->load($id);
      if ($message !== NULL && (string) $message->getRole() === 'assistant'
        && trim((string) $message->getContent()) !== '') {
        $sequences[] = (int) $message->getSequenceNumber();
      }
    }
    if ($sequences !== []) {
      return max($sequences);
    }
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('session_id', $sessionId)
      ->condition('role', 'assistant')
      ->sort('sequence_number', 'DESC')
      ->range(0, 1)
      ->execute();
    if ($ids === []) {
      return NULL;
    }
    $message = $storage->load((int) reset($ids));
    return $message !== NULL ? (int) $message->getSequenceNumber() : NULL;
  }

  /**
   * The machine-readable pause reason stamped on a pipeline, if any.
   */
  private function pausedReason(?string $pipelineId): string {
    if ($pipelineId === NULL || $pipelineId === '') {
      return 'unknown';
    }
    $pipeline = \Drupal::entityTypeManager()
      ->getStorage('flowdrop_pipeline')
      ->load($pipelineId);
    if ($pipeline === NULL) {
      return 'unknown';
    }
    return (string) ($pipeline->getExecutionContext()['paused_reason'] ?? 'unknown');
  }

  /**
   * The last assistant message in a session, or a fallback.
   *
   * `latest: TRUE` is load-bearing: getMessages() defaults to the FIRST 100
   * rows of the session (ASC + limit), so on a long-running thread the
   * "final" text silently came from a stale window — a resolve continuation
   * then streamed an old turn's reply as the outcome (the two-tab approve
   * bug). Anchoring on the end of the range reads the genuinely-latest tail.
   */
  private function finalAssistantText(object $sessions, int $sessionId): string {
    $text = '';
    foreach ($sessions->getMessages($sessionId, NULL, 100, NULL, TRUE) as $message) {
      if ((string) $message->getRole() === 'assistant') {
        $text = (string) $message->getContent();
      }
    }
    return $text !== '' ? $text : 'The flow finished.';
  }

}
