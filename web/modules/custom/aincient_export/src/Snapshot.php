<?php

declare(strict_types=1);

namespace Drupal\aincient_export;

/**
 * One frozen snapshot on disk, read from its marker file.
 *
 * Snapshots are plain export folders under the frozen directory; the marker
 * is the only metadata (DECISIONS 0416) — no database table.
 */
final class Snapshot {

  public function __construct(
    public readonly string $id,
    public readonly string $dir,
    public readonly ?string $label,
    public readonly ?string $frozenAt,
    public readonly ?string $frozenBy,
    public readonly ?string $atelierVersion,
    public readonly int $pages,
    public readonly int $assets,
    public readonly bool $keep,
  ) {}

  /**
   * Reads a snapshot from its folder, or NULL if it carries no marker.
   */
  public static function fromDir(string $dir): ?self {
    $marker = rtrim($dir, '/') . '/' . Exporter::MARKER;
    if (!is_file($marker)) {
      return NULL;
    }
    $data = json_decode((string) file_get_contents($marker), TRUE);
    if (!is_array($data) || ($data['generator'] ?? NULL) !== 'aincient_export') {
      return NULL;
    }
    return new self(
      id: basename($dir),
      dir: rtrim($dir, '/'),
      label: isset($data['label']) && $data['label'] !== '' ? (string) $data['label'] : NULL,
      frozenAt: isset($data['frozen_at']) ? (string) $data['frozen_at'] : NULL,
      frozenBy: isset($data['frozen_by']) ? (string) $data['frozen_by'] : NULL,
      atelierVersion: isset($data['atelier_version']) ? (string) $data['atelier_version'] : NULL,
      pages: (int) ($data['pages'] ?? 0),
      assets: (int) ($data['assets'] ?? 0),
      keep: (bool) ($data['keep'] ?? FALSE),
    );
  }

  /**
   * Array form for JSON output (drush --format=json, the console API).
   *
   * @return array<string, mixed>
   */
  public function toArray(): array {
    return [
      'id' => $this->id,
      'label' => $this->label,
      'frozen_at' => $this->frozenAt,
      'frozen_by' => $this->frozenBy,
      'atelier_version' => $this->atelierVersion,
      'pages' => $this->pages,
      'assets' => $this->assets,
      'keep' => $this->keep,
    ];
  }

}
