<?php

declare(strict_types=1);

namespace Drupal\aincient_pages\Controller;

use Drupal\aincient_pages\EditLock;
use Drupal\aincient_pages\NodeModeration;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * JSON API for the single-writer editor lock (see {@see EditLock}).
 *
 * The console acquires a lock when a studio opens a page for editing, carries
 * the returned fencing token in studio state, and presents it on every write
 * (PageController gates the write on it). Handover between the Content and Checks
 * studios re-acquires with the carried token (same session → keeps the lock);
 * a second tab or another user must take over explicitly ({@see acquire} force).
 *
 * Acquire is gated on real UPDATE access to the head revision — the same floor
 * every write uses — so a view-only user can never take the pen (the dedicated
 * "may acquire" permission is deferred; node access is the floor for now).
 */
final class LockController implements ContainerInjectionInterface {

  public function __construct(
    private readonly EditLock $lock,
    private readonly NodeModeration $moderation,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('aincient_pages.edit_lock'),
      $container->get('aincient_pages.moderation'),
    );
  }

  /**
   * POST /atelier/page/lock/acquire — take (or report) the lock for a page.
   *
   * Body: `{ node_id, langcode?, studio, token?, force? }`. Returns the
   * {@see EditLock::acquire} envelope: `{ status, token, holder }` where status ∈
   * {acquired, held_self, held_other}. A held_* result means another session
   * holds it — the console offers an explicit take-over that re-POSTs `force:true`.
   */
  public function acquire(Request $request): JsonResponse {
    $data = $this->body($request);
    [$nid, $langcode] = $this->target($data);
    if ($nid === NULL) {
      return new JsonResponse(['error' => 'Expected a node_id.'], 400);
    }
    $studio = isset($data['studio']) && $data['studio'] !== '' ? (string) $data['studio'] : 'content';
    if (($denied = $this->denyIfNoUpdate($nid, $langcode)) !== NULL) {
      return $denied;
    }
    $token = isset($data['token']) && $data['token'] !== '' ? (string) $data['token'] : NULL;
    $result = $this->lock->acquire((int) $nid, $langcode, $studio, $token, !empty($data['force']));
    return new JsonResponse($result);
  }

  /**
   * POST /atelier/page/lock/release — drop the lock (clean exit / handover done).
   *
   * Body: `{ node_id, langcode?, token, force? }`. A non-matching token no-ops
   * unless `force:true` (the explicit force-release path). Returns `{ released }`.
   */
  public function release(Request $request): JsonResponse {
    $data = $this->body($request);
    [$nid, $langcode] = $this->target($data);
    if ($nid === NULL) {
      return new JsonResponse(['error' => 'Expected a node_id.'], 400);
    }
    // Force-release is a deliberate takeover step — still gated on update access
    // (only someone who could edit may seize the pen).
    if (!empty($data['force']) && ($denied = $this->denyIfNoUpdate($nid, $langcode)) !== NULL) {
      return $denied;
    }
    $token = isset($data['token']) ? (string) $data['token'] : NULL;
    $released = $this->lock->release((int) $nid, $langcode, $token, !empty($data['force']));
    return new JsonResponse(['released' => $released]);
  }

  /**
   * GET /atelier/page/lock/status?node_id=&langcode= — the current holder.
   *
   * Returns `{ holder: {…}|null }`. Cheap; the console uses it to refresh the
   * "locked by …" affordance without re-acquiring.
   */
  public function status(Request $request): JsonResponse {
    $nid = $request->query->get('node_id');
    if ($nid === NULL || $nid === '' || !is_numeric($nid)) {
      return new JsonResponse(['error' => 'Expected a node_id.'], 400);
    }
    $langcode = (string) ($request->query->get('langcode') ?? '');
    return new JsonResponse(['holder' => $this->lock->status((int) $nid, $langcode)]);
  }

  /**
   * Parse a JSON body to an array (empty if absent/malformed).
   */
  private function body(Request $request): array {
    $data = json_decode((string) $request->getContent(), TRUE);
    return is_array($data) ? $data : [];
  }

  /**
   * Extract (node_id, langcode) from a body — langcode normalised to '' for the
   * source/default translation (the lock's composite-key convention: the source
   * language is the empty-string partition, matching how writes address it).
   *
   * @return array{0: ?string, 1: string}
   */
  private function target(array $data): array {
    $nid = isset($data['node_id']) && $data['node_id'] !== '' ? (string) $data['node_id'] : NULL;
    $langcode = isset($data['langcode']) && $data['langcode'] !== '' ? (string) $data['langcode'] : '';
    return [$nid, $langcode];
  }

  /**
   * 403/404 if the current user can't UPDATE the head revision — the floor for
   * taking the pen. NULL when acquire may proceed.
   */
  private function denyIfNoUpdate(string $nid, string $langcode): ?JsonResponse {
    $head = $this->moderation->loadLatestRevision($nid, 'aincient_page', $langcode !== '' ? $langcode : NULL);
    if ($head === NULL) {
      return new JsonResponse(['error' => 'That page does not exist.'], 404);
    }
    if (!$head->access('update')) {
      return new JsonResponse(['error' => "You don’t have access to edit this page."], 403);
    }
    return NULL;
  }

}
