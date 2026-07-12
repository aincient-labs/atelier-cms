<?php

declare(strict_types=1);

namespace Drupal\aincient_audit\Plugin\AiFunctionCall;

use Drupal\aincient_audit\AuditEngine;
use Drupal\aincient_pages\NodeModeration;
use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * AIncient capability: run the read-only page health audit.
 *
 * The Checks studio agent's primary tool. It computes NOTHING in the model —
 * it delegates to the deterministic {@see AuditEngine} (the same engine the
 * studio panel fetches), so the agent narrates real findings rather than
 * inventing them. Read-only by construction: it audits, it never writes.
 *
 * Returns the structured report as a JSON string the model reads and summarises.
 */
#[FunctionCall(
  id: 'aincient_audit:run_page_audit',
  function_name: 'aincient_run_page_audit',
  name: 'Run page audit',
  description: 'Run read-only health checks (SEO, meta tags, internal-link integrity) on an existing page and return the findings. Call this when the user asks to audit / check / review a page for SEO, meta tags, or broken links. Takes the page node id. It only reports — it changes nothing.',
  context_definitions: [
    'node_id' => new ContextDefinition(
      data_type: 'integer',
      label: new TranslatableMarkup('Node ID'),
      description: new TranslatableMarkup('The aincient_page node id to audit.'),
      required: TRUE,
    ),
  ],
)]
final class RunPageAudit extends FunctionCallBase implements ExecutableFunctionCallInterface {

  protected AuditEngine $engine;
  protected EntityTypeManagerInterface $entityTypeManager;
  protected NodeModeration $moderation;
  protected AccountInterface $currentUser;
  protected string $result = '';

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->engine = $container->get('aincient_audit.engine');
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->moderation = $container->get('aincient_pages.moderation');
    $instance->currentUser = $container->get('current_user');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function execute(): void {
    if (!$this->currentUser->hasPermission('administer aincient pages')) {
      $this->result = 'Error: you do not have permission to audit pages.';
      return;
    }

    $node_id = $this->getContextValue('node_id');
    if (!is_numeric($node_id)) {
      $this->result = 'Error: provide the numeric node id of the page to audit.';
      return;
    }

    // Audit the LATEST revision (the editable draft head) so the agent narrates
    // the same findings as the studio panel and reflects a staged-then-saved
    // draft fix, not the stale published default.
    $node = $this->moderation->loadLatestRevision((string) $node_id, 'aincient_page');
    if ($node === NULL) {
      $this->result = sprintf('Error: node %s is not an AIncient page.', (string) $node_id);
      return;
    }

    $report = $this->engine->audit($node);
    $this->result = (string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
  }

  /**
   * {@inheritdoc}
   */
  public function getReadableOutput(): string {
    return $this->result;
  }

}
