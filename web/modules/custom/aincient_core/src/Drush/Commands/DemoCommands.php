<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Drush\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\aincient_core\Demo\DemoContentTracker;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush commands for studio demo content ("clear examples").
 *
 * The headless twin of the in-studio button (not built yet — no studio module
 * ships demo content today): both call {@see DemoContentTracker::clear()}, so
 * what the shell removes and what the button removes cannot differ
 * (plans/studio-modules.md "Demo content", DECISIONS 0430).
 */
final class DemoCommands extends DrushCommands {

  public function __construct(
    private readonly DemoContentTracker $tracker,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('aincient_core.demo_content'));
  }

  /**
   * List the entities tracked as studio demo content.
   */
  #[CLI\Command(name: 'atelier:demo-list')]
  #[CLI\Argument(name: 'studio', description: 'Limit to one studio id.')]
  #[CLI\FieldLabels(labels: [
    'studio' => 'Studio',
    'entity_type' => 'Type',
    'entity_id' => 'ID',
  ])]
  #[CLI\DefaultTableFields(fields: ['studio', 'entity_type', 'entity_id'])]
  public function list(?string $studio = NULL): RowsOfFields {
    return new RowsOfFields($this->tracker->tracked($studio));
  }

  /**
   * Delete studio demo content that is still present.
   *
   * Edited demo items are deleted too: the tag means "came from us".
   */
  #[CLI\Command(name: 'atelier:demo-clear')]
  #[CLI\Argument(name: 'studio', description: 'Limit to one studio id; omit for every studio.')]
  #[CLI\Option(name: 'dry-run', description: 'List what would be deleted without deleting it.')]
  #[CLI\Usage(name: 'drush atelier:demo-clear media --dry-run', description: 'Show the Media studio examples that would go.')]
  public function clear(?string $studio = NULL, array $options = ['dry-run' => FALSE]): int {
    $label = $studio ?? 'all studios';
    if (!empty($options['dry-run'])) {
      $rows = $this->tracker->tracked($studio);
      foreach ($rows as $row) {
        $this->io()->writeln(sprintf('[%s] %s:%s', $row['studio'], $row['entity_type'], $row['entity_id']));
      }
      $this->io()->writeln(sprintf('Dry run: %d tracked entities would be deleted (%s).', count($rows), $label));
      return self::EXIT_SUCCESS;
    }
    $count = $this->tracker->clear($studio);
    $this->io()->success(sprintf('Deleted %d demo entities (%s).', $count, $label));
    return self::EXIT_SUCCESS;
  }

}
