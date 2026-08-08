<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Context;

use Drupal\aincient_core\Entity\ContextLedgerEntry;
use Drupal\aincient_core\File\ContextDerivative;
use Drupal\aincient_core\File\DocumentUploadValidator;
use Drupal\aincient_core\File\ImageUploadValidator;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The write + lookup surface of the private, forensic context store.
 *
 * An operator attaches an image to a chat turn to give the agent context. This
 * is NOT media-library content: it is a private scratchpad that is never
 * publicly reachable, kept as a permanent ledger row plus ONE permanent
 * normalized derivative. The original bytes are not stored by default — they
 * carry the polyglot/EXIF risk the derivative was made to shed (retention
 * tier 3 is off; DECISIONS 0347/0350/0359).
 *
 * Policy lives in class constants, deliberately NOT in config: this repo has
 * been burned by config:import destroying runtime keys (0335/0336), and the
 * store's safety must not depend on a config value a sync could drop.
 */
final class ContextStore {

  /**
   * Extensions the store accepts (a Drupal file_extensions setting string).
   */
  public const ALLOWED_EXTENSIONS = 'png gif jpg jpeg webp avif';

  /**
   * The upper bound on an uploaded file's size.
   */
  public const MAX_FILESIZE = '20 MB';

  /**
   * Text-document extensions the store accepts (Tier A, DECISIONS 0350).
   */
  public const ALLOWED_DOC_EXTENSIONS = 'md txt';

  /**
   * The upper bound on an uploaded document's size.
   *
   * Tier A is all-in-context text, so the cap is small on purpose — a design
   * token file is kilobytes, not megabytes.
   */
  public const MAX_DOC_FILESIZE = '1 MB';

  /**
   * The derivative's long-edge ceiling in pixels.
   *
   * The vision ceiling, and also the transcript thumbnail size. ONE derivative,
   * no image styles.
   */
  public const MAX_LONG_EDGE = 1092;

  /**
   * The WebP quality the derivative is encoded at.
   */
  public const WEBP_QUALITY = 80;

  /**
   * Whether to keep the original bytes (retention tier 3). Off by default.
   */
  public const KEEP_ORIGINAL = FALSE;

  /**
   * The private directory the store's files live under.
   */
  public const DIRECTORY = 'private://atelier-context';

  /**
   * A generous total-size cap; over it the store warns, but never prunes.
   */
  public const TOTAL_SIZE_WARN_BYTES = 2 * 1024 * 1024 * 1024;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FileSystemInterface $fileSystem,
    private readonly ImageUploadValidator $validator,
    private readonly DocumentUploadValidator $documentValidator,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Ingests an uploaded image into the context store.
   *
   * @param \Symfony\Component\HttpFoundation\File\UploadedFile $upload
   *   The uploaded image.
   * @param string $threadId
   *   The FlowDrop console thread id (thr_…).
   * @param int|null $turn
   *   The user message sequence_number, or null when unknown at write time.
   * @param int $uid
   *   The actor who attached it.
   *
   * @return \Drupal\aincient_core\Entity\ContextLedgerEntry
   *   The persisted ledger entry.
   *
   * @throws \RuntimeException
   *   On validation or processing failure (the validator's exceptions
   *   propagate unchanged).
   */
  public function storeImage(UploadedFile $upload, string $threadId, ?int $turn, int $uid): ContextLedgerEntry {
    // 1. The original bytes and their ingest identity.
    $path = $upload->getRealPath();
    if ($path === FALSE) {
      throw new \RuntimeException('The context upload could not be read.');
    }
    $original = file_get_contents($path);
    if ($original === FALSE) {
      throw new \RuntimeException('The context upload could not be read.');
    }
    $sha256 = hash('sha256', $original);
    $size = strlen($original);
    $declaredMime = $upload->getClientMimeType();
    $filename = $upload->getClientOriginalName();

    // 2. Sniff + pixel-bomb guard + re-encode (EXIF/polyglots stripped). Let
    // the validator's exceptions propagate — the caller handles them.
    $valid = $this->validator->validate($upload, self::ALLOWED_EXTENSIONS, self::MAX_FILESIZE);

    // 3. ONE derivative from the already-safe bytes: downscale to the long-edge
    // ceiling and re-encode to WebP.
    $derivative = ContextDerivative::webp($valid->bytes, self::MAX_LONG_EDGE, self::WEBP_QUALITY);
    $derivativeBytes = $derivative['bytes'];

    // 4. Content-address the derivative by its own hash (identical bytes
    // dedupe). Never the client filename in the URI — that itself discloses.
    $derivativeSha = hash('sha256', $derivativeBytes);
    $this->prepareDirectory();
    $derivativeUri = $this->fileSystem->saveData(
      $derivativeBytes,
      self::DIRECTORY . '/' . $derivativeSha . '.webp',
      FileExists::Replace,
    );
    $derivativeFile = $this->createPermanentFile($derivativeUri, 'image/webp');

    // 5. The original is kept only when retention tier 3 is on.
    $originalFile = NULL;
    if (self::KEEP_ORIGINAL) {
      $originalUri = $this->fileSystem->saveData(
        $original,
        self::DIRECTORY . '/' . $sha256 . '.orig',
        FileExists::Replace,
      );
      $originalFile = $this->createPermanentFile($originalUri, $valid->mimeType);
    }

    // 6. The ledger row — every field set.
    $storage = $this->entityTypeManager->getStorage('aincient_context_entry');
    /** @var \Drupal\aincient_core\Entity\ContextLedgerEntry $entry */
    $entry = $storage->create([
      'uid' => $uid,
      'thread_id' => $threadId,
      'turn' => $turn,
      'kind' => 'image',
      'sha256' => $sha256,
      'derivative_sha256' => $derivativeSha,
      'size' => $size,
      'declared_mime' => $declaredMime,
      'sniffed_mime' => $valid->mimeType,
      'filename' => $filename,
      'width' => $derivative['width'],
      'height' => $derivative['height'],
      'file' => $derivativeFile->id(),
      'original_file' => $originalFile?->id(),
    ]);
    $entry->save();

    // Write-time size backstop: warn if the store grows large, never prune.
    $this->warnIfOversized();

    return $entry;
  }

  /**
   * Ingests an uploaded text document into the context store.
   *
   * The Tier A document analogue of {@see self::storeImage()}: the normalized
   * UTF-8 text (never the raw upload) is stored as ONE permanent `.txt`
   * derivative, and the SAME text is written to the ledger's `description` so a
   * later turn injects it directly with no vision call. `kind` is 'document';
   * `width`/`height` stay null.
   *
   * @param \Symfony\Component\HttpFoundation\File\UploadedFile $upload
   *   The uploaded document.
   * @param string $threadId
   *   The FlowDrop console thread id (thr_…).
   * @param int|null $turn
   *   The user message sequence_number, or null when unknown at write time.
   * @param int $uid
   *   The actor who attached it.
   *
   * @return \Drupal\aincient_core\Entity\ContextLedgerEntry
   *   The persisted ledger entry.
   *
   * @throws \RuntimeException
   *   On validation or processing failure (the validator's exceptions
   *   propagate unchanged).
   */
  public function storeDocument(UploadedFile $upload, string $threadId, ?int $turn, int $uid): ContextLedgerEntry {
    // 1. The original bytes and their ingest identity.
    $path = $upload->getRealPath();
    if ($path === FALSE) {
      throw new \RuntimeException('The context upload could not be read.');
    }
    $original = file_get_contents($path);
    if ($original === FALSE) {
      throw new \RuntimeException('The context upload could not be read.');
    }
    $sha256 = hash('sha256', $original);
    $size = strlen($original);
    $declaredMime = $upload->getClientMimeType();
    $filename = $upload->getClientOriginalName();

    // 2. Floor: size, extension, text-content sniff, UTF-8. Returns the
    // normalized text (BOM stripped, newlines → \n). Exceptions propagate — the
    // caller renders a clean rejection.
    $valid = $this->documentValidator->validate($upload, self::ALLOWED_DOC_EXTENSIONS, self::MAX_DOC_FILESIZE);

    // 3. ONE derivative: the normalized UTF-8 text, content-addressed by its own
    // hash so identical files dedupe. Never the client filename in the URI.
    $derivativeBytes = $valid->text;
    $derivativeSha = hash('sha256', $derivativeBytes);
    $this->prepareDirectory();
    $derivativeUri = $this->fileSystem->saveData(
      $derivativeBytes,
      self::DIRECTORY . '/' . $derivativeSha . '.txt',
      FileExists::Replace,
    );
    $derivativeFile = $this->createPermanentFile($derivativeUri, $valid->mimeType);

    // 4. The original is kept only when retention tier 3 is on.
    $originalFile = NULL;
    if (self::KEEP_ORIGINAL) {
      $originalUri = $this->fileSystem->saveData(
        $original,
        self::DIRECTORY . '/' . $sha256 . '.orig',
        FileExists::Replace,
      );
      $originalFile = $this->createPermanentFile($originalUri, $declaredMime);
    }

    // 5. The ledger row. `description` holds the extracted text so the turn
    // preparer injects it without a vision call; `kind` marks it a document.
    $storage = $this->entityTypeManager->getStorage('aincient_context_entry');
    /** @var \Drupal\aincient_core\Entity\ContextLedgerEntry $entry */
    $entry = $storage->create([
      'uid' => $uid,
      'thread_id' => $threadId,
      'turn' => $turn,
      'kind' => 'document',
      'sha256' => $sha256,
      'derivative_sha256' => $derivativeSha,
      'size' => $size,
      'declared_mime' => $declaredMime,
      'sniffed_mime' => $valid->sniffedMime,
      'filename' => $filename,
      'description' => $valid->text,
      'file' => $derivativeFile->id(),
      'original_file' => $originalFile?->id(),
    ]);
    $entry->save();

    $this->warnIfOversized();

    return $entry;
  }

  /**
   * Resolves the ledger entry that owns a stored context uri, if any.
   *
   * @param string $uri
   *   A private://atelier-context/... uri.
   *
   * @return \Drupal\aincient_core\Entity\ContextLedgerEntry|null
   *   The entry referencing that file via `file` OR `original_file`, or null.
   */
  public function entryForUri(string $uri): ?ContextLedgerEntry {
    $fileStorage = $this->entityTypeManager->getStorage('file');
    $fids = $fileStorage->getQuery()
      ->condition('uri', $uri)
      ->accessCheck(FALSE)
      ->range(0, 1)
      ->execute();
    if (!$fids) {
      return NULL;
    }
    $fid = (int) reset($fids);

    // The file may be referenced as either the derivative or (if retained) the
    // original — match on either.
    $entryStorage = $this->entityTypeManager->getStorage('aincient_context_entry');
    $query = $entryStorage->getQuery()->accessCheck(FALSE);
    $orGroup = $query->orConditionGroup()
      ->condition('file', $fid)
      ->condition('original_file', $fid);
    $ids = $query->condition($orGroup)->range(0, 1)->execute();
    if (!$ids) {
      return NULL;
    }
    /** @var \Drupal\aincient_core\Entity\ContextLedgerEntry|null $entry */
    $entry = $entryStorage->load((int) reset($ids));
    return $entry;
  }

  /**
   * Ensures the store's private directory exists and is writable.
   */
  private function prepareDirectory(): void {
    $dir = self::DIRECTORY;
    $this->fileSystem->prepareDirectory(
      $dir,
      FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS,
    );
  }

  /**
   * Creates and saves a permanent (status 1) File entity for a stored uri.
   */
  private function createPermanentFile(string $uri, string $filemime): FileInterface {
    /** @var \Drupal\file\FileInterface $file */
    $file = $this->entityTypeManager->getStorage('file')->create([
      'uri' => $uri,
      'filemime' => $filemime,
      'status' => 1,
    ]);
    $file->save();
    return $file;
  }

  /**
   * Best-effort size backstop: logs a warning if the store is large.
   *
   * Never deletes anything.
   */
  private function warnIfOversized(): void {
    $dir = $this->fileSystem->realpath(self::DIRECTORY);
    if ($dir === FALSE || !is_dir($dir)) {
      return;
    }
    $total = 0;
    foreach (glob($dir . '/*') ?: [] as $file) {
      if (is_file($file)) {
        $total += (int) filesize($file);
      }
    }
    if ($total > self::TOTAL_SIZE_WARN_BYTES) {
      $this->logger->warning('The private context store is @bytes bytes, over the @cap-byte warn cap. Nothing was pruned.', [
        '@bytes' => $total,
        '@cap' => self::TOTAL_SIZE_WARN_BYTES,
      ]);
    }
  }

}
