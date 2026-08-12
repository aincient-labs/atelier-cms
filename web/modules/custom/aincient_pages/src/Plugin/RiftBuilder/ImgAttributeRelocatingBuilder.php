<?php

namespace Drupal\aincient_pages\Plugin\RiftBuilder;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\media\MediaInterface;
use Drupal\rift\Attribute\RiftBuilder;
use Drupal\rift\DTO\PictureConfig;
use Drupal\rift\Html\ElementBase;
use Drupal\rift\Html\PictureElement;
use Drupal\rift\Plugin\RiftBuilder\DefaultBuilder;
use Drupal\rift\RiftMediaSourceInterface;
use Drupal\rift\RiftSourceInterface;

/**
 * The default builder + img-scoped attribute relocation (the LCP seam).
 *
 * Rift places a view mode's custom `attributes` on the <picture> element, but
 * `loading` / `fetchpriority` / `decoding` are only read by browsers on the
 * <img> itself — on <picture> they are inert. This builder is the default in
 * every way except that it moves those img-scoped attributes onto the inner
 * <img>, so the page renderer's LCP hints (`fetchpriority="high"` on the
 * page's first image-bearing section, `loading="lazy"` below the fold — see
 * PageSpikeController::$lcpImagePending) reach the element the browser reads.
 *
 * Delete when rift does this upstream (the relocation then becomes a no-op:
 * the picture bag simply never carries these attributes).
 */
#[RiftBuilder(
  id: 'aincient_img_attributes',
  label: new TranslatableMarkup('AIncient img-attribute builder'),
  description: new TranslatableMarkup('Default picture builder, with img-scoped attributes (loading/fetchpriority/decoding) relocated from <picture> to the inner <img>.'),
  priority: 10
)]
class ImgAttributeRelocatingBuilder extends DefaultBuilder {

  /**
   * Attributes browsers only honour on <img>, never on <picture>.
   */
  private const IMG_SCOPED = ['loading', 'fetchpriority', 'decoding'];

  /**
   * {@inheritdoc}
   */
  protected function generatePictureElement(PictureConfig $pictureConfig, RiftMediaSourceInterface $riftMediaSource, RiftSourceInterface $riftSource, ?MediaInterface $media = NULL): ElementBase {
    $picture = parent::generatePictureElement($pictureConfig, $riftMediaSource, $riftSource, $media);
    $img = $picture instanceof PictureElement ? $picture->getImg() : NULL;
    if ($img === NULL) {
      return $picture;
    }
    $pictureAttribute = $picture->getAttribute();
    foreach (self::IMG_SCOPED as $name) {
      if ($pictureAttribute->offsetExists($name)) {
        $img->getAttribute()->setAttribute($name, (string) $pictureAttribute->offsetGet($name));
        $pictureAttribute->removeAttribute($name);
      }
    }
    return $picture;
  }

}
