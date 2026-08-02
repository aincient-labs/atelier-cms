<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Inference;

/**
 * An image handed to, or returned by, a model — in OUR vocabulary.
 *
 * Deliberately not `drupal/ai`'s `ImageFile` and not `symfony/ai`'s content
 * classes. It exists so a caller that wants alt text for a picture never names a
 * vendor type, which is the whole point of {@see AiGateway}: when the backend
 * changes, this class is what does not.
 *
 * Plain bytes rather than a Drupal File entity, because the callers already have
 * the binary in hand (a just-uploaded file, a generated image) and a File entity
 * would drag entity storage into a value object.
 */
final class ImageRef {

  /**
   * @param string $binary
   *   The raw image bytes.
   * @param string $mimeType
   *   The MIME type; defaults to PNG when a caller genuinely cannot tell.
   * @param string $filename
   *   A filename for providers that want one in the payload.
   */
  public function __construct(
    public readonly string $binary,
    public readonly string $mimeType = 'image/png',
    public readonly string $filename = 'image.png',
  ) {}

  /**
   * Builds a reference, filling blank MIME/filename with usable defaults.
   *
   * The call sites all guard these individually today
   * (`$mime !== '' ? $mime : 'image/png'`); centralising it means one place gets
   * it right instead of four.
   */
  public static function create(string $binary, string $mimeType = '', string $filename = ''): self {
    return new self(
      $binary,
      $mimeType !== '' ? $mimeType : 'image/png',
      $filename !== '' ? $filename : 'image.png',
    );
  }

  /**
   * Whether there are actually any bytes here.
   */
  public function isEmpty(): bool {
    return $this->binary === '';
  }

}
