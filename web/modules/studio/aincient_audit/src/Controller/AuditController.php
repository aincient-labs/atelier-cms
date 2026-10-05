<?php

declare(strict_types=1);

namespace Drupal\aincient_audit\Controller;

use Drupal\aincient_audit\AuditEngine;
use Drupal\aincient_audit\AuditTarget;
use Drupal\aincient_pages\PageStore;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * JSON API for the Checks studio (consumed by the chat console).
 *
 * The audit parallel of {@see \Drupal\aincient_pages\Controller\PageController},
 * but READ-ONLY: there is no preview and no save. The panel fetches a page's
 * findings here directly (independent of chat), and the agent's `run_page_audit`
 * capability calls the SAME {@see AuditEngine}, so the two always agree.
 */
final class AuditController implements ContainerInjectionInterface {

  public function __construct(
    private readonly AuditEngine $engine,
    private readonly AuditTarget $target,
    private readonly PageStore $store,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('aincient_audit.engine'),
      $container->get('aincient_audit.target'),
      $container->get('aincient_pages.store'),
    );
  }

  /**
   * GET /atelier/audit/{node}/report — the read-only findings for a page
   * (POST: the unsaved draft, {@see self::reportUnsaved}).
   *
   * Audits the LIVE page by default (DECISIONS 0450); `?revision=draft` audits
   * the latest saved draft instead — the Checks studio always asks for it, so
   * its Save draft → re-check loop grades the fix about to ship. The report's
   * `audited` block names the copy actually read. The route's default-revision
   * load stays the gate for existence / bundle / VIEW access.
   */
  public function report(NodeInterface $node, Request $request): JsonResponse {
    if ($node->bundle() !== 'aincient_page') {
      return new JsonResponse(['error' => 'Not an AIncient page.'], 404);
    }
    // Per-node gate (the route checks only the broad permission). The audit is
    // read-only, so VIEW access is the right bar: a page the user can't see
    // 403s here and the Checks studio shows the access-denied end-state.
    if (!$node->access('view')) {
      return new JsonResponse(['error' => "You don’t have access to this page."], 403);
    }
    if ($request->isMethod('POST')) {
      return $this->reportUnsaved($node, $request);
    }
    $langcode = $request->query->get('langcode');
    $langcode = is_string($langcode) && $langcode !== '' ? $langcode : NULL;
    $revision = AuditTarget::normalize($request->query->get('revision'));
    $target = $this->target->load((string) $node->id(), $revision, $langcode) ?? $node;
    return new JsonResponse($this->engine->audit($target, $revision));
  }

  /**
   * POST /atelier/audit/{node}/report `{schema, langcode}` — the findings for
   * the studio's UNSAVED draft (DECISIONS 0453), so a staged fix reads "Fixed
   * in draft" before Save, graded by the same checks as the gate.
   *
   * The schema is written onto a CLONE of the latest revision through
   * {@see PageStore::writeSchema} — the single write path, so the checks see
   * exactly what Save would store — and the clone is never saved.
   */
  private function reportUnsaved(NodeInterface $node, Request $request): JsonResponse {
    $data = json_decode((string) $request->getContent(), TRUE);
    if (!is_array($data) || !is_array($data['schema'] ?? NULL)) {
      return new JsonResponse(['error' => 'Expected {schema, langcode}.'], 400);
    }
    $langcode = is_string($data['langcode'] ?? NULL) && $data['langcode'] !== '' ? $data['langcode'] : NULL;
    $head = $this->target->load((string) $node->id(), AuditTarget::DRAFT, $langcode);
    if ($head === NULL) {
      return new JsonResponse(['error' => 'Not an AIncient page.'], 404);
    }
    $draft = clone $head;
    $this->store->writeSchema($draft, $data['schema']);
    try {
      return new JsonResponse($this->engine->auditUnsaved($draft));
    }
    catch (\LogicException $e) {
      // A policy the unsaved path can't reproduce: the client keeps the saved
      // report rather than show a partial one.
      return new JsonResponse(['error' => $e->getMessage()], 501);
    }
  }

}
