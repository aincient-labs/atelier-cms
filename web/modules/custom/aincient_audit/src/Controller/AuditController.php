<?php

declare(strict_types=1);

namespace Drupal\aincient_audit\Controller;

use Drupal\aincient_audit\AuditEngine;
use Drupal\aincient_pages\NodeModeration;
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
    private readonly NodeModeration $moderation,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('aincient_audit.engine'),
      $container->get('aincient_pages.moderation'),
    );
  }

  /**
   * GET /atelier/audit/{node}/report — the read-only findings for a page.
   *
   * Audits the LATEST revision (the editable draft head), not the published
   * default — so the Checks fix loop's re-run reflects a fix that's been staged
   * and Saved as a draft (per Plan A: the audit runs on the settled draft,
   * "what's about to ship"). The route's default-revision load stays the gate
   * for existence / bundle / VIEW access.
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
    $langcode = $request->query->get('langcode');
    $langcode = is_string($langcode) && $langcode !== '' ? $langcode : NULL;
    $head = $this->moderation->loadLatestRevision((string) $node->id(), 'aincient_page', $langcode) ?? $node;
    return new JsonResponse($this->engine->audit($head));
  }

}
