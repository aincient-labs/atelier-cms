<?php

declare(strict_types=1);

namespace Drupal\aincient_pages\Plugin\AiFunctionCall;

use Drupal\aincient_pages\Reference\ReferenceCatalog;
use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * AIncient capability: find REAL content to reference in a page.
 *
 * The page agent's one bridge to existing entities. The page schema stores every
 * reference as a text TOKEN (resolved at render by
 * {@see \Drupal\aincient_pages\EntityEmbedResolver}); this tool searches the
 * unified reference catalog ({@see ReferenceCatalog} — the SAME surface the studio
 * picker shows) and returns those tokens so the agent can drop a real image,
 * embed an existing page, or place a global block instead of inventing an id:
 *
 *   find_reference "team" types="media"  →  media:42 — "Team photo" …
 *   find_reference "pricing" types="node" →  entity:node:15 — "Pricing" …
 *
 * Read-only: it finds, it never creates (humans upload images / author blocks in
 * the studio). Returns plain text the agent reads — not a widget.
 */
#[FunctionCall(
  id: 'aincient_pages:find_reference',
  function_name: 'aincient_find_reference',
  name: 'Find reference',
  description: 'Find REAL existing content to reference in a page, returned as a text TOKEN you put straight into a prop. Use it instead of inventing an id. Token → where it goes: a `media:<id>` token goes in an image / avatar / cover prop (renders at the right size with alt text); an `entity:node:<id>` token goes in an embed section\'s `entity` prop (append @teaser or @full for a view mode); an `entity:user:<id>` token references a person (author byline, team card) in an embed section; a `block:<id>` token goes in a block section\'s `ref` prop. Pass "query" to filter by name/title, and optional "types" (CSV of: media, node, user, block) to narrow the search. ALWAYS prefer a media token to a raw image URL. If nothing fits, tell the user to upload an image or author the block in the page studio — do not invent a token.',
  context_definitions: [
    'query' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Search'),
      description: new TranslatableMarkup('Optional name/title filter, e.g. "team" or "pricing". Omit to list the most recent.'),
      required: FALSE,
    ),
    'types' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Types'),
      description: new TranslatableMarkup('Optional CSV of reference types to search: media, node, block. Omit to search all.'),
      required: FALSE,
    ),
  ],
)]
final class FindReference extends FunctionCallBase implements ExecutableFunctionCallInterface {

  /**
   * The unified reference catalog.
   */
  protected ReferenceCatalog $catalog;

  /**
   * The current user.
   */
  protected AccountInterface $currentUser;

  /**
   * The readable output (a token list, or a note that nothing matched).
   */
  protected string $result = '';

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->catalog = $container->get('aincient_pages.reference_catalog');
    $instance->currentUser = $container->get('current_user');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function execute(): void {
    if (!$this->currentUser->hasPermission('administer aincient pages')) {
      $this->result = 'Error: you do not have permission to browse content.';
      return;
    }

    $query = trim((string) ($this->getContextValue('query') ?? ''));
    $typesRaw = trim((string) ($this->getContextValue('types') ?? ''));
    $types = $typesRaw !== ''
      ? array_values(array_filter(array_map('trim', explode(',', $typesRaw))))
      : [];

    $rows = $this->catalog->search($types, $query !== '' ? $query : NULL, 25);

    if ($rows === []) {
      $scope = $typesRaw !== '' ? sprintf(' of type %s', $typesRaw) : '';
      $this->result = $query !== ''
        ? sprintf('No content%s matches "%s". Ask the user to add it in the page studio, then try again — do not invent a token.', $scope, $query)
        : sprintf('No content%s found. Ask the user to add it in the page studio — do not invent a token.', $scope);
      return;
    }

    $lines = [];
    foreach ($rows as $row) {
      $lines[] = $this->line($row);
    }
    $this->result = sprintf(
      "Found %d reference%s. Put the token in the matching prop "
      . "(media:→image/avatar/cover, entity:node:→embed `entity`, block:→block `ref`):\n%s",
      count($rows),
      count($rows) === 1 ? '' : 's',
      implode("\n", $lines),
    );
  }

  /**
   * One readable line for a descriptor: token — "label" (type[, bundle]) — gloss.
   */
  private function line(array $row): string {
    $tag = (string) $row['type'];
    $bundle = (string) ($row['meta']['bundle'] ?? '');
    if ($bundle !== '' && $bundle !== $tag) {
      $tag .= ', ' . $bundle;
    }
    $gloss = trim((string) ($row['description'] ?? ''));
    if (($row['status'] ?? NULL) === 'unpublished') {
      $gloss = $gloss !== '' ? $gloss . ' — unpublished' : 'unpublished';
    }
    return sprintf(
      '- %s — "%s" (%s)%s',
      $row['token'],
      $row['label'],
      $tag,
      $gloss !== '' ? ' — ' . $gloss : '',
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getReadableOutput(): string {
    return $this->result;
  }

}
