<?php

declare(strict_types=1);

namespace Drupal\aincient_export;

use Drupal\aincient_pages\CollectionInventory;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;

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
    // OPTIONAL (@?): null when aincient_pages is not installed, so this module
    // stays independently testable. In the distribution the two always ship
    // together and the collection JSON + archive paths are enumerated below.
    private readonly ?CollectionInventory $collectionInventory = NULL,
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

    // Collection listings (DECISIONS 0329): every DISTINCT index-mode collection
    // contributes two public routes — its content-addressed JSON index and its
    // flat archive page. Enumerated from the same inventory the routes resolve
    // against, so a hash the exporter writes is exactly a hash the route serves.
    if ($this->collectionInventory !== NULL) {
      foreach (array_keys($this->collectionInventory->indexCollections()) as $hash) {
        $paths[] = Url::fromRoute('aincient_pages.collection_data', ['file' => $hash . '.json'])->toString();
        $paths[] = Url::fromRoute('aincient_pages.collection_archive', ['hash' => $hash])->toString();
      }
    }

    // The configured front page also has a path of its own (e.g. "/node");
    // exporting it twice is harmless but pollutes the sitemap.
    $front = $this->configFactory->get('system.site')->get('page.front');
    $paths = array_filter($paths, fn (string $path) => $path !== $front);

    return array_values(array_unique($paths));
  }

}
