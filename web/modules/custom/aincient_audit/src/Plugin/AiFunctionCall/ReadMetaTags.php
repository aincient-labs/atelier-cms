<?php

declare(strict_types=1);

namespace Drupal\aincient_audit\Plugin\AiFunctionCall;

use Drupal\aincient_audit\AuditEngine;
use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * AIncient capability: read a page's current effective meta tags.
 *
 * A focused, read-only companion to {@see RunPageAudit} for "what meta tags does
 * this page have right now?" questions. It returns the RESOLVED tags (tokens
 * replaced — e.g. `[node:title]` becomes the real title) via {@see AuditEngine},
 * so the values match what the SEO check evaluates and what ships in the page's
 * <head>. Read-only: it reports, it never writes.
 */
#[FunctionCall(
  id: 'aincient_audit:read_meta_tags',
  function_name: 'aincient_read_meta_tags',
  name: 'Read meta tags',
  description: 'Read the current effective meta tags (title, description, canonical, Open Graph) for a page, with tokens already resolved to real values. Call this when the user asks what meta / SEO tags a page currently has. Takes the page node id. Read-only.',
  context_definitions: [
    'node_id' => new ContextDefinition(
      data_type: 'integer',
      label: new TranslatableMarkup('Node ID'),
      description: new TranslatableMarkup('The aincient_page node id to read meta tags for.'),
      required: TRUE,
    ),
  ],
)]
final class ReadMetaTags extends FunctionCallBase implements ExecutableFunctionCallInterface {

  protected AuditEngine $engine;
  protected EntityTypeManagerInterface $entityTypeManager;
  protected AccountInterface $currentUser;
  protected string $result = '';

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->engine = $container->get('aincient_audit.engine');
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->currentUser = $container->get('current_user');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function execute(): void {
    if (!$this->currentUser->hasPermission('administer aincient pages')) {
      $this->result = 'Error: you do not have permission to read page meta tags.';
      return;
    }

    $node_id = $this->getContextValue('node_id');
    if (!is_numeric($node_id)) {
      $this->result = 'Error: provide the numeric node id of the page.';
      return;
    }

    $node = $this->entityTypeManager->getStorage('node')->load((int) $node_id);
    if ($node === NULL || $node->bundle() !== 'aincient_page') {
      $this->result = sprintf('Error: node %s is not an AIncient page.', (string) $node_id);
      return;
    }

    $tags = $this->engine->metaTags($node);
    $this->result = (string) json_encode(['node_id' => (string) $node->id(), 'tags' => $tags], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
  }

  /**
   * {@inheritdoc}
   */
  public function getReadableOutput(): string {
    return $this->result;
  }

}
