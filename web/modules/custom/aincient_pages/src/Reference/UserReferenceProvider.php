<?php

declare(strict_types=1);

namespace Drupal\aincient_pages\Reference;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\user\UserInterface;

/**
 * The `entity:user:<id>` reference type — people (authors, team members).
 *
 * The first provider to extend the catalog beyond the page-composition types,
 * proving the seam: a user account becomes embeddable (an author byline, a team
 * card) with no change to the catalog, controller, React field or agent — just
 * this tagged provider. The bare token renders the user in its default view mode
 * via the view builder ({@see \Drupal\aincient_pages\EntityEmbedResolver::render()});
 * the studio appends a `@viewmode` suffix when one is chosen.
 *
 * Anonymous (uid 0) is never referenceable; `status` maps to active/blocked.
 */
final class UserReferenceProvider implements ReferenceProviderInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  public function typeKey(): string {
    return 'user';
  }

  public function search(?string $query, int $limit): array {
    $storage = $this->entityTypeManager->getStorage('user');
    $q = $storage->getQuery()
      ->accessCheck(TRUE)
      // Skip anonymous (uid 0) — it is not a real, referenceable person.
      ->condition('uid', 0, '>')
      ->sort('changed', 'DESC')
      ->range(0, $limit);
    if ($query !== NULL && trim($query) !== '') {
      $q->condition('name', trim($query), 'CONTAINS');
    }
    $out = [];
    foreach ($storage->loadMultiple($q->execute()) as $user) {
      if ($user instanceof UserInterface) {
        $out[] = $this->toDescriptor($user);
      }
    }
    return $out;
  }

  public function describe(int $id): ?array {
    $user = $this->entityTypeManager->getStorage('user')->load($id);
    return $user instanceof UserInterface && (int) $user->id() > 0
      ? $this->toDescriptor($user)
      : NULL;
  }

  private function toDescriptor(UserInterface $user): array {
    return ReferenceDescriptor::create(
      token: 'entity:user:' . $user->id(),
      type: 'user',
      label: $user->getDisplayName(),
      description: (string) $user->getEmail(),
      thumb: $this->thumb($user),
      // A blocked account maps to the descriptor's "unpublished" badge.
      published: $user->isActive(),
      editUrl: $user->hasLinkTemplate('edit-form')
        ? $user->toUrl('edit-form')->toString()
        : NULL,
    );
  }

  /**
   * A thumbnail URL from the user picture, or NULL when none is set.
   *
   * Reuses the media/file token path for the avatar image style so the card gets
   * a sensibly sized derivative, mirroring NodeReferenceProvider::thumb().
   */
  private function thumb(UserInterface $user): ?string {
    if (!$user->hasField('user_picture') || $user->get('user_picture')->isEmpty()) {
      return NULL;
    }
    $fid = $user->get('user_picture')->first()->target_id ?? NULL;
    if ($fid === NULL) {
      return NULL;
    }
    $file = $this->entityTypeManager->getStorage('file')->load((int) $fid);
    if ($file === NULL) {
      return NULL;
    }
    $style = $this->entityTypeManager->getStorage('image_style')->load('thumbnail');
    return $style
      ? $this->fileUrlGenerator->transformRelative($style->buildUrl($file->getFileUri()))
      : $this->fileUrlGenerator->generateString($file->getFileUri());
  }

}
