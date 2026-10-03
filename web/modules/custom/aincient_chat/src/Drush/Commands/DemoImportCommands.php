<?php

declare(strict_types=1);

namespace Drupal\aincient_chat\Drush\Commands;

use Drupal\aincient_chat\Studio\StudioDemoContent;
use Drupal\aincient_chat\Studio\StudioManager;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * `drush atelier:demo-import` — seed a studio's demo content by hand.
 *
 * The product seeds it once, on the studio's first enable
 * ({@see StudioDemoContent}); this is the operator's and the developer's way
 * to do it again — after `atelier:demo-clear` on a demo appliance, or while
 * authoring a studio's `content/demo/`. The listing and the clear live in
 * `aincient_core` (`atelier:demo-list`, `atelier:demo-clear`) because the
 * tracking table does; the import knows studios, so it lives here.
 */
final class DemoImportCommands extends DrushCommands {

  public function __construct(
    private readonly StudioManager $studios,
    private readonly StudioDemoContent $demo,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('plugin.manager.aincient.studios'),
      $container->get('aincient_chat.studio_demo_content'),
    );
  }

  /**
   * Import a studio's demo content (every studio that ships some when omitted).
   *
   * Without --force a studio already imported once is skipped, exactly as the
   * first-enable import would skip it.
   */
  #[CLI\Command(name: 'atelier:demo-import')]
  #[CLI\Argument(name: 'studio', description: 'A studio id; omit for every studio that ships demo content.')]
  #[CLI\Option(name: 'force', description: 'Import again even if this studio was imported before (the earlier examples stay unless cleared first).')]
  #[CLI\Usage(name: 'drush atelier:demo-clear forms && drush atelier:demo-import forms --force', description: 'Reset the Forms studio examples.')]
  public function import(?string $studio = NULL, array $options = ['force' => FALSE]): int {
    $ids = $studio === NULL
      ? array_keys(array_filter($this->studios->studios(), fn($s) => $s->demoPath() !== NULL))
      : [$studio];
    if ($studio !== NULL && $this->studios->get($studio) === NULL) {
      throw new \InvalidArgumentException(sprintf('Unknown studio "%s". Known: %s.', $studio, implode(', ', $this->studios->keys())));
    }
    if ($studio !== NULL && !$this->demo->ships($studio)) {
      $this->io()->warning(sprintf('The %s studio ships no demo content.', $studio));
      return self::EXIT_SUCCESS;
    }
    foreach ($ids as $id) {
      if (!$options['force'] && $this->demo->imported($id)) {
        $this->io()->writeln(sprintf('%s: already imported (use --force to import again).', $id));
        continue;
      }
      $count = $this->demo->import($id, (bool) $options['force']);
      $this->io()->writeln(sprintf('%s: %d entities imported.', $id, $count));
    }
    return self::EXIT_SUCCESS;
  }

}
