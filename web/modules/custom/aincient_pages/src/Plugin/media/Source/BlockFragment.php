<?php

declare(strict_types=1);

namespace Drupal\aincient_pages\Plugin\media\Source;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\media\Attribute\MediaSource;
use Drupal\media\MediaInterface;
use Drupal\media\MediaSourceBase;

/**
 * Media source for a GLOBAL BLOCK — a saved page-schema fragment.
 *
 * The load-bearing observation behind block-as-media (DECISIONS 0137): a Media
 * entity is not "just a file". Core's oEmbed/remote-video sources already prove a
 * media item can be a reusable thing whose rendered output is arbitrary HTML
 * behind a formatter. A global block is exactly that shape — authored *structured
 * data* (a page-schema fragment) rather than an uploaded file — so it becomes a
 * Media bundle referenced by the universal `media:<id>` token, and the Library UI
 * (media_library) is its front door for free.
 *
 * Unlike the file/image sources, a block's "source" is authored structure, not a
 * derived asset: the source field ({@see \Drupal\aincient_pages\PageStore} writes
 * `field_page_content` as the translatable content overlay, `field_page_structure`
 * as the shared layout). This plugin therefore derives almost no metadata — the
 * name comes from the fragment's title, and the thumbnail is a static placeholder
 * icon (a rendered preview is a later refinement). It is deliberately thin: the
 * fragment's meaning lives in the page-schema codec, not in source metadata.
 *
 * @see \Drupal\aincient_pages\BlockStore
 * @see \Drupal\aincient_pages\EntityEmbedResolver
 */
#[MediaSource(
  id: "aincient_block_fragment",
  label: new TranslatableMarkup("Global block"),
  description: new TranslatableMarkup("A reusable page-schema fragment (CTA, banner, footer note) authored in the page studio and placed on many pages."),
  allowed_field_types: ["string_long"],
  default_thumbnail_filename: "generic.png",
)]
final class BlockFragment extends MediaSourceBase {

  /**
   * {@inheritdoc}
   */
  public function getMetadataAttributes() {
    // The block's title, lifted from the content overlay, is the only derived
    // attribute — everything else about a block lives in the page-schema codec,
    // not in source metadata.
    return [
      'title' => $this->t('Block title'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getMetadata(MediaInterface $media, $attribute_name) {
    switch ($attribute_name) {
      case 'title':
      case 'default_name':
        $title = $this->fragmentTitle($media);
        return $title !== '' ? $title : parent::getMetadata($media, $attribute_name);

      default:
        // thumbnail_uri falls through to the base, which returns the static
        // placeholder named by default_thumbnail_filename.
        return parent::getMetadata($media, $attribute_name);
    }
  }

  /**
   * The title carried in the block's content overlay, or '' if none.
   *
   * The source field ({@see PageStore::writeSchema}) stores the content layer as
   * a JSON object whose `title` is the block's human label. Decode defensively —
   * an unsaved or malformed fragment simply yields no name.
   */
  private function fragmentTitle(MediaInterface $media): string {
    $field = $this->configuration['source_field'] ?? '';
    if ($field === '' || !$media->hasField($field) || $media->get($field)->isEmpty()) {
      return '';
    }
    $raw = (string) $media->get($field)->value;
    $decoded = json_decode($raw, TRUE);
    $title = is_array($decoded) ? ($decoded['title'] ?? '') : '';
    return is_string($title) ? trim($title) : '';
  }

}
