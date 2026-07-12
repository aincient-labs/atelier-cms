<?php

declare(strict_types=1);

namespace Drupal\aincient_pages\Controller;

use Drupal\aincient_pages\BlockStore;
use Drupal\aincient_pages\Exception\RevisionConflictException;
use Drupal\aincient_pages\NodeModeration;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\media\MediaInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * JSON API for reusable GLOBAL BLOCKS in the page studio (chat console).
 *
 * A sibling of {@see PageController}: a global block is authored in the SAME
 * studio (sections-only, no blog regime / translation governance), so this
 * exposes the directory + load + save a block needs. A page references a block
 * with a `block` slot ({@see \Drupal\aincient_pages\ComponentCatalog}); the
 * renderer expands the block's sections inline. Gated like the other console
 * endpoints (`administer aincient pages`). All shaping goes through
 * {@see BlockStore} so the picker and the renderer see the same fragments.
 *
 * Like pages, blocks are under the `aincient_editorial` workflow: save persists
 * a DRAFT and publishing is a separate explicit transition.
 */
final class BlockController implements ContainerInjectionInterface {

  public function __construct(
    private readonly BlockStore $blocks,
    private readonly NodeModeration $moderation,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('aincient_pages.block_store'),
      $container->get('aincient_pages.moderation'),
    );
  }

  /**
   * GET /aincient/block/{media}/schema — the stored fragment for a block.
   *
   * A global block is a `block` media entity (DECISIONS 0138). `?langcode=`
   * resolves a specific translation. Loads the LATEST revision (the editable
   * head) and returns the merged fragment plus its moderation envelope (state,
   * transitions, base_vid, can_edit). VIEW is the floor to open it; view without
   * update opens read-only.
   */
  public function blockSchema(MediaInterface $media, Request $request): JsonResponse {
    if ($media->bundle() !== 'block') {
      return new JsonResponse(['error' => 'Not an AIncient block.'], 404);
    }
    $langcode = $request->query->get('langcode') ?: NULL;
    $id = (string) $media->id();
    $head = $this->moderation->loadLatestRevision($id, 'block', $langcode, 'media');
    if ($head === NULL) {
      return new JsonResponse(['error' => 'That block has no schema.'], 404);
    }
    if (!$head->access('view')) {
      return new JsonResponse(['error' => "You don’t have access to view this block."], 403);
    }
    $data = $this->blocks->loadLatest($id, $langcode);
    if ($data === NULL) {
      return new JsonResponse(['error' => 'That block has no schema.'], 404);
    }
    return new JsonResponse($data);
  }

  /**
   * POST /aincient/block/save — SAVE the working block fragment as a DRAFT.
   *
   * No longer publishes (editorial workflow): persists a forward draft revision;
   * the live block is untouched. With a `node_id` it updates that block; else a
   * new draft block is created. Body: `{ schema, node_id?, langcode?, base_vid? }`.
   */
  public function save(Request $request): JsonResponse {
    $data = $this->body($request);
    if (!is_array($data['schema'] ?? NULL)) {
      return new JsonResponse(['error' => 'Expected { "schema": { … } }.'], 400);
    }
    [$nodeId, $langcode, $baseVid] = $this->writeArgs($data);
    if ($nodeId !== NULL && ($denied = $this->denyIfNoUpdate($nodeId, $langcode)) !== NULL) {
      return $denied;
    }
    try {
      $result = $this->blocks->saveDraft($data['schema'], $nodeId, $langcode, $baseVid);
    }
    catch (RevisionConflictException $e) {
      return $this->conflict($e);
    }
    return $result === NULL
      ? new JsonResponse(['error' => 'That block does not exist, or the language is not configured.'], 404)
      : new JsonResponse($result);
  }

  /**
   * POST /aincient/block/publish — write the latest fragment then PUBLISH it.
   * Body: `{ schema?, node_id, langcode?, base_vid? }`.
   */
  public function publish(Request $request): JsonResponse {
    $data = $this->body($request);
    [$nodeId, $langcode, $baseVid] = $this->writeArgs($data);
    if ($nodeId === NULL) {
      return new JsonResponse(['error' => 'Publish needs a node_id (save a draft first).'], 400);
    }
    if (($denied = $this->denyIfNoUpdate($nodeId, $langcode)) !== NULL) {
      return $denied;
    }
    $schema = is_array($data['schema'] ?? NULL) ? $data['schema'] : NULL;
    try {
      $result = $this->blocks->publish($nodeId, $schema, $langcode, $baseVid);
    }
    catch (RevisionConflictException $e) {
      return $this->conflict($e);
    }
    return $result === NULL
      ? new JsonResponse(['error' => "You don’t have permission to publish this block."], 403)
      : new JsonResponse($result);
  }

  /**
   * POST /aincient/block/submit-review — Draft → Needs review.
   */
  public function submitReview(Request $request): JsonResponse {
    return $this->transition($request, 'submit_for_review', requireUpdate: TRUE);
  }

  /**
   * POST /aincient/block/approve — Needs review → Published (reviewer-gated).
   */
  public function approve(Request $request): JsonResponse {
    return $this->transition($request, 'approve', requireUpdate: FALSE);
  }

  /**
   * POST /aincient/block/reject — Needs review → Draft.
   */
  public function reject(Request $request): JsonResponse {
    return $this->transition($request, 'reject', requireUpdate: FALSE);
  }

  /**
   * POST /aincient/block/archive — Published → Archived.
   */
  public function archive(Request $request): JsonResponse {
    return $this->transition($request, 'archive', requireUpdate: FALSE);
  }

  /**
   * POST /aincient/block/restore — Archived → Draft.
   */
  public function restore(Request $request): JsonResponse {
    return $this->transition($request, 'restore', requireUpdate: FALSE);
  }

  /**
   * Shared pure-transition handler (mirror of {@see PageController::transition}).
   */
  private function transition(Request $request, string $transitionId, bool $requireUpdate): JsonResponse {
    $data = $this->body($request);
    $nodeId = isset($data['node_id']) && $data['node_id'] !== '' ? (string) $data['node_id'] : NULL;
    if ($nodeId === NULL) {
      return new JsonResponse(['error' => 'Expected a node_id.'], 400);
    }
    $baseVid = isset($data['base_vid']) && is_numeric($data['base_vid']) ? (int) $data['base_vid'] : NULL;
    if ($requireUpdate && ($denied = $this->denyIfNoUpdate($nodeId, NULL)) !== NULL) {
      return $denied;
    }
    try {
      $result = $this->blocks->transition($nodeId, $transitionId, $baseVid);
    }
    catch (RevisionConflictException $e) {
      return $this->conflict($e);
    }
    return $result === NULL
      ? new JsonResponse(['error' => "That change isn’t available — the block may have moved on, or you don’t hold this transition."], 403)
      : new JsonResponse($result);
  }

  private function body(Request $request): array {
    $data = json_decode((string) $request->getContent(), TRUE);
    return is_array($data) ? $data : [];
  }

  /**
   * @return array{0: ?string, 1: ?string, 2: ?int}
   */
  private function writeArgs(array $data): array {
    return [
      isset($data['node_id']) && $data['node_id'] !== '' ? (string) $data['node_id'] : NULL,
      isset($data['langcode']) && $data['langcode'] !== '' ? (string) $data['langcode'] : NULL,
      isset($data['base_vid']) && is_numeric($data['base_vid']) ? (int) $data['base_vid'] : NULL,
    ];
  }

  private function denyIfNoUpdate(string $nodeId, ?string $langcode): ?JsonResponse {
    $head = $this->moderation->loadLatestRevision($nodeId, 'block', $langcode, 'media');
    if ($head === NULL) {
      return new JsonResponse(['error' => 'That block does not exist.'], 404);
    }
    if (!$head->access('update')) {
      return new JsonResponse(['error' => "You don’t have access to edit this block."], 403);
    }
    return NULL;
  }

  private function conflict(RevisionConflictException $e): JsonResponse {
    return new JsonResponse([
      'error' => 'This block changed since you opened it — saving would overwrite newer work.',
      'conflict' => TRUE,
      'base_vid' => $e->expectedVid,
      'current_vid' => $e->currentVid,
    ], 409);
  }

}
