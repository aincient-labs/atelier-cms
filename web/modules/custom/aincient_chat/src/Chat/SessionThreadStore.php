<?php

declare(strict_types=1);

namespace Drupal\aincient_chat\Chat;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * The console's conversation store, backed by FlowDrop sessions.
 *
 * Single-lane architecture: a console thread IS a FlowDrop session running the
 * operator workflow (decision 2026-06-02). The session — created/reused per
 * thread by {@see FlowDropDispatcher} under the name "console:<threadId>" and
 * owned by the acting user — holds the canonical conversation (user/assistant
 * messages, system/log noise, pending interrupts). This service is the read
 * model the sidebar/history/archive/delete endpoints query, replacing the old
 * ChatTurn-backed store.
 *
 * FlowDrop services are resolved lazily (the aincient_chat kernel test does not
 * enable FlowDrop); when they're absent every method degrades to an empty/no-op
 * result rather than throwing.
 */
final class SessionThreadStore {

  /**
   * The session-name prefix that marks a FlowDrop session as a console thread.
   */
  private const NAME_PREFIX = 'console:';

  /**
   * Metadata key flagging a thread as archived (hidden from the main list).
   */
  private const ARCHIVED_KEY = 'ain_archived';

  /**
   * Metadata key flagging a thread as LOCKED — wrapped up (read-only) but still
   * shown in the sidebar (distinct from ARCHIVED_KEY, which hides it). Set when a
   * page is published or the user explicitly finishes a thread; the console
   * replaces the composer with the celebration end-state and the chat turn is
   * refused server-side, so a done conversation stops burning tokens.
   */
  private const LOCKED_KEY = 'ain_locked';

  /**
   * Metadata key carrying the published page a locked thread produced
   * ({url?, node?}), so the celebration pane can link to the live page even on a
   * cold reload. Cleared when the thread is reopened.
   */
  private const PUBLISHED_KEY = 'ain_published';

  /**
   * Metadata key carrying the STUDIO-GIVEN thread title (Atelier study 02,
   * Plate 8): the outcome of the first exchange in ≤5 words, minted once by
   * {@see ThreadNamer} after the first assistant reply. When present it
   * replaces the raw-first-message fallback in {@see self::listThreads()} —
   * the history reads as a ledger of work, not a log of prompts.
   */
  private const TITLE_KEY = 'ain_title';

  /**
   * Metadata key homing a thread to the resource it works on — {nid, langcode},
   * IDENTITY ONLY (never live page state; the draft/lock/revision are always
   * re-fetched fresh, because resource state is durable + node-owned, never
   * thread-owned). This is the linchpin of resource-first navigation: it's what
   * buckets a thread under its Node(nid, lang) room. Absent on General/singleton
   * threads and on threads that never touched a saved node. Home-once: a thread
   * is born on a resource and never migrates (studio-navigation.md §3.4).
   */
  private const WORKING_NODE_KEY = 'ain_working_node';

  /**
   * Roles surfaced as console turns; system/log/tool messages are dropped.
   */
  private const VISIBLE_ROLES = ['user', 'assistant'];

  /**
   * Interrupt types that are HITL questions the console renders as turns.
   *
   * Outward control signals (pause/timer/cancel…) never become conversation.
   */
  private const HITL_TYPES = ['confirmation', 'choice', 'text', 'form', 'schema_form'];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * The user's console threads (newest activity first), for the sidebar.
   *
   * @return array<int, array<string, mixed>>
   *   One row per thread: remoteId, title, lastActivity, status, workflow
   *   (the session's pinned workflow as {id, label} — the console shows it in
   *   the flow picker and as the assistant's speaker caption).
   */
  public function listThreads(int $uid): array {
    if (!$this->sessionsAvailable()) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('flowdrop_session');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $uid)
      ->condition('name', self::NAME_PREFIX, 'STARTS_WITH')
      ->sort('changed', 'DESC')
      ->execute();
    if (!$ids) {
      return [];
    }

    $sessions = $storage->loadMultiple($ids);
    $labels = $this->workflowLabels(array_map(
      static fn(object $session): string => (string) $session->getWorkflowId(),
      $sessions,
    ));

    $threads = [];
    foreach ($sessions as $session) {
      $meta = $session->getMetadata();
      // The studio-given name wins; the raw first message is only the fallback
      // for threads the namer hasn't (or couldn't) title yet.
      $title = trim((string) ($meta[self::TITLE_KEY] ?? ''));
      if ($title === '') {
        $title = $this->firstUserText((int) $session->id());
      }
      $workflowId = (string) $session->getWorkflowId();
      $threads[] = [
        'remoteId' => $this->threadIdOf($session),
        'title' => $title !== '' ? mb_strimwidth($title, 0, 60, '…') : 'Conversation',
        'lastActivity' => (int) $session->getChangedTime(),
        'status' => !empty($meta[self::ARCHIVED_KEY]) ? 'archived' : 'regular',
        // Wrapped-up state (read-only, still listed). Separate from `status` so a
        // thread can be locked without being hidden.
        'locked' => !empty($meta[self::LOCKED_KEY]),
        'published' => is_array($meta[self::PUBLISHED_KEY] ?? NULL) ? $meta[self::PUBLISHED_KEY] : NULL,
        // The resource this thread is homed to ({nid, langcode}) — what buckets
        // it under a Node room in resource-first navigation. NULL for
        // General/singleton threads and threads that never touched a saved node.
        'workingNode' => $this->readWorkingNode($meta),
        'workflow' => [
          'id' => $workflowId,
          'label' => $labels[$workflowId] ?? $workflowId,
        ],
      ];
    }
    // loadMultiple() doesn't preserve the query's changed-DESC order — re-sort.
    usort($threads, static fn(array $a, array $b): int => $b['lastActivity'] <=> $a['lastActivity']);
    // Give each homed thread's working node a human title so the console can
    // label its Node(nid, lang) room ("Home", "About") instead of a bare nid —
    // one bulk node load for the whole list rather than N per-thread loads.
    $this->attachWorkingNodeTitles($threads);
    return $threads;
  }

  /**
   * Fill in each thread's working-node `title` from a single bulk node load.
   *
   * The room tree labels a Node room by its node title; the working node itself
   * stores identity only ({nid, langcode}), so the title is resolved here at
   * list time. Titles are read in the thread's homed langcode when a translation
   * exists, else the node's default. A missing/deleted node leaves the title
   * absent (the console falls back to "Page {nid}").
   *
   * @param array<int, array<string, mixed>> $threads
   *   The thread rows, mutated in place to add workingNode['title'].
   */
  private function attachWorkingNodeTitles(array &$threads): void {
    $nids = [];
    foreach ($threads as $row) {
      $wn = $row['workingNode'] ?? NULL;
      if (is_array($wn) && !empty($wn['nid'])) {
        $nids[(int) $wn['nid']] = TRUE;
      }
    }
    if (!$nids) {
      return;
    }
    try {
      $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple(array_keys($nids));
    }
    catch (\Throwable $e) {
      // Node module absent (kernel test) or storage error — leave titles off.
      return;
    }
    foreach ($threads as &$row) {
      $wn = $row['workingNode'] ?? NULL;
      if (!is_array($wn) || empty($wn['nid'])) {
        continue;
      }
      $node = $nodes[(int) $wn['nid']] ?? NULL;
      if ($node === NULL) {
        continue;
      }
      $langcode = $wn['langcode'] ?? NULL;
      if (is_string($langcode) && $langcode !== '' && $node->hasTranslation($langcode)) {
        $node = $node->getTranslation($langcode);
      }
      $row['workingNode']['title'] = (string) $node->label();
    }
    unset($row);
  }

  /**
   * One thread's turns, mapped to the console's turn shape.
   *
   * Only user/assistant messages become turns, interleaved with the session's
   * HITL interrupts so a pause reads as two distinct chat bubbles: the REQUEST
   * (an assistant turn carrying the interrupt id + schema, timestamped at
   * creation — re-hydrates the choice widget) and, once resolved, the ANSWER
   * (a user turn with the human-readable choice, timestamped at resolution).
   * That keeps "when it was requested" and "when it was approved" trackable
   * across reloads instead of collapsing into the widget.
   *
   * @return array<int, array<string, mixed>>
   *   One row per turn: id, role, text, status, routed_to, created, interrupt?.
   */
  public function threadTurns(string $threadId, int $uid): array {
    $session = $this->loadSession($threadId, $uid);
    if ($session === NULL) {
      return [];
    }
    $sessionId = (int) $session->id();
    $workflowId = $session->getWorkflowId();

    $messageStorage = $this->entityTypeManager->getStorage('flowdrop_session_message');
    $ids = $messageStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('session_id', $sessionId)
      ->sort('sequence_number', 'ASC')
      ->execute();
    $messages = $ids ? $messageStorage->loadMultiple($ids) : [];
    // loadMultiple() keys by id, not sequence — re-sort to conversation order.
    usort($messages, static fn($a, $b): int => $a->getSequenceNumber() <=> $b->getSequenceNumber());

    // Tool-path widgets the dispatcher stashed on the session, keyed by the
    // sequence number of the assistant message they belong to (the agent's
    // prose hides them, so they can't be recovered from the message text the
    // way a direct-path envelope can — see
    // FlowDropDispatcher::persistTurnWidgets).
    $metaWidgets = $session->getMetadata()['ain_widgets'] ?? [];
    // A session in operator/tool mode (the agent invokes a widget workflow as a
    // tool) has tool widgets stashed here. In that mode the widget workflow's
    // own chat_output ALSO lands a raw envelope as an assistant message in the
    // shared session — a sub-workflow artifact, NOT the operator's reply (which
    // is the prose turn the card is metadata-attached to). We skip those raw
    // envelopes so the card renders once. With no tool widgets the session runs
    // the widget workflow DIRECTLY: there the envelope message IS the reply, so
    // we decode it into a card instead.
    $toolMode = $metaWidgets !== [];

    // Rows carry a sort key [timestamp, bias, tiebreak]. Messages bias 0.
    // A request biases +1 (it ends the turn its user message started, so it
    // lands after same-second messages). An answer anchors to its REQUEST's
    // second (bias +2, directly after the card): the session is paused
    // between the two, so everything timestamped later is post-resume —
    // and `resolved_at` can't anchor it because FlowDrop stamps it after the
    // synchronous resume finishes, i.e. LATER than the resumed messages.
    $rows = [];
    foreach ($messages as $message) {
      $role = $message->getRole();
      if (!in_array($role, self::VISIBLE_ROLES, TRUE)) {
        continue;
      }
      // Skip messages produced by a NESTED sub-workflow execution. A specialist
      // sub-workflow's `chat_output` (e.g. the Brand agent's per-axis colour /
      // typography / shape slices, `{"tokens_json":…}`) is plumbing FOR the main
      // flow — the merge node folds the slices into the one authoritative
      // brand_preview card carried by the operator's own prose turn. These raw
      // slices are not user turns; replaying them dumps unreadable JSON into the
      // thread. The main flow stamps no `workflow_id` (its chat_output is the
      // user-facing reply), so a present-and-different id marks a sub-workflow
      // artifact. (Tool-mode widget envelopes are also nested, and are already
      // re-surfaced via `ain_widgets` on the prose turn — see below.)
      $subWorkflow = (string) ($message->getMessageMetadata()['workflow_id'] ?? '');
      if ($subWorkflow !== '' && $subWorkflow !== (string) $workflowId) {
        continue;
      }
      $text = (string) $message->getContent();
      if ($role === 'user') {
        $text = $this->stripSlashPrefix($text);
      }

      // Re-attach generative-UI widgets so the card re-renders inline on reload
      // (the live stream puts the tool-call part(s) above the text in one
      // bubble; the frontend rebuilds the same shape from `widgets`).
      $widgets = [];
      if ($role === 'assistant') {
        $envelope = WidgetEnvelope::decode($text);
        if ($envelope !== NULL) {
          // A raw widget envelope. In tool mode it's a redundant sub-workflow
          // artifact (the metadata-attached prose turn carries the card) — skip
          // it. Direct mode: it IS the reply — render the card ALONE, dropping
          // the summary text so the card isn't echoed in prose.
          if ($toolMode) {
            continue;
          }
          $widgets[] = ['widget' => $envelope['widget'], 'payload' => $envelope['payload']];
          $text = '';
        }
        else {
          // A prose turn — attach any tool-path widget keyed to it.
          foreach ($metaWidgets[(string) $message->getSequenceNumber()] ?? [] as $widget) {
            if (isset($widget['widget'], $widget['payload'])
              && is_string($widget['widget']) && is_array($widget['payload'])) {
              $widgets[] = ['widget' => $widget['widget'], 'payload' => $widget['payload']];
            }
          }
        }
      }

      // Drop empty turns, but keep a card-only turn (no text, has a widget).
      if (trim($text) === '' && $widgets === []) {
        continue;
      }

      $turn = [
        'id' => $message->getSequenceNumber(),
        'role' => $role,
        'text' => $text,
        'status' => $message->getStatus(),
        'routed_to' => $workflowId,
        'created' => $message->getTimestamp(),
      ];
      if ($widgets !== []) {
        $turn['widgets'] = $widgets;
      }
      $rows[] = [
        'key' => [$message->getTimestamp(), 0, $message->getSequenceNumber()],
        'turn' => $turn,
      ];
    }

    foreach ($this->hitlInterrupts($sessionId) as $interrupt) {
      $schema = $interrupt->getSchema();
      $status = $interrupt->getStatus()->value;
      $resolved = $interrupt->isResolved();
      $created = $interrupt->getCreatedTime();
      $answer = $resolved ? $interrupt->getResponseData() : NULL;

      $rows[] = [
        'key' => [$created, 1, (int) $interrupt->id()],
        'turn' => [
          'id' => 'hitl-' . $interrupt->id(),
          'role' => 'assistant',
          'text' => $interrupt->getMessage(),
          'status' => $interrupt->isPending() ? 'awaiting' : 'done',
          'routed_to' => $workflowId,
          'created' => $created,
          'interrupt' => [
            'uuid' => $interrupt->uuid(),
            'prompt' => $interrupt->getMessage(),
            'schema' => $schema,
            'status' => $status,
            'resolved' => $resolved,
            'answer' => $answer,
          ],
        ],
      ];

      if ($resolved) {
        $resolvedAt = max((int) ($interrupt->getResolvedAt() ?? $created), $created);
        // The answer is an ACTION, not typed chat: `kind` flags it so the
        // console renders an event chip ("admin approved · 8:56 PM") instead
        // of a user bubble. `by` comes from the interrupt's resolver — an
        // approval can happen outside this thread (e.g. a pending-interrupts
        // inbox), so the actor is whoever actually resolved it. Sorts right
        // after its request card; `created` keeps the resolution time for
        // display.
        $rows[] = [
          'key' => [$created, 2, (int) $interrupt->id()],
          'turn' => [
            'id' => 'hitl-' . $interrupt->id() . '-answer',
            'role' => 'user',
            'kind' => 'hitl_answer',
            'verb' => $this->answerVerb($schema, $answer),
            'by' => $this->resolverName($interrupt),
            'text' => $this->answerLabel($schema, $answer),
            'status' => 'done',
            'routed_to' => $workflowId,
            'created' => $resolvedAt,
          ],
        ];
      }
    }

    usort($rows, static fn(array $a, array $b): int => $a['key'] <=> $b['key']);
    return array_column($rows, 'turn');
  }

  /**
   * Flag a thread (un)archived. Returns the number of sessions touched (0/1).
   */
  public function archive(string $threadId, int $uid, bool $archived): int {
    $session = $this->loadSession($threadId, $uid);
    if ($session === NULL) {
      return 0;
    }
    $meta = $session->getMetadata();
    $meta[self::ARCHIVED_KEY] = $archived;
    $session->setMetadata($meta)->save();
    return 1;
  }

  /**
   * (Un)lock a thread — wrap it up read-only, auto-archiving it out of the room.
   *
   * This is the one SEAL primitive (studio-navigation.md §4): both `clear` (a
   * mid-work fresh start) and publish route through it. Publish is just a seal
   * with a milestone marker — it passes the published page ({url?, node?}) so
   * the celebration pane can link to it on reload; a plain clear passes NULL.
   * Unlocking clears the marker. The working-node homing key is left untouched
   * (identity survives the seal), so a fresh thread started on the same resource
   * re-homes to it. Returns the number of sessions touched (0/1).
   *
   * Sealing auto-archives in the SAME save (decision D8): a wrapped-up thread is
   * read-only history, no longer listed in its room — the two flags flip together
   * atomically, so the console never has to fire a second, racing /archive request
   * (which would clobber this one, both writing the whole metadata array).
   *
   * @param array<string, string>|null $published
   *   The published page reference to remember when locking, or NULL.
   */
  public function lock(string $threadId, int $uid, bool $locked, ?array $published = NULL): int {
    $session = $this->loadSession($threadId, $uid);
    if ($session === NULL) {
      return 0;
    }
    $meta = $session->getMetadata();
    $meta[self::LOCKED_KEY] = $locked;
    // Seal ⇒ archive (D8). Left set on unseal — the lifecycle is one-way (Reopen
    // is retired); to continue, a fresh thread is started on the same resource.
    if ($locked) {
      $meta[self::ARCHIVED_KEY] = TRUE;
    }
    if ($locked && $published) {
      $meta[self::PUBLISHED_KEY] = $published;
    }
    if (!$locked) {
      unset($meta[self::PUBLISHED_KEY]);
    }
    $session->setMetadata($meta)->save();
    return 1;
  }

  /**
   * Whether a thread is locked (wrapped up) — the chat turn guard reads this to
   * refuse new turns on a finished conversation.
   */
  public function isLocked(string $threadId, int $uid): bool {
    $session = $this->loadSession($threadId, $uid);
    return $session !== NULL && !empty($session->getMetadata()[self::LOCKED_KEY]);
  }

  /**
   * Home a thread to the resource (node) it works on — identity only.
   *
   * Home-once (studio-navigation.md §3.4): a thread is born on a resource and
   * NEVER migrates. The first real nid a thread touches sticks; later turns on
   * the same page are a no-op, and a turn carrying a DIFFERENT nid is ignored (a
   * thread's resource can't change under it). A thread that began on a fresh
   * (unsaved) page homes the moment that page is first saved and a turn carries
   * the new nid. Never stores page state — only {nid, langcode}; the draft,
   * lock, and revision are always re-fetched fresh.
   *
   * @return int
   *   1 if the thread was homed, 0 if nothing changed (already homed / no nid /
   *   no session yet).
   */
  public function setWorkingNode(string $threadId, int $uid, int $nid, string $langcode = ''): int {
    if ($nid <= 0) {
      return 0;
    }
    $session = $this->loadSession($threadId, $uid);
    if ($session === NULL) {
      return 0;
    }
    $meta = $session->getMetadata();
    // Home-once: a real nid is sticky, never migrated off.
    if ($this->readWorkingNode($meta) !== NULL) {
      return 0;
    }
    $meta[self::WORKING_NODE_KEY] = [
      'nid' => $nid,
      'langcode' => $langcode !== '' ? $langcode : NULL,
    ];
    $session->setMetadata($meta)->save();
    return 1;
  }

  /**
   * The resource a thread is homed to ({nid, langcode}), or NULL if unhomed.
   *
   * @return array{nid: int, langcode: string|null}|null
   */
  public function workingNode(string $threadId, int $uid): ?array {
    $session = $this->loadSession($threadId, $uid);
    return $session === NULL ? NULL : $this->readWorkingNode($session->getMetadata());
  }

  /**
   * Normalise stored working-node metadata into {nid, langcode} (or NULL).
   *
   * @param array<string, mixed> $meta
   *   The session metadata.
   *
   * @return array{nid: int, langcode: string|null}|null
   */
  private function readWorkingNode(array $meta): ?array {
    $raw = $meta[self::WORKING_NODE_KEY] ?? NULL;
    if (!is_array($raw) || empty($raw['nid'])) {
      return NULL;
    }
    $lang = $raw['langcode'] ?? NULL;
    return [
      'nid' => (int) $raw['nid'],
      'langcode' => is_string($lang) && $lang !== '' ? $lang : NULL,
    ];
  }

  /**
   * Delete a thread's session (and its messages). Returns 0/1.
   */
  public function delete(string $threadId, int $uid): int {
    $session = $this->loadSession($threadId, $uid);
    if ($session === NULL) {
      return 0;
    }
    $service = $this->sessionService();
    if ($service !== NULL) {
      // Cascades to messages (and any session-scoped state).
      $service->deleteSession($session);
    }
    else {
      $session->delete();
    }
    return 1;
  }

  /**
   * Load the FlowDrop session for a console thread owned by the given user.
   */
  /**
   * The studio-given title, or '' when the thread is still unnamed.
   */
  public function title(string $threadId, int $uid): string {
    $session = $this->loadSession($threadId, $uid);
    if ($session === NULL) {
      return '';
    }
    return trim((string) ($session->getMetadata()[self::TITLE_KEY] ?? ''));
  }

  /**
   * Store the studio-given thread title (overwrites a previous one).
   */
  public function setTitle(string $threadId, int $uid, string $title): bool {
    $session = $this->loadSession($threadId, $uid);
    if ($session === NULL) {
      return FALSE;
    }
    $meta = $session->getMetadata();
    $meta[self::TITLE_KEY] = $title;
    $session->setMetadata($meta)->save();
    return TRUE;
  }

  /**
   * The thread's first exchange — first user message + first assistant reply.
   *
   * The namer's raw material: both must exist (a thread is only nameable after
   * one full exchange), else NULL.
   *
   * @return array{user: string, assistant: string}|null
   *   The two texts, or NULL when the exchange hasn't completed.
   */
  public function firstExchange(string $threadId, int $uid): ?array {
    $session = $this->loadSession($threadId, $uid);
    if ($session === NULL) {
      return NULL;
    }
    $user = $this->firstUserText((int) $session->id());
    $assistant = $this->firstRoleText((int) $session->id(), 'assistant');
    return $user !== '' && $assistant !== '' ? ['user' => $user, 'assistant' => $assistant] : NULL;
  }

  /**
   * The first message of a session in a given role, or ''.
   */
  private function firstRoleText(int $sessionId, string $role): string {
    $storage = $this->entityTypeManager->getStorage('flowdrop_session_message');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('session_id', $sessionId)
      ->condition('role', $role)
      ->sort('sequence_number', 'ASC')
      ->range(0, 1)
      ->execute();
    if (!$ids) {
      return '';
    }
    $message = $storage->load((int) reset($ids));
    return $message ? trim((string) $message->get('content')->value) : '';
  }

  private function loadSession(string $threadId, int $uid): ?object {
    if (!$this->sessionsAvailable()) {
      return NULL;
    }
    $storage = $this->entityTypeManager->getStorage('flowdrop_session');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('name', self::NAME_PREFIX . $threadId)
      ->condition('uid', $uid)
      ->sort('id', 'DESC')
      ->range(0, 1)
      ->execute();
    return $ids ? $storage->load((int) reset($ids)) : NULL;
  }

  /**
   * The thread id (the part after "console:") of a session.
   */
  private function threadIdOf(object $session): string {
    return substr($session->getName(), strlen(self::NAME_PREFIX));
  }

  /**
   * The first user message of a session (slash-prefix stripped), for the title.
   */
  private function firstUserText(int $sessionId): string {
    $storage = $this->entityTypeManager->getStorage('flowdrop_session_message');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('session_id', $sessionId)
      ->condition('role', 'user')
      ->sort('sequence_number', 'ASC')
      ->range(0, 1)
      ->execute();
    if (!$ids) {
      return '';
    }
    $message = $storage->load((int) reset($ids));
    return $message ? $this->stripSlashPrefix((string) $message->get('content')->value) : '';
  }

  /**
   * Strip a leading flow-pinning slash command (e.g. "/op ", "/choose ").
   */
  private function stripSlashPrefix(string $text): string {
    return trim((string) preg_replace('#^\s*/(op|operator|choose|flowdrop)\b\s*#i', '', $text));
  }

  /**
   * All HITL question interrupts of a session (pending AND settled), oldest
   * first. Settled ones rebuild the request/answer bubbles; cancelled/expired
   * ones keep the request visible (the question WAS asked) in a dismissed
   * state.
   *
   * @return array<int, object>
   */
  private function hitlInterrupts(int $sessionId): array {
    if (!$this->entityTypeManager->hasDefinition('flowdrop_interrupt')) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('flowdrop_interrupt');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('session_id', $sessionId)
      ->condition('direction', 'outward')
      ->condition('type', self::HITL_TYPES, 'IN')
      ->sort('created', 'ASC')
      ->execute();
    return $ids ? array_values($storage->loadMultiple($ids)) : [];
  }

  /**
   * Whether an interrupt schema is a boolean Approve/Decline confirmation.
   */
  private static function isConfirmation(array $schema): bool {
    return ($schema['presentation'] ?? NULL) === 'confirmation'
      || ($schema['type'] ?? NULL) === 'boolean';
  }

  /**
   * Whether an answer counts as the affirmative confirmation button.
   */
  private static function isAffirmative(mixed $answer): bool {
    return $answer === TRUE || $answer === 'true' || $answer === '1' || $answer === 1;
  }

  /**
   * The action verb of an answer: approved/declined for confirmations,
   * "chose" for everything else (the chip then quotes the chosen label).
   */
  private function answerVerb(array $schema, mixed $answer): string {
    if (self::isConfirmation($schema)) {
      return self::isAffirmative($answer) ? 'approved' : 'declined';
    }
    return 'chose';
  }

  /**
   * The display name of whoever resolved an interrupt ('' when unknown).
   */
  private function resolverName(object $interrupt): string {
    $uid = $interrupt->getResolvedBy();
    if (!$uid) {
      return '';
    }
    $account = $this->entityTypeManager->getStorage('user')->load($uid);
    return $account ? (string) $account->getDisplayName() : '';
  }

  /**
   * The human-readable label of an interrupt answer (mirrors the widget's
   * formatting): confirmation booleans use the schema's button labels, enum
   * values map through enumLabels, arrays join with commas.
   */
  private function answerLabel(array $schema, mixed $answer): string {
    if (self::isConfirmation($schema)) {
      return (string) (self::isAffirmative($answer)
        ? ($schema['confirmLabel'] ?? 'Yes')
        : ($schema['declineLabel'] ?? 'No'));
    }
    $multiple = ($schema['type'] ?? NULL) === 'array' || !empty($schema['multiple']);
    $src = $multiple ? ($schema['items'] ?? []) : $schema;
    $labels = [];
    foreach (array_values($src['enum'] ?? []) as $i => $value) {
      $labels[(string) $value] = (string) (($src['enumLabels'][$i] ?? NULL) ?: $value);
    }
    $values = is_array($answer) ? $answer : [$answer];
    $readable = array_map(
      static fn(mixed $v): string => $labels[(string) (is_scalar($v) ? $v : json_encode($v))] ?? (string) (is_scalar($v) ? $v : json_encode($v)),
      $values,
    );
    return implode(', ', $readable);
  }

  /**
   * Labels for a set of workflow ids (missing/deleted workflows are skipped).
   *
   * @param array<int|string, string> $workflowIds
   *   Workflow ids, possibly with duplicates/empties.
   *
   * @return array<string, string>
   *   Workflow id => label.
   */
  private function workflowLabels(array $workflowIds): array {
    if (!$this->entityTypeManager->hasDefinition('flowdrop_workflow')) {
      return [];
    }
    $workflows = $this->entityTypeManager->getStorage('flowdrop_workflow')
      ->loadMultiple(array_unique(array_filter($workflowIds)));
    $labels = [];
    foreach ($workflows as $workflow) {
      $labels[(string) $workflow->id()] = (string) $workflow->label();
    }
    return $labels;
  }

  /**
   * Whether the FlowDrop session entity type is installed.
   */
  private function sessionsAvailable(): bool {
    return $this->entityTypeManager->hasDefinition('flowdrop_session');
  }

  /**
   * The FlowDrop session service, or NULL when FlowDrop isn't enabled.
   */
  private function sessionService(): ?object {
    return \Drupal::hasService('flowdrop_session.service')
      ? \Drupal::service('flowdrop_session.service')
      : NULL;
  }

}
