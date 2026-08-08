<?php

declare(strict_types=1);

namespace Drupal\aincient_core\File;

use Drupal\Component\Utility\Bytes;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The single text-document upload gate, the document analogue of the image one.
 *
 * This exists for the same reason as {@see ImageUploadValidator}: one gate every
 * document-upload path shares, so a second gate can't drift from it. It enforces
 * the CODE FLOOR for Tier A text-native documents (DECISIONS 0350) — the part
 * that must be code, not config, and cannot be disabled by a `config:import`
 * (0335/0336):
 *
 *   1. size cap;
 *   2. extension allowlist (Tier A: text-native only);
 *   3. MIME agreement — the CONTENT must actually be text: finfo must sniff a
 *      `text/*` type. This is what a renamed binary fails (a PNG renamed .md
 *      sniffs image/png), stronger than trusting the client-declared MIME, which
 *      is unreliable for .md (browsers send text/markdown, text/plain, or
 *      application/octet-stream interchangeably) and so is recorded but never
 *      gated on;
 *   4. UTF-8 validity — the decisive "is this text" check; binary content that
 *      slips a finfo text guess still fails here.
 *
 * On success it returns the NORMALIZED text (BOM stripped, CR/CRLF → LF) — the
 * store persists this, never the raw upload — plus an ADVISORY injection scan.
 * A Tier A design file's purpose is to instruct, so injection-shaped lines are
 * legitimate content; the scan is surfaced for the operator, never a rejection
 * (DECISIONS 0350: for a design.md the tool gate carries containment, and that
 * gate is deferred while the flow stays proposal-only, DECISIONS 0368).
 *
 * NO parser surface: Tier A is read as raw UTF-8 bytes. Zip/XML (Tier B) and PDF
 * (Tier C) need a parser dependency in the appliance image and are out of scope.
 */
final class DocumentUploadValidator {

  /**
   * Canonical MIME for each accepted extension.
   *
   * The stored `sniffed_mime` is what finfo actually read; this is the tidy,
   * extension-derived MIME used as the canonical type.
   */
  private const CANONICAL_MIME = [
    'md' => 'text/markdown',
    'txt' => 'text/plain',
  ];

  /**
   * Advisory prompt-injection patterns (case-insensitive), label => regex.
   *
   * Deliberately small and non-authoritative: these NEVER block (see the class
   * docblock). They exist so the admission UI can say "this file contains
   * instruction-shaped text" — honest surfacing, not a security control.
   */
  private const INJECTION_PATTERNS = [
    'ignore-previous' => '/\bignore\s+(all\s+)?(previous|prior|above)\b/i',
    'disregard' => '/\bdisregard\s+(all\s+)?(previous|prior|the\s+above|instructions)\b/i',
    'system-prompt' => '/\bsystem\s+prompt\b/i',
    'new-instructions' => '/\byou\s+are\s+now\b/i',
    'role-override' => '/\b(act|behave)\s+as\s+(if|an?|the)\b/i',
  ];

  /**
   * Validates an uploaded text document and returns its normalized form.
   *
   * @param \Symfony\Component\HttpFoundation\File\UploadedFile $upload
   *   The uploaded file.
   * @param string $extensionSetting
   *   A Drupal `file_extensions` setting, e.g. "md txt".
   * @param string|null $maxFilesizeSetting
   *   A Drupal `max_filesize` setting, e.g. "1 MB", or NULL/'' for no limit.
   *
   * @return \Drupal\aincient_core\File\ValidatedDocument
   *   The normalized, validated document.
   *
   * @throws \RuntimeException
   *   With a user-facing message, on any floor violation.
   */
  public function validate(UploadedFile $upload, string $extensionSetting, ?string $maxFilesizeSetting): ValidatedDocument {
    if (!$upload->isValid()) {
      throw new \RuntimeException('The upload did not complete.');
    }

    $maxBytes = ImageUploadValidator::maxBytes($maxFilesizeSetting);
    if ($maxBytes > 0 && $upload->getSize() > $maxBytes) {
      throw new \RuntimeException('The file is larger than the allowed maximum.');
    }

    // Extension allowlist. The canonical extension is the CLIENT extension
    // (a text file has no magic bytes to derive one from), constrained to the
    // allowlist; the content checks below are what make trusting it safe.
    $allowed = ImageUploadValidator::parseExtensions($extensionSetting);
    $ext = mb_strtolower((string) $upload->getClientOriginalExtension());
    $canonicalExt = $ext === 'markdown' ? 'md' : $ext;
    if (!in_array($canonicalExt, $allowed, TRUE) || !isset(self::CANONICAL_MIME[$canonicalExt])) {
      throw new \RuntimeException(sprintf('Unsupported file type ".%s" — allowed: %s.', $ext, implode(', ', $allowed)));
    }

    $path = $upload->getRealPath();
    if ($path === FALSE) {
      throw new \RuntimeException('The upload could not be read.');
    }
    $raw = file_get_contents($path);
    if ($raw === FALSE) {
      throw new \RuntimeException('The upload could not be read.');
    }

    // MIME agreement: the CONTENT must actually be text. A finfo sniff of a
    // non-text/* type means the bytes are not a text document whatever the name
    // says (a PNG renamed .md sniffs image/png here).
    $sniffed = (new \finfo(FILEINFO_MIME_TYPE))->buffer($raw);
    if (!is_string($sniffed) || !str_starts_with($sniffed, 'text/')) {
      throw new \RuntimeException('That file is not a text document.');
    }

    // Strip a UTF-8 BOM, then require the remainder to be valid UTF-8 — the
    // decisive "is this text" gate. Binary that slipped the finfo guess dies
    // here.
    $text = $raw;
    if (str_starts_with($text, "\xEF\xBB\xBF")) {
      $text = substr($text, 3);
    }
    if (!mb_check_encoding($text, 'UTF-8')) {
      throw new \RuntimeException('The document is not valid UTF-8 text.');
    }

    // Normalize newlines to \n (CRLF and lone CR → LF).
    $text = (string) preg_replace('/\r\n?/', "\n", $text);

    return new ValidatedDocument(
      $text,
      $canonicalExt,
      self::CANONICAL_MIME[$canonicalExt],
      $sniffed,
      self::scanInjection($text),
    );
  }

  /**
   * Advisory scan for instruction-shaped patterns. NEVER blocks.
   *
   * @param string $text
   *   The normalized document text.
   *
   * @return array<int, string>
   *   The labels of matched patterns, in declaration order; empty when none.
   */
  public static function scanInjection(string $text): array {
    $found = [];
    foreach (self::INJECTION_PATTERNS as $label => $pattern) {
      if (preg_match($pattern, $text) === 1) {
        $found[] = $label;
      }
    }
    return $found;
  }

}
