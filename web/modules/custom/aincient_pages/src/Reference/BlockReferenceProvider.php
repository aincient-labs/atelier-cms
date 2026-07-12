<?php

declare(strict_types=1);

namespace Drupal\aincient_pages\Reference;

use Drupal\aincient_pages\BlockStore;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\media\MediaInterface;

/**
 * The `block:<id>` reference type — reusable global blocks.
 *
 * A global block is a `block` MEDIA entity (DECISIONS 0138); `<id>` is the media
 * id. This still surfaces blocks as their own reference TYPE (`block`) — distinct
 * from the image `media` picker — because they carry a different studio UX: a
 * block has no single thumbnail and no canonical edit URL (it is edited
 * in-studio, not at an entity form — see the React field's "Edit block" action),
 * so `thumb` and `edit_url` are NULL; the studio recognises a `block` reference
 * and offers the in-studio editor instead. (Folding blocks into a single
 * "pick from Library" picker under one media:<id> token is a later increment.)
 */
final class BlockReferenceProvider implements ReferenceProviderInterface {

  private const BUNDLE = 'block';

  public function __construct(
    private readonly BlockStore $blocks,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  public function typeKey(): string {
    return 'block';
  }

  public function search(?string $query, int $limit): array {
    $storage = $this->entityTypeManager->getStorage('media');
    $q = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('bundle', self::BUNDLE)
      ->sort('changed', 'DESC')
      ->range(0, $limit);
    if ($query !== NULL && trim($query) !== '') {
      $q->condition('name', trim($query), 'CONTAINS');
    }
    $out = [];
    foreach ($storage->loadMultiple($q->execute()) as $media) {
      if ($media instanceof MediaInterface) {
        $out[] = $this->toDescriptor($media);
      }
    }
    return $out;
  }

  public function describe(int $id): ?array {
    $media = $this->entityTypeManager->getStorage('media')->load($id);
    return $media instanceof MediaInterface && $media->bundle() === self::BUNDLE
      ? $this->toDescriptor($media)
      : NULL;
  }

  private function toDescriptor(MediaInterface $media): array {
    $count = count($this->blocks->resolveSections((string) $media->id()));
    return ReferenceDescriptor::create(
      token: 'block:' . $media->id(),
      type: 'block',
      label: (string) $media->label(),
      description: $count === 1 ? '1 section' : sprintf('%d sections', $count),
      thumb: NULL,
      published: $media->isPublished(),
      // Blocks edit in-studio, not at an entity form.
      editUrl: NULL,
      meta: ['sections' => $count, 'changed' => (int) $media->get('changed')->value],
    );
  }

}
