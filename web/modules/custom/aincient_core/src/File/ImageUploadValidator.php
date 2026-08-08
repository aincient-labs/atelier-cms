<?php

declare(strict_types=1);

namespace Drupal\aincient_core\File;

use Drupal\Component\Utility\Bytes;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The single image-upload gate every console upload path shares.
 *
 * This exists because the media-library path and the avatar path had each
 * written their own upload validation, and they DRIFTED: the pixel-bomb
 * guard existed on one and not the other. A second gate written from
 * scratch would drift again the same way, so every upload path in the
 * console calls this one instead.
 *
 * Extension alone is not a check — a renamed file passes an extension
 * allowlist trivially. This sniffs the real image type from the file's
 * content (`getimagesize()`), and it re-encodes the pixels through GD
 * rather than storing the uploaded bytes as-is. Re-encoding strips
 * EXIF/embedded payloads and defuses polyglot files (an image with a
 * trailing PHP payload still decodes as an image, but only the decoded
 * pixels come back out).
 *
 * Accepted consequence: GD re-encoding FLATTENS animated GIF/WebP to a
 * single frame. This is deliberate — the security posture (re-encode,
 * don't trust bytes) is worth more than animation support, and it is the
 * same trade for every caller rather than a per-path decision.
 *
 * Supported formats: JPEG, PNG, GIF, WebP, AVIF. AVIF support is contingent
 * on the server's GD build having the codec compiled in; where it's
 * missing, AVIF uploads are rejected with a clean error instead of fatal.
 */
final class ImageUploadValidator {

  /**
   * Pixel ceiling (~40 megapixels), checked before any GD decode.
   *
   * A decompression bomb is never allocated: getimagesize() reports the
   * dimensions from the header, so an oversized image is rejected before
   * imagecreatefrom*() would try to hold it in memory.
   */
  private const MAX_PIXELS = 40_000_000;

  /**
   * Validates an uploaded image and returns its normalized, re-encoded form.
   *
   * @param \Symfony\Component\HttpFoundation\File\UploadedFile $upload
   *   The uploaded file.
   * @param string $extensionSetting
   *   A Drupal `file_extensions` setting, e.g. "png gif jpg jpeg webp".
   * @param string|null $maxFilesizeSetting
   *   A Drupal `max_filesize` setting, e.g. "8 MB", or NULL/'' for no limit.
   *
   * @return \Drupal\aincient_core\File\ValidatedImage
   *   The normalized, re-encoded image.
   *
   * @throws \RuntimeException
   *   With a user-facing message, on any validation failure.
   */
  public function validate(UploadedFile $upload, string $extensionSetting, ?string $maxFilesizeSetting): ValidatedImage {
    if (!$upload->isValid()) {
      throw new \RuntimeException('The upload did not complete.');
    }

    $maxBytes = self::maxBytes($maxFilesizeSetting);
    if ($maxBytes > 0 && $upload->getSize() > $maxBytes) {
      throw new \RuntimeException('The file is larger than the allowed maximum.');
    }

    $path = $upload->getRealPath();
    if ($path === FALSE) {
      throw new \RuntimeException('The upload could not be read.');
    }

    $info = @getimagesize($path);
    if ($info === FALSE) {
      throw new \RuntimeException('That file is not a valid image.');
    }

    $width = (int) $info[0];
    $height = (int) $info[1];
    if ($width * $height > self::MAX_PIXELS) {
      throw new \RuntimeException('The image dimensions exceed the allowed maximum.');
    }

    [$canonicalExt, $canonicalMime] = match ($info[2]) {
      \IMAGETYPE_JPEG => ['jpg', 'image/jpeg'],
      \IMAGETYPE_PNG => ['png', 'image/png'],
      \IMAGETYPE_GIF => ['gif', 'image/gif'],
      \IMAGETYPE_WEBP => ['webp', 'image/webp'],
      \IMAGETYPE_AVIF => ['avif', 'image/avif'],
      default => throw new \RuntimeException('Unsupported image type.'),
    };

    // AVIF support depends on the server's GD build; some builds report
    // IMAGETYPE_AVIF from getimagesize() but lack the codec functions, which
    // would otherwise fatal below. Fail cleanly instead.
    if ($canonicalExt === 'avif' && (!function_exists('imagecreatefromavif') || !function_exists('imageavif'))) {
      throw new \RuntimeException('AVIF images are not supported on this server.');
    }

    $allowed = self::parseExtensions($extensionSetting);
    $allowedForCanonical = $canonicalExt === 'jpg'
      ? (in_array('jpg', $allowed, TRUE) || in_array('jpeg', $allowed, TRUE))
      : in_array($canonicalExt, $allowed, TRUE);
    if (!$allowedForCanonical) {
      throw new \RuntimeException(sprintf('Unsupported file type ".%s" — allowed: %s.', $canonicalExt, implode(', ', $allowed)));
    }

    $im = match ($info[2]) {
      \IMAGETYPE_JPEG => imagecreatefromjpeg($path),
      \IMAGETYPE_PNG => imagecreatefrompng($path),
      \IMAGETYPE_GIF => imagecreatefromgif($path),
      \IMAGETYPE_WEBP => imagecreatefromwebp($path),
      \IMAGETYPE_AVIF => imagecreatefromavif($path),
    };
    if ($im === FALSE) {
      throw new \RuntimeException('The image could not be processed.');
    }

    try {
      if ($canonicalExt === 'png') {
        imagealphablending($im, FALSE);
        imagesavealpha($im, TRUE);
      }

      ob_start();
      $ok = match ($canonicalExt) {
        'jpg' => imagejpeg($im, NULL, 85),
        'png' => imagepng($im),
        'gif' => imagegif($im),
        'webp' => imagewebp($im, NULL, 82),
        'avif' => imageavif($im, NULL, 82),
      };
      $bytes = ob_get_clean();
    }
    finally {
      imagedestroy($im);
    }

    if (!$ok || empty($bytes)) {
      throw new \RuntimeException('The image could not be processed.');
    }

    return new ValidatedImage($bytes, $canonicalExt, $canonicalMime, $width, $height);
  }

  /**
   * Splits a Drupal `file_extensions` setting into a lowercased array.
   *
   * @param string $setting
   *   Whitespace-separated extensions, e.g. "png gif jpg jpeg webp".
   *
   * @return string[]
   *   The lowercased extensions.
   */
  public static function parseExtensions(string $setting): array {
    $parts = preg_split('/\s+/', trim($setting));
    if ($parts === FALSE) {
      return [];
    }
    return array_values(array_filter(array_map('mb_strtolower', $parts), static fn (string $ext): bool => $ext !== ''));
  }

  /**
   * Resolves a Drupal `max_filesize` setting to bytes.
   *
   * @param string|null $setting
   *   A setting such as "8 MB", "500 KB", '' or NULL.
   *
   * @return int
   *   The maximum in bytes, or 0 when unset/blank.
   */
  public static function maxBytes(?string $setting): int {
    if ($setting === NULL || trim($setting) === '') {
      return 0;
    }
    return (int) Bytes::toNumber($setting);
  }

}
