<?php

declare(strict_types=1);

namespace Drupal\aincient_export;

/**
 * Options for one export run.
 */
final class ExportOptions {

  /**
   * @param string $outDir
   *   Absolute path of the static output directory.
   * @param string $baseUrl
   *   Scheme + host the site is rendered against (absolute URLs in the
   *   rendered HTML with this prefix are treated as local).
   * @param string|null $zipPath
   *   Absolute path for the export zip, or NULL to skip packaging.
   * @param bool $includeConfig
   *   Add config/sync to the zip.
   * @param bool $includeUsers
   *   Add users.json (no password hashes) to the zip.
   * @param bool $runLinkCheck
   *   Run the post-export link check.
   * @param string[] $checkIgnore
   *   fnmatch() patterns of referenced paths the link check may ignore.
   */
  public function __construct(
    public readonly string $outDir,
    public readonly string $baseUrl,
    public readonly ?string $zipPath = NULL,
    public readonly bool $includeConfig = FALSE,
    public readonly bool $includeUsers = FALSE,
    public readonly bool $runLinkCheck = TRUE,
    public readonly array $checkIgnore = [],
  ) {}

}
