<?php

declare(strict_types=1);

namespace Drupal\aincient_pages\Exception;

/**
 * Thrown when a write is attempted over a revision that is no longer the head.
 *
 * The optimistic-concurrency guard (see the editorial-workflow plan): the studio
 * sends the `base_vid` it loaded; if the node has since advanced to a newer
 * revision, the write is refused rather than silently clobbering the newer work.
 * The controller maps this to an HTTP 409 Conflict so the studio can offer
 * "Reload latest" instead of blind-overwriting.
 */
final class RevisionConflictException extends \RuntimeException {

  public function __construct(
    public readonly int $expectedVid,
    public readonly ?int $currentVid,
  ) {
    parent::__construct(sprintf(
      'This content changed since you opened it (expected revision %d, current %s).',
      $expectedVid,
      $currentVid === NULL ? 'none' : (string) $currentVid,
    ));
  }

}
