<?php

declare(strict_types=1);

namespace Drupal\aincient_audit\Plugin\AiCapability;

use Drupal\aincient_audit\AuditEngine;
use Drupal\aincient_audit\AuditTarget;
use Drupal\aincient_core\Attribute\Capability;
use Drupal\aincient_core\Capability\CapabilityBase;
use Drupal\aincient_core\Capability\ExecutableCapabilityInterface;
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
#[Capability(
  id: 'aincient_audit:run_page_audit',
  function_name: 'aincient_run_page_audit',
  name: 'Run page audit',
  description: 'Run read-only health checks (SEO, meta tags, internal-link integrity) on an existing page and return the findings. Call this when the user asks to audit / check / review a page for SEO, meta tags, or broken links. Takes the page node id and optionally which copy to check: the live page (default) or "draft" (the latest saved draft). The report says which copy it checked. It only reports — it changes nothing.',
  context_definitions: [
    'node_id' => new ContextDefinition(
      data_type: 'integer',
      label: new TranslatableMarkup('Node ID'),
      description: new TranslatableMarkup('The aincient_page node id to audit.'),
      required: TRUE,
    ),
    'revision' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Revision'),
      description: new TranslatableMarkup('Which copy to audit: "draft" for the latest saved draft (what is about to ship). Omit for the live page visitors see.'),
      required: FALSE,
    ),
  ],
)]
final class RunPageAudit extends CapabilityBase implements ExecutableCapabilityInterface {

  protected AuditEngine $engine;
  protected EntityTypeManagerInterface $entityTypeManager;
  protected AuditTarget $target;
  protected AccountInterface $currentUser;
  protected string $result = '';

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->engine = $container->get('aincient_audit.engine');
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->target = $container->get('aincient_audit.target');
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

    // Live by default (DECISIONS 0450); the Checks agent passes "draft" so it
    // narrates the same copy the studio panel grades. Same resolver as the panel.
    $revision = AuditTarget::normalize($this->getContextValue('revision'));
    $node = $this->target->load((string) $node_id, $revision);
    if ($node === NULL) {
      $this->result = sprintf('Error: node %s is not an AIncient page.', (string) $node_id);
      return;
    }

    $report = $this->engine->audit($node, $revision);
    $this->result = (string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
  }

  /**
   * {@inheritdoc}
   */
  public function getReadableOutput(): string {
    return $this->result;
  }

}
