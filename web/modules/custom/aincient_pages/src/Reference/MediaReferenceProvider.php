<?php

declare(strict_types=1);

namespace Drupal\aincient_pages\Reference;

use Drupal\aincient_pages\ConsoleDeepLink;
use Drupal\aincient_pages\MediaRepository;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * The `media:<id>` reference type — images from the library.
 *
 * A thin adapter over {@see MediaRepository} (the existing single seam the studio
 * picker, the upload path and the agent's find tool already share), enriched with
 * the published status + edit link the unified descriptor adds.
 */
final class MediaReferenceProvider implements ReferenceProviderInterface {

  public function __construct(
    private readonly MediaRepository $media,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  public function typeKey(): string {
    return 'media';
  }

  public function search(?string $query, int $limit): array {
    return array_map([$this, 'toDescriptor'], $this->media->search($query, $limit));
  }

  public function describe(int $id): ?array {
    $row = $this->media->resolveToken('media:' . $id);
    return $row !== NULL ? $this->toDescriptor($row) : NULL;
  }

  /**
   * Map a MediaRepository row ({id,token,name,thumb,alt}) to a descriptor.
   */
  private function toDescriptor(array $row): array {
    $media = $this->entityTypeManager->getStorage('media')->load($row['id']);
    // Edit in the Media studio (/atelier/media/image/<id>), not the raw media
    // form. Only an entity a studio owns gets a deep link; anything else falls
    // back to its backend edit form (defensive — a `media:` token is an image).
    $editUrl = $media !== NULL ? (ConsoleDeepLink::editUrl($media)?->toString()
      ?? ($media->hasLinkTemplate('edit-form') ? $media->toUrl('edit-form')->toString() : NULL)) : NULL;
    return ReferenceDescriptor::create(
      token: $row['token'],
      type: 'media',
      label: $row['name'],
      description: $row['alt'],
      thumb: $row['thumb'],
      published: $media?->isPublished(),
      editUrl: $editUrl,
    );
  }

}
