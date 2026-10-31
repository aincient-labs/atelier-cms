<?php

declare(strict_types=1);

namespace Drupal\aincient_studio_site\Engine;

use Drupal\Core\Site\Settings;
use Psr\Log\LoggerInterface;

/**
 * The console's side of the engine's file protocol (Manager Instances M4).
 *
 * The experimental engine sidecar shares two volumes with the app container
 * (Manager `crates/aincient-engine/src/inbox.rs`, mounted by
 * `crates/aincient-core/src/engine.rs`):
 *
 * - the OUTBOX, read-only here: the engine publishes `pairings.json`, the
 *   pairings list without token hashes;
 * - the INBOX, writable here: the one file Drupal may write is
 *   `revoke.request` = `{"id":"<pairing id>"}`.
 *
 * Nothing in Drupal can pair (design E-02): no codes, no tokens, no approve.
 * The engine refuses a console revoke of the primary itself; this refuses it
 * first so the console never offers it.
 *
 * Whether the engine is enabled at all is whether the outbox mount exists: a
 * default install has neither volume and sees nothing of this.
 *
 * Paths come from settings.php (`atelier_engine_outbox`,
 * `atelier_engine_inbox`) so tests and dev sites can point them at a temp
 * dir; the defaults are the appliance's mount points.
 */
final class EnginePairings {

  public const DEFAULT_OUTBOX = '/opt/drupal/engine-outbox';
  public const DEFAULT_INBOX = '/opt/drupal/engine-inbox';
  public const PAIRINGS_FILE = 'pairings.json';
  public const REVOKE_FILE = 'revoke.request';

  /**
   * The most read from `pairings.json`. A pairing is ~200 bytes and the engine
   * keeps a handful; 64 KiB is far past any real list.
   */
  public const MAX_BYTES = 65536;

  /**
   * The most pairings shown; more is not a list the engine writes.
   */
  public const MAX_PAIRINGS = 200;

  /**
   * Exactly what the engine's `new_pairing_id()` makes: 12 lowercase
   * Crockford base32 symbols (no i, l, o, u). `is_pairing_id()` in codes.rs.
   */
  public const ID_PATTERN = '/^[0-9a-hjkmnp-tv-z]{12}$/D';

  /**
   * The engine's NAME_MAX is 64 characters; allow bytes for multibyte names.
   */
  private const NAME_MAX_BYTES = 256;

  public const STATE_ABSENT = 'absent';
  public const STATE_PENDING = 'pending';
  public const STATE_OK = 'ok';
  public const STATE_ERROR = 'error';

  public function __construct(
    private readonly LoggerInterface $logger,
  ) {}

  public function outboxDir(): string {
    return rtrim((string) Settings::get('atelier_engine_outbox', self::DEFAULT_OUTBOX), '/');
  }

  public function inboxDir(): string {
    return rtrim((string) Settings::get('atelier_engine_inbox', self::DEFAULT_INBOX), '/');
  }

  /**
   * Whether this site runs the engine: its outbox mount is a real directory.
   */
  public function enabled(): bool {
    $dir = $this->outboxDir();
    return $dir !== '' && !is_link($dir) && is_dir($dir);
  }

  public static function isPairingId(string $id): bool {
    return preg_match(self::ID_PATTERN, $id) === 1;
  }

  /**
   * The pairings list as the console shows it.
   *
   * @return array{state: string, pairings: list<array{id: string, name: string, primary: bool, created: int, last_used: int, via: string}>, error?: string}
   *   `absent`: no engine on this site. `pending`: the engine is there but has
   *   not published its list yet. `ok`: the list. `error`: the file is there
   *   but could not be read safely; `error` says why, in plain words.
   */
  public function read(): array {
    if (!$this->enabled()) {
      return ['state' => self::STATE_ABSENT, 'pairings' => []];
    }
    $path = $this->outboxDir() . '/' . self::PAIRINGS_FILE;
    clearstatcache(TRUE, $path);
    if (!file_exists($path) && !is_link($path)) {
      return ['state' => self::STATE_PENDING, 'pairings' => []];
    }
    try {
      $bytes = self::readSmallRegular($path, self::MAX_BYTES);
      return ['state' => self::STATE_OK, 'pairings' => self::parse($bytes)];
    }
    catch (\UnexpectedValueException $e) {
      return ['state' => self::STATE_ERROR, 'pairings' => [], 'error' => $e->getMessage()];
    }
  }

  /**
   * Ask the engine to revoke one pairing: write `revoke.request` atomically.
   *
   * @param string $id
   *   The pairing id; must have the engine's id shape and be a non-primary
   *   pairing in the current list.
   * @param string $actor
   *   Who asked, for the log.
   *
   * @return array{id: string, name: string}
   *   The pairing the request names.
   *
   * @throws \Drupal\aincient_studio_site\Engine\RevokeRefused
   *   With a plain-words reason and an HTTP-ish status.
   */
  public function requestRevoke(string $id, string $actor): array {
    if (!self::isPairingId($id)) {
      throw new RevokeRefused('That is not a pairing id.', 400);
    }
    $list = $this->read();
    if ($list['state'] === self::STATE_ABSENT) {
      throw new RevokeRefused('The engine is not enabled on this site.', 404);
    }
    if ($list['state'] !== self::STATE_OK) {
      throw new RevokeRefused('The pairings list can’t be read right now, so nothing was revoked.', 409);
    }
    $pairing = NULL;
    foreach ($list['pairings'] as $p) {
      if ($p['id'] === $id) {
        $pairing = $p;
        break;
      }
    }
    if ($pairing === NULL) {
      throw new RevokeRefused('No Manager with that id is paired with this site.', 404);
    }
    if ($pairing['primary']) {
      throw new RevokeRefused('The primary Manager can’t be revoked from the console. Revoke it from the Manager app, or over SSH.', 403);
    }

    $dir = $this->inboxDir();
    if ($dir === '' || is_link($dir) || !is_dir($dir) || !is_writable($dir)) {
      throw new RevokeRefused('The engine’s inbox is not writable from this site, so nothing was revoked.', 500);
    }
    $target = $dir . '/' . self::REVOKE_FILE;
    clearstatcache(TRUE, $target);
    if (file_exists($target) || is_link($target)) {
      throw new RevokeRefused('Another revoke is still waiting for the engine. Try again in a few seconds.', 409);
    }

    $json = json_encode(['id' => $id], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $tmp = $dir . '/.revoke.' . bin2hex(random_bytes(8)) . '.tmp';
    // 'x' = O_CREAT|O_EXCL: never follows or reuses something already there.
    $handle = @fopen($tmp, 'x');
    if ($handle === FALSE) {
      throw new RevokeRefused('Could not write the revoke request, so nothing was revoked.', 500);
    }
    $written = fwrite($handle, $json);
    fflush($handle);
    fclose($handle);
    // The inbox is www-data's (uid 33), 0770; the engine reads as root.
    @chmod($tmp, 0660);
    if ($written !== strlen($json) || !@rename($tmp, $target)) {
      @unlink($tmp);
      throw new RevokeRefused('Could not write the revoke request, so nothing was revoked.', 500);
    }

    $this->logger->notice('Revoke of Manager pairing %id (%name) requested from the console by %user.', [
      '%id' => $id,
      '%name' => $pairing['name'],
      '%user' => $actor,
    ]);
    return ['id' => $id, 'name' => $pairing['name']];
  }

  /**
   * Read a small regular file without following a link or blocking on a FIFO.
   *
   * PHP can't open with O_NOFOLLOW, so: lstat first (a link, FIFO, socket or
   * directory is refused before it is opened), then fstat what was actually
   * opened and require the same inode, so a swap between the two is refused.
   */
  private static function readSmallRegular(string $path, int $max): string {
    $before = @lstat($path);
    if ($before === FALSE) {
      throw new \UnexpectedValueException('The engine’s pairings list could not be read.');
    }
    if (is_link($path) || ($before['mode'] & 0170000) !== 0100000) {
      throw new \UnexpectedValueException('The engine’s pairings list is not a regular file, so it was not read.');
    }
    if ($before['size'] > $max) {
      throw new \UnexpectedValueException('The engine’s pairings list is larger than expected, so it was not read.');
    }
    $handle = @fopen($path, 'rb');
    if ($handle === FALSE) {
      throw new \UnexpectedValueException('The engine’s pairings list could not be opened.');
    }
    try {
      $after = fstat($handle);
      if ($after === FALSE || $after['ino'] !== $before['ino'] || $after['dev'] !== $before['dev'] || ($after['mode'] & 0170000) !== 0100000) {
        throw new \UnexpectedValueException('The engine’s pairings list changed while it was read; reload in a moment.');
      }
      $bytes = stream_get_contents($handle, $max + 1);
    }
    finally {
      fclose($handle);
    }
    if ($bytes === FALSE) {
      throw new \UnexpectedValueException('The engine’s pairings list could not be read.');
    }
    if (strlen($bytes) > $max) {
      throw new \UnexpectedValueException('The engine’s pairings list is larger than expected, so it was not read.');
    }
    return $bytes;
  }

  /**
   * Parse the engine's `PairingView` list with typed fields; anything off is
   * an error for the whole list (an honest error beats a partial list that
   * might hide a pairing).
   *
   * @return list<array{id: string, name: string, primary: bool, created: int, last_used: int, via: string}>
   */
  public static function parse(string $bytes): array {
    try {
      $data = json_decode($bytes, TRUE, 4, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      throw new \UnexpectedValueException('The engine’s pairings list is not valid JSON.');
    }
    if (!is_array($data) || !array_is_list($data)) {
      throw new \UnexpectedValueException('The engine’s pairings list is not a list.');
    }
    if (count($data) > self::MAX_PAIRINGS) {
      throw new \UnexpectedValueException('The engine’s pairings list is longer than expected, so it was not shown.');
    }
    $out = [];
    foreach ($data as $row) {
      if (
        !is_array($row)
        || !is_string($row['id'] ?? NULL) || !self::isPairingId($row['id'])
        || !is_string($row['name'] ?? NULL) || $row['name'] === '' || strlen($row['name']) > self::NAME_MAX_BYTES || !mb_check_encoding($row['name'], 'UTF-8')
        || !is_int($row['created'] ?? NULL) || $row['created'] < 0
        || !is_int($row['last_used'] ?? NULL) || $row['last_used'] < 0
        || !is_bool($row['primary'] ?? NULL)
        || (array_key_exists('via', $row) && !is_string($row['via']))
      ) {
        throw new \UnexpectedValueException('The engine’s pairings list has an entry this console doesn’t understand.');
      }
      $out[] = [
        'id' => $row['id'],
        'name' => $row['name'],
        'primary' => $row['primary'],
        'created' => $row['created'],
        'last_used' => $row['last_used'],
        'via' => mb_substr((string) ($row['via'] ?? ''), 0, 128),
      ];
    }
    return $out;
  }

}
