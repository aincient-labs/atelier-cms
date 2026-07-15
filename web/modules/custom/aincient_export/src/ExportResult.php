<?php

declare(strict_types=1);

namespace Drupal\aincient_export;

/**
 * Accumulated outcome of one export run.
 */
final class ExportResult {

  /**
   * @var array<string, string>
   *   Exported page path => destination relative to the output directory.
   */
  public array $pages = [];

  /**
   * @var array<string, int>
   *   Paths skipped because they returned a non-200 status (path => status).
   */
  public array $skipped = [];

  /**
   * @var array<string, string>
   *   Paths that threw during rendering (path => error).
   */
  public array $failures = [];

  public int $assetsCopied = 0;

  public int $derivativesWarmed = 0;

  /**
   * @var array<int, array{file: string, ref: string}>
   */
  public array $brokenLinks = [];

  public ?string $zipPath = NULL;

  public int $zipFiles = 0;

  public function __construct(
    public readonly string $outDir,
  ) {}

  public function ok(): bool {
    return !$this->failures && !$this->brokenLinks;
  }

}
