<?php

declare(strict_types=1);

namespace Drupal\aincient_core\File;

/**
 * The outcome of a passed image-upload validation.
 *
 * The normalized, re-encoded bytes plus the facts a caller needs to land them.
 */
final class ValidatedImage {

  public function __construct(
    public readonly string $bytes,
    public readonly string $extension,
    public readonly string $mimeType,
    public readonly int $width,
    public readonly int $height,
  ) {}

}
