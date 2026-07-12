<?php

declare(strict_types=1);

namespace Drupal\aincient_pages;

use Drupal\Component\Utility\Crypt;
use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Component\Datetime\TimeInterface;

/**
 * The single-writer editor lock: one live editor per (node, langcode).
 *
 * content_moderation gates state *transitions* by permission but gives ZERO
 * concurrency control — two studios, two tabs, or two users can each stage a
 * forward draft over one node and silently clobber one another. This service is
 * that missing control: a DB-backed lock (the single crash-resistant source of
 * truth) keyed on `(nid, langcode)`, the resource being guarded.
 *
 * The holder is identified by `uid` + `studio`, but the authority is an opaque,
 * server-minted `lock_token` — a FENCING token, not just a tiebreaker. Every
 * DB write ({@see PageController} save/publish/transition) presents its token
 * and {@see verify} rejects any whose token ≠ the current row's, so a stale tab
 * *cannot* write (strictly stronger than the `base_vid` optimistic check, which
 * stays as a secondary guard against legitimate-holder races). The token — not
 * `uid`, not the thread id — is what disambiguates the same user's two tabs (the
 * multi-draft UX is browser tabs): a second tab sees the lock is held (by them)
 * and must explicitly take over.
 *
 * Takeover is universal but ALWAYS explicit (never silent): anyone may
 * {@see acquire} with `force`, which mints a NEW token, so the previous holder's
 * next write fails the fence and the takeover surfaces. No heartbeat / TTL — a
 * stale lock is cleared by a human force-release (auto-release is deferred; see
 * DECISIONS 0099 / plans/editor-lock-and-provenance.md).
 */
final class EditLock {

  public const TABLE = 'aincient_edit_lock';

  public function __construct(
    private readonly Connection $database,
    private readonly AccountProxyInterface $currentUser,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Acquire (or report) the lock for a node+language.
   *
   * Resolution, in order:
   *  - No row → mint a token and take the lock ('acquired').
   *  - Row token === $currentToken → this exact session already holds it; refresh
   *    the studio + timestamp, keep the token ('acquired'). This is the same-tab
   *    studio handover (Content → Checks) — the caller carries its token across
   *    the switch, so no takeover dialog is needed.
   *  - $force → mint a NEW token and overwrite the holder ('acquired'). This is a
   *    deliberate takeover (self, from another tab; or a different user).
   *  - Otherwise → the lock is held by someone else's session; return the holder
   *    with NO token ('held_self' if the same user from another tab, else
   *    'held_other'). The caller offers an explicit "take over" that re-calls
   *    with $force.
   *
   * @return array{status: string, token: ?string, holder: ?array}
   *   status ∈ {acquired, held_self, held_other}; token is non-null only on
   *   'acquired'; holder is the current row's holder envelope (never null once a
   *   row exists — on a fresh 'acquired' it is the caller).
   */
  public function acquire(int $nid, string $langcode, string $studio, ?string $currentToken = NULL, bool $force = FALSE): array {
    $row = $this->row($nid, $langcode);
    $uid = (int) $this->currentUser->id();

    // Free, this-session, or a forced takeover all resolve to "we hold it now".
    if ($row === NULL || ($currentToken !== NULL && hash_equals($row->lock_token, $currentToken)) || $force) {
      // Keep the token when it's the same session refreshing; mint one otherwise.
      $keep = $row !== NULL && $currentToken !== NULL && hash_equals($row->lock_token, $currentToken);
      $token = $keep ? $row->lock_token : $this->mintToken();
      $this->write($nid, $langcode, $uid, $studio, $token);
      return ['status' => 'acquired', 'token' => $token, 'holder' => $this->holder($nid, $langcode, $uid, $studio)];
    }

    // Held by another session — report the holder, withhold the token.
    return [
      'status' => (int) $row->uid === $uid ? 'held_self' : 'held_other',
      'token' => NULL,
      'holder' => $this->holder($nid, $langcode, (int) $row->uid, $row->studio, (int) $row->acquired_at),
    ];
  }

  /**
   * The current lock holder for a node+language, or NULL if unlocked.
   *
   * @return array{uid: int, name: string, studio: string, acquired_at: int, mine: bool}|null
   */
  public function status(int $nid, string $langcode): ?array {
    $row = $this->row($nid, $langcode);
    if ($row === NULL) {
      return NULL;
    }
    return $this->holder($nid, $langcode, (int) $row->uid, $row->studio, (int) $row->acquired_at);
  }

  /**
   * Fence check: does this token still hold the lock for the node+language?
   *
   * The gate every DB write passes through. Constant-time compare; a missing
   * row, a stale token, or a taken-over lock all fail. A NULL/'' token never
   * passes (a caller with no lock cannot write).
   */
  public function verify(int $nid, string $langcode, ?string $token): bool {
    if ($token === NULL || $token === '') {
      return FALSE;
    }
    $row = $this->row($nid, $langcode);
    return $row !== NULL && hash_equals($row->lock_token, $token);
  }

  /**
   * Release the lock — only if the presented token matches (or $force).
   *
   * The clean-exit path (studio closes / handover complete). A non-matching
   * token no-ops without $force, so a stale tab can't release a lock that was
   * taken over from it. Force-release is the explicit "take over" escape hatch.
   *
   * @return bool
   *   TRUE if a row was removed.
   */
  public function release(int $nid, string $langcode, ?string $token, bool $force = FALSE): bool {
    if (!$force && !$this->verify($nid, $langcode, $token)) {
      return FALSE;
    }
    $deleted = $this->database->delete(self::TABLE)
      ->condition('nid', $nid)
      ->condition('langcode', $langcode)
      ->execute();
    return (bool) $deleted;
  }

  /**
   * Upsert the lock row (merge on the composite key).
   */
  private function write(int $nid, string $langcode, int $uid, string $studio, string $token): void {
    $this->database->merge(self::TABLE)
      ->keys(['nid' => $nid, 'langcode' => $langcode])
      ->fields([
        'uid' => $uid,
        'studio' => $studio,
        'lock_token' => $token,
        'acquired_at' => $this->time->getRequestTime(),
      ])
      ->execute();
  }

  /**
   * Load the raw lock row for a node+language, or NULL.
   */
  private function row(int $nid, string $langcode): ?object {
    $row = $this->database->select(self::TABLE, 'l')
      ->fields('l')
      ->condition('nid', $nid)
      ->condition('langcode', $langcode)
      ->execute()
      ->fetchObject();
    return $row ?: NULL;
  }

  /**
   * Build the holder envelope the console renders ("Locked by {name}…").
   */
  private function holder(int $nid, string $langcode, int $uid, string $studio, ?int $acquiredAt = NULL): array {
    return [
      'uid' => $uid,
      'name' => $this->userName($uid),
      'studio' => $studio,
      'acquired_at' => $acquiredAt ?? $this->time->getRequestTime(),
      'mine' => $uid === (int) $this->currentUser->id(),
    ];
  }

  /**
   * A display name for a uid (falls back to "User N" if it can't be loaded).
   */
  private function userName(int $uid): string {
    $user = $this->entityTypeManager->getStorage('user')->load($uid);
    return $user ? (string) $user->getDisplayName() : 'User ' . $uid;
  }

  /**
   * A fresh opaque lock token (URL-safe, unguessable — the fence's authority).
   */
  private function mintToken(): string {
    return Crypt::randomBytesBase64(24);
  }

}
