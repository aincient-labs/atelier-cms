<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Plugin\AiCapability;

use Drupal\aincient_core\Attribute\Capability;
use Drupal\aincient_core\Capability\CapabilityBase;
use Drupal\aincient_core\Capability\ExecutableCapabilityInterface;
use Drupal\aincient_core\Docs\DocsCorpus;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Read one page of the Atelier docs bundled with this release.
 *
 * Read-only, our own shipped text: taint-safe. The follow-up to search_docs
 * when a section is not enough (a full install or upgrade walkthrough).
 */
#[Capability(
  id: 'aincient_core:read_docs_page',
  function_name: 'aincient_read_docs_page',
  name: 'Read a docs page',
  description: 'Read one whole page of the Atelier documentation, e.g. after search_docs when the section is not enough. Pass "page": the page slug or link from a search result, e.g. "quickstart" or "https://aincient-labs.com/docs/component-packs".',
  context_definitions: [
    'page' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Page'),
      description: new TranslatableMarkup('The docs page slug or link, e.g. "quickstart".'),
      required: TRUE,
    ),
  ],
)]
final class ReadDocsPage extends CapabilityBase implements ExecutableCapabilityInterface {

  /**
   * Longest page text returned (the longest page today is ~16k characters).
   */
  private const MAX_CHARS = 20000;

  protected DocsCorpus $docs;

  protected AccountInterface $currentUser;

  protected string $result = '';

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->docs = $container->get('aincient_core.docs_corpus');
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
    $page = $this->docs->page((string) ($this->getContextValue('page') ?? ''));
    if ($page === NULL) {
      $this->result = 'Error: no docs page by that name. Use search_docs to find the right page. Pages: ' . implode(', ', $this->docs->slugs());
      return;
    }
    $text = $page['text'];
    if (mb_strlen($text) > self::MAX_CHARS) {
      $text = rtrim(mb_substr($text, 0, self::MAX_CHARS)) . "\n… (page continues at the link above)";
    }
    $this->result = sprintf("Docs page: %s\n%s\n%s\n\n%s", $page['title'], $page['url'], $page['description'], $text);
  }

  /**
   * {@inheritdoc}
   */
  public function getReadableOutput(): string {
    return $this->result;
  }

}
