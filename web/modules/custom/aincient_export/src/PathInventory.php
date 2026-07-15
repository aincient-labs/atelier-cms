<?php

declare(strict_types=1);

namespace Drupal\aincient_export;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Enumerates the public paths of the site.
 *
 * The inventory is derived from our own entities instead of crawling: the
 * component grammar is closed, so the set of public pages is knowable. Every
 * new public route type must be added here — the post-export link check is
 * the guard for the ones we forget.
 */
final class PathInventory {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Collects the page paths to export, alias form, deduplicated.
   *
   * @return string[]
   *   Paths starting with "/". The front page is always "/".
   */
  public function collect(): array {
    $paths = ['/'];

    $node_storage = $this->entityTypeManager->getStorage('node');
    $nids = $node_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 1)
      ->sort('nid')
      ->execute();
    foreach ($node_storage->loadMultiple($nids) as $node) {
      $paths[] = $node->toUrl()->toString();
    }

    if ($this->entityTypeManager->hasDefinition('taxonomy_term')) {
      $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');
      $tids = $term_storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('status', 1)
        ->sort('tid')
        ->execute();
      foreach ($term_storage->loadMultiple($tids) as $term) {
        $paths[] = $term->toUrl()->toString();
      }
    }

    // The configured front page also has a path of its own (e.g. "/node");
    // exporting it twice is harmless but pollutes the sitemap.
    $front = $this->configFactory->get('system.site')->get('page.front');
    $paths = array_filter($paths, fn (string $path) => $path !== $front);

    return array_values(array_unique($paths));
  }

}
