<?php

declare(strict_types=1);

namespace Drupal\aincient_core\File;

/**
 * The outcome of a passed text-document upload validation.
 *
 * The document analogue of {@see ValidatedImage}: the normalized UTF-8 text
 * (the bytes actually stored, never the raw upload) plus the facts a caller
 * needs to land it. `injectionFindings` is ADVISORY — a Tier A design file's
 * whole purpose is to instruct, so an injection-shaped line is expected content,
 * not a rejection reason (DECISIONS 0350). It is surfaced, never used to block.
 */
final class ValidatedDocument {

  /**
   * @param string $text
   *   The normalized UTF-8 text (BOM stripped, newlines normalized to \n).
   * @param string $extension
   *   The canonical, lowercased extension (e.g. "md").
   * @param string $mimeType
   *   The canonical MIME for the extension (e.g. "text/markdown").
   * @param string $sniffedMime
   *   What finfo read from the content (e.g. "text/plain").
   * @param array<int, string> $injectionFindings
   *   Advisory labels for injection-shaped patterns seen in the text. Empty
   *   when none matched. NEVER a reason to reject — see the class docblock.
   */
  public function __construct(
    public readonly string $text,
    public readonly string $extension,
    public readonly string $mimeType,
    public readonly string $sniffedMime,
    public readonly array $injectionFindings = [],
  ) {}

}
