<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Plugin\AiCapability;

use Drupal\aincient_core\Attribute\Capability;
use Drupal\aincient_core\Capability\CapabilityBase;
use Drupal\aincient_core\Capability\ExecutableCapabilityInterface;
use Drupal\aincient_core\Search\SearchIndex;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Search the Atelier docs bundled with this release.
 *
 * Read-only, and the text it returns is OUR docs (shipped in the release, not
 * fetched), so it is taint-safe. Returns the best sections with their public
 * links, so the assistant can answer from the docs and point at the page.
 */
#[Capability(
  id: 'aincient_core:search_docs',
  function_name: 'aincient_search_docs',
  name: 'Search the docs',
  description: 'Search the Atelier documentation (the docs for the version installed here) and get the best-matching sections with their links. Call this BEFORE answering any question about how Atelier works, what it can or cannot do, where something is in the console, how to install, update, back up or deploy, or what a setting means — and answer from what it returns, linking the page. Pass "query": a few keywords, e.g. "create new component" or "update Atelier". If nothing fits, say the docs do not cover it; never invent a feature or a menu.',
  context_definitions: [
    'query' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Query'),
      description: new TranslatableMarkup('Keywords to search for, e.g. "carousel lightbox" or "restore a backup".'),
      required: TRUE,
    ),
  ],
)]
final class SearchDocs extends CapabilityBase implements ExecutableCapabilityInterface {

  /**
   * Sections returned per search.
   */
  private const LIMIT = 5;

  /**
   * Characters of each section's text included in the result.
   */
  private const SECTION_CHARS = 1500;

  protected SearchIndex $index;

  protected AccountInterface $currentUser;

  protected string $result = '';

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->index = $container->get('aincient_core.search_index');
    $instance->currentUser = $container->get('current_user');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function execute(): void {
    if (!$this->currentUser->hasPermission('use aincient operator console')) {
      $this->result = 'Error: you do not have permission to use the assistant.';
      return;
    }
    $query = trim((string) ($this->getContextValue('query') ?? ''));
    if ($query === '') {
      $this->result = 'Error: pass "query" with a few keywords to search the docs for.';
      return;
    }
    $this->result = SearchIndex::formatDocsHits($query, $this->index->searchDocs($query, self::LIMIT), self::SECTION_CHARS);
  }

  /**
   * {@inheritdoc}
   */
  public function getReadableOutput(): string {
    return $this->result;
  }

}
