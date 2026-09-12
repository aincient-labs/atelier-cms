<?php

declare(strict_types=1);

namespace Drupal\aincient_export;

use Drupal\aincient_pages\CollectionInventory;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
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
    private readonly LanguageManagerInterface $languageManager,
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
    // Every enabled language, not just the default one: a snapshot that holds
    // only the default language silently falls through to live Drupal for every
    // translated page, and the link check then flags one "does not exist" per
    // language-switcher link per page (pages x languages) until the report is
    // useless. A page is enumerated in a language only when that translation
    // actually exists, so the export grows by translations, not by languages.
    $languages = $this->languageManager->getLanguages();

    $paths = [];
    foreach ($languages as $language) {
      $paths[] = Url::fromRoute('<front>')->setOption('language', $language)->toString();
    }

    $node_storage = $this->entityTypeManager->getStorage('node');
    $nids = $node_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 1)
      ->sort('nid')
      ->execute();
    foreach ($node_storage->loadMultiple($nids) as $node) {
      foreach ($this->translatedPaths($node, $languages) as $path) {
        $paths[] = $path;
      }
    }

    if ($this->entityTypeManager->hasDefinition('taxonomy_term')) {
      $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');
      $tids = $term_storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('status', 1)
        ->sort('tid')
        ->execute();
      foreach ($term_storage->loadMultiple($tids) as $term) {
        foreach ($this->translatedPaths($term, $languages) as $path) {
          $paths[] = $path;
        }
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
    // exporting it twice is harmless but pollutes the sitemap. Compared RAW, as
    // before: `page.front` holds an internal path (`/node/1`) while the paths
    // above are aliases, so resolving it here would additionally drop the front
    // node's own alias page — which has always been exported and may be linked.
    $front = $this->configFactory->get('system.site')->get('page.front');
    $paths = array_filter($paths, fn (string $path) => $path !== $front);

    return array_values(array_unique($paths));
  }

  /**
   * One path per language the entity is actually translated into.
   *
   * @return string[]
   *   Alias-form paths, each carrying its language's URL prefix.
   */
  private function translatedPaths(ContentEntityInterface $entity, array $languages): array {
    $paths = [];
    foreach ($languages as $langcode => $language) {
      if (!$entity->hasTranslation($langcode)) {
        continue;
      }
      // Resolve the URL from the TRANSLATION: an alias is per-language, so
      // asking the default translation would emit the default alias under a
      // foreign prefix (`/de/rooms-suites` for a page aliased `/de/zimmer`).
      $paths[] = $entity->getTranslation($langcode)->toUrl()
        ->setOption('language', $language)
        ->toString();
    }
    return $paths;
  }

}
