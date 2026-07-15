<?php

declare(strict_types=1);

namespace Drupal\aincient_export;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Image\ImageFactory;
use Drupal\image\ImageStyleInterface;

/**
 * Pre-generates every image-style derivative for every managed image.
 *
 * Crawl-based exporters only generate the derivatives their crawled HTML
 * happens to reference; warming the full matrix up-front is deterministic and
 * lets the exporter treat derivatives as plain files on disk.
 */
final class DerivativeWarmer {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ImageFactory $imageFactory,
  ) {}

  /**
   * Warms all derivatives; returns the number newly created.
   */
  public function warmAll(): int {
    /** @var \Drupal\image\ImageStyleInterface[] $styles */
    $styles = $this->entityTypeManager->getStorage('image_style')->loadMultiple();
    $file_storage = $this->entityTypeManager->getStorage('file');
    $fids = $file_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 1)
      ->condition('filemime', 'image/', 'STARTS_WITH')
      ->execute();

    $created = 0;
    foreach ($file_storage->loadMultiple($fids) as $file) {
      $uri = $file->getFileUri();
      if (!str_starts_with($uri, 'public://')) {
        continue;
      }
      // A source the toolkit can't load would make every style log an error
      // (and some toolkit failures throw); probe the load once per file
      // instead. isValid() alone is not enough — it only parses the header,
      // which can succeed on files whose pixel data GD then fails to load.
      if (!$this->sourceLoads($uri)) {
        continue;
      }
      foreach ($styles as $style) {
        if ($this->warmOne($style, $uri)) {
          $created++;
        }
      }
    }
    return $created;
  }

  private function sourceLoads(string $uri): bool {
    $image = $this->imageFactory->get($uri);
    if (!$image->isValid()) {
      return FALSE;
    }
    $toolkit = $image->getToolkit();
    if (method_exists($toolkit, 'getImage')) {
      try {
        return $toolkit->getImage() !== NULL;
      }
      catch (\Throwable) {
        return FALSE;
      }
    }
    return TRUE;
  }

  private function warmOne(ImageStyleInterface $style, string $uri): bool {
    if (!$style->supportsUri($uri)) {
      return FALSE;
    }
    $derivative = $style->buildUri($uri);
    if (file_exists($derivative)) {
      return FALSE;
    }
    // createDerivative() logs and returns FALSE on unsupported sources
    // (e.g. SVG) — but throws a TypeError on files GD cannot load (core
    // calls setImage(false)); either way skip the file, don't abort the run.
    try {
      return $style->createDerivative($uri, $derivative);
    }
    catch (\Throwable) {
      return FALSE;
    }
  }

}
