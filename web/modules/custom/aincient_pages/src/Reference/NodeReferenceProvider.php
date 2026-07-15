<?php

declare(strict_types=1);

namespace Drupal\aincient_pages\Reference;

use Drupal\aincient_pages\ConsoleDeepLink;
use Drupal\aincient_pages\EntityEmbedResolver;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

/**
 * The `entity:node:<id>` reference type — embeddable content (pages, articles).
 *
 * Absorbs the query that used to live in EmbedController::find (published nodes,
 * EXCEPT global blocks — those are placed via a `block` slot, not embedded) and
 * adds the descriptor's gloss (body summary), thumb (first referenced media image,
 * best-effort), status and edit link. The default view mode is implicit in the
 * bare token; the studio appends a `@viewmode` suffix when one is chosen.
 */
final class NodeReferenceProvider implements ReferenceProviderInterface {

  /**
   * The bundle that is a global block, not an embeddable page.
   */
  private const BLOCK_BUNDLE = 'aincient_block';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityEmbedResolver $embed,
  ) {}

  public function typeKey(): string {
    return 'node';
  }

  public function search(?string $query, int $limit): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $q = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('status', 1)
      ->condition('type', self::BLOCK_BUNDLE, '<>')
      ->sort('changed', 'DESC')
      ->range(0, $limit);
    if ($query !== NULL && trim($query) !== '') {
      $q->condition('title', trim($query), 'CONTAINS');
    }
    $out = [];
    foreach ($storage->loadMultiple($q->execute()) as $node) {
      if ($node instanceof NodeInterface) {
        $out[] = $this->toDescriptor($node);
      }
    }
    return $out;
  }

  public function describe(int $id): ?array {
    $node = $this->entityTypeManager->getStorage('node')->load($id);
    // Permissive: preview any node a stored token points at (even an access-
    // controlled one resolves for the admin viewing the studio).
    return $node instanceof NodeInterface ? $this->toDescriptor($node) : NULL;
  }

  private function toDescriptor(NodeInterface $node): array {
    // An aincient_page edits in the Content studio (/atelier/content/node/<nid>),
    // not the raw node form. A node no studio owns (e.g. a plain article) keeps
    // its backend edit form — there is no studio equivalent to send it to.
    $editUrl = ConsoleDeepLink::editUrl($node)?->toString()
      ?? ($node->hasLinkTemplate('edit-form') ? $node->toUrl('edit-form')->toString() : NULL);
    return ReferenceDescriptor::create(
      token: 'entity:node:' . $node->id(),
      type: 'node',
      label: (string) $node->label(),
      description: $this->summary($node),
      thumb: $this->thumb($node),
      published: $node->isPublished(),
      editUrl: $editUrl,
      meta: ['bundle' => $node->bundle()],
    );
  }

  /**
   * A one-line gloss from the node body (summary or trimmed text), or ''.
   */
  private function summary(NodeInterface $node): string {
    if (!$node->hasField('body') || $node->get('body')->isEmpty()) {
      return '';
    }
    $item = $node->get('body')->first();
    $text = (string) ($item->summary ?? '');
    if (trim($text) === '') {
      $text = (string) ($item->value ?? '');
    }
    $text = trim(strip_tags($text));
    return $text === '' ? '' : Unicode::truncate($text, 140, TRUE, TRUE);
  }

  /**
   * A thumbnail URL from the node's first referenced media image, best-effort.
   *
   * Covers the common case (content that references a media image); a raw image
   * field gets no thumb here (the card falls back to a generic icon) — kept
   * deliberately narrow so token semantics stay inside EntityEmbedResolver.
   */
  private function thumb(NodeInterface $node): ?string {
    foreach ($node->getFieldDefinitions() as $name => $def) {
      if ($def->getType() !== 'entity_reference'
        || $def->getSetting('target_type') !== 'media'
        || $node->get($name)->isEmpty()) {
        continue;
      }
      $mid = $node->get($name)->first()->target_id ?? NULL;
      $url = $mid ? $this->embed->url('media:' . $mid, 'media_library') : NULL;
      if ($url !== NULL) {
        return $url;
      }
    }
    return NULL;
  }

}
