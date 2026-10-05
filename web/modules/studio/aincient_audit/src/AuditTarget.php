<?php

declare(strict_types=1);

namespace Drupal\aincient_audit;

use Drupal\aincient_pages\NodeModeration;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

/**
 * Picks the page revision an audit reads — the one resolver every audit path
 * shares (the Checks report endpoint, `run_page_audit`, `read_meta_tags` and
 * the `policy_check` workflow node), so they can never check different copies.
 *
 * DECISIONS 0450: the default is LIVE — what visitors see. The Checks studio
 * asks for DRAFT explicitly, so its Save draft → re-check loop still grades the
 * fix that's about to ship. A request that names a copy which doesn't exist
 * falls to the other one: a page (or translation) that was never published has
 * only a draft, and a page with no pending draft is its live copy. The report
 * says which copy it actually read ({@see self::kind}).
 */
final class AuditTarget {

  /**
   * The published default revision — what visitors see.
   */
  public const LIVE = 'live';

  /**
   * The editable head — the latest saved draft, about to ship.
   */
  public const DRAFT = 'draft';

  private const BUNDLE = 'aincient_page';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly NodeModeration $moderation,
  ) {}

  /**
   * A requested revision from a query string or a tool argument: DRAFT only
   * when asked for by name, anything else is LIVE.
   */
  public static function normalize(mixed $revision): string {
    return $revision === self::DRAFT ? self::DRAFT : self::LIVE;
  }

  /**
   * Load the copy of a page to audit, in $langcode when given.
   *
   * @return \Drupal\node\NodeInterface|null
   *   NULL when the node is gone or not an aincient_page.
   */
  public function load(string $nodeId, string $revision = self::LIVE, ?string $langcode = NULL): ?NodeInterface {
    if ($revision === self::DRAFT) {
      $node = $this->moderation->loadLatestRevision($nodeId, self::BUNDLE, $langcode);
      return $node instanceof NodeInterface ? $node : NULL;
    }
    $node = $this->entityTypeManager->getStorage('node')->load((int) $nodeId);
    if (!$node instanceof NodeInterface || $node->bundle() !== self::BUNDLE) {
      return NULL;
    }
    if ($langcode !== NULL) {
      // A translation that exists only in a draft has no live copy.
      if (!$node->hasTranslation($langcode)) {
        return $this->load($nodeId, self::DRAFT, $langcode);
      }
      $node = $node->getTranslation($langcode);
    }
    // Never published (in this language): the draft is the only copy there is.
    return $node->isPublished() ? $node : $this->load($nodeId, self::DRAFT, $langcode);
  }

  /**
   * Which copy a loaded revision is: LIVE when it is the published default
   * revision, DRAFT otherwise.
   */
  public function kind(NodeInterface $node): string {
    return $node->isDefaultRevision() && $node->isPublished() ? self::LIVE : self::DRAFT;
  }

}
