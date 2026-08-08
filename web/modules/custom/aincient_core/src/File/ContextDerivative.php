<?php

declare(strict_types=1);

namespace Drupal\aincient_core\File;

/**
 * Produces the single normalized derivative the context store keeps.
 *
 * The input is the already-safe, GD-re-encoded bytes from the
 * {@see \Drupal\aincient_core\File\ImageUploadValidator} (EXIF/polyglots
 * already stripped), so this does one job: downscale so the long edge fits
 * the vision ceiling (never upscale) and re-encode to WebP. Alpha is
 * preserved. There is exactly ONE derivative — it doubles as the transcript
 * thumbnail — so no image styles are involved.
 *
 * The GD resource is always freed in a finally, matching the idiom in
 * {@see ImageUploadValidator::validate}.
 */
final class ContextDerivative {

  /**
   * Downscale (if needed) and re-encode safe bytes to WebP.
   *
   * @param string $safeBytes
   *   Already-re-encoded, safe image bytes (a ValidatedImage's $bytes).
   * @param int $maxLongEdge
   *   The long-edge ceiling in pixels; the image is only ever downscaled.
   * @param int $quality
   *   The WebP quality (0-100).
   *
   * @return array{bytes:string, width:int, height:int}
   *   The derivative bytes and their dimensions.
   *
   * @throws \RuntimeException
   *   If GD lacks WebP support or the image cannot be processed.
   */
  public static function webp(string $safeBytes, int $maxLongEdge, int $quality): array {
    if (!function_exists('imagewebp')) {
      throw new \RuntimeException('This server\'s GD build has no WebP support; the context derivative cannot be produced.');
    }

    $src = @imagecreatefromstring($safeBytes);
    if ($src === FALSE) {
      throw new \RuntimeException('The context image could not be decoded.');
    }

    $resized = NULL;
    try {
      $srcW = imagesx($src);
      $srcH = imagesy($src);
      $longEdge = max($srcW, $srcH);

      // Only ever downscale — never upscale a small source.
      if ($longEdge > $maxLongEdge) {
        $scale = $maxLongEdge / $longEdge;
        $dstW = max(1, (int) round($srcW * $scale));
        $dstH = max(1, (int) round($srcH * $scale));
      }
      else {
        $dstW = $srcW;
        $dstH = $srcH;
      }

      $resized = imagecreatetruecolor($dstW, $dstH);
      if ($resized === FALSE) {
        throw new \RuntimeException('The context image could not be processed.');
      }

      // Preserve transparency for PNG/WebP sources.
      imagealphablending($resized, FALSE);
      imagesavealpha($resized, TRUE);

      if (!imagecopyresampled($resized, $src, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH)) {
        throw new \RuntimeException('The context image could not be resized.');
      }

      ob_start();
      $ok = imagewebp($resized, NULL, $quality);
      $bytes = ob_get_clean();

      if (!$ok || $bytes === FALSE || $bytes === '') {
        throw new \RuntimeException('The context image could not be encoded to WebP.');
      }

      return ['bytes' => $bytes, 'width' => $dstW, 'height' => $dstH];
    }
    finally {
      imagedestroy($src);
      if ($resized instanceof \GdImage) {
        imagedestroy($resized);
      }
    }
  }

}
