<?php

declare(strict_types=1);

namespace Drupal\aincient_audit;

use Drupal\aincient_audit\Check\CheckInterface;
use Drupal\aincient_pages\PageStore;
use Drupal\node\NodeInterface;

/**
 * Deterministic, read-only page auditor — the single source of audit findings.
 *
 * Both the Checks studio panel ({@see \Drupal\aincient_audit\Controller\AuditController})
 * and the page agent (the `run_page_audit` capability) call this, so the numbers
 * they show always agree. It WRITES NOTHING and makes no network requests.
 *
 * As of DECISIONS 0129 this is a thin facade: it owns the report envelope
 * (node id/title/url) and delegates the pass/warn/fail summary + the findings to
 * {@see PolicyEvaluator}, which runs the shipped default policies (`seo`,
 * `links`) AS deterministic FlowDrop workflows — synchronously and DB-free.
 * Meta-tag reading moved to {@see MetaTagReader} (shared with the
 * `read_meta_tags` capability). Behaviour is byte-identical to the old
 * direct-check report; the machinery changed, not the numbers (findings now
 * additionally carry a `policyId`).
 *
 * v1 ships two checks — SEO/meta tags and internal-link integrity. External
 * links are listed but deliberately NOT fetched (an HTTP round-trip per link
 * is slow).
 */
final class AuditEngine {

  /**
   * Severity levels (worst first) — kept as aliases of {@see CheckInterface}
   * for any caller that still references `AuditEngine::FAIL`.
   */
  public const FAIL = CheckInterface::FAIL;
  public const WARN = CheckInterface::WARN;
  public const PASS = CheckInterface::PASS;

  /**
   * @param \Drupal\aincient_pages\PageStore $store
   *   Resolves the canonical page URL for the report envelope.
   * @param \Drupal\aincient_audit\MetaTagReader $metaReader
   *   Backs the public metaTags() the `read_meta_tags` capability calls.
   * @param \Drupal\aincient_audit\PolicyEvaluator $evaluator
   *   Runs the shipped default policies (as workflows) → summary + findings.
   */
  public function __construct(
    private readonly PageStore $store,
    private readonly MetaTagReader $metaReader,
    private readonly PolicyEvaluator $evaluator,
  ) {}

  /**
   * Audit a page node → a structured, JSON-serialisable report.
   *
   * @param \Drupal\node\NodeInterface $node
   *   An `aincient_page` node (the caller validates the bundle).
   *
   * @return array
   *   `{ node_id, title, url, summary:{pass,warn,fail,total}, checks:[{key,
   *   label, findings:[{id,severity,title,detail,location}]}] }`.
   */
  public function audit(NodeInterface $node): array {
    $body = $this->evaluator->evaluate($node);
    return [
      'node_id' => (string) $node->id(),
      'title' => (string) $node->label(),
      'url' => $this->store->url((string) $node->id(), FALSE),
      'summary' => $body['summary'],
      'checks' => $body['checks'],
    ];
  }

  /**
   * The effective meta tags for a node, resolved (tokens replaced) → {key: value}.
   *
   * Delegates to {@see MetaTagReader}; kept here so the `read_meta_tags`
   * capability's injection of `aincient_audit.engine` is untouched.
   *
   * @return array<string, string>
   */
  public function metaTags(NodeInterface $node): array {
    return $this->metaReader->tags($node);
  }

}
