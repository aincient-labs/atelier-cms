<?php

declare(strict_types=1);

namespace Drupal\aincient_pages;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Where an image's `media:<id>` token is still placed.
 *
 * Discarding a generated image unpublishes it (DECISIONS 0512), and an
 * unpublished image no longer renders for visitors. So a discard is refused
 * while the image is still placed somewhere a visitor sees it: the latest
 * revision of a page or a block (schema + per-language copy, a page's teaser
 * image), the site identity (logo, favicon) or the header/footer chrome.
 */
final class MediaUsage {

  /**
   * Schema-carrying fields, per entity type => [bundle, fields].
   */
  private const FIELDS = [
    'node' => ['aincient_page', ['field_page_structure', 'field_page_content', 'field_teaser_image']],
    'media' => ['block', ['field_page_structure', 'field_page_content']],
  ];

  private const CONFIGS = [
    SiteIdentity::CONFIG => 'Site identity',
    ChromeRepository::CONFIG => 'Header and footer',
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * The places a media item is used, as short labels (capped).
   *
   * @return list<string>
   *   E.g. ["Page: Home", "Block: Footer CTA", "Site identity"].
   */
  public function placements(string|int $mediaId, int $limit = 5): array {
    $token = 'media:' . $mediaId;
    // `media:7` must not match `media:70`.
    $pattern = '/' . preg_quote($token, '/') . '(?!\d)/';
    $out = [];

    foreach (self::FIELDS as $entityType => [$bundle, $fields]) {
      $storage = $this->entityTypeManager->getStorage($entityType);
      $definitions = $this->entityTypeManager->getDefinition($entityType);
      $query = $storage->getQuery()
        ->accessCheck(FALSE)
        ->latestRevision()
        ->condition($definitions->getKey('bundle'), $bundle);
      $or = $query->orConditionGroup();
      $present = FALSE;
      foreach ($fields as $field) {
        if ($this->hasField($entityType, $bundle, $field)) {
          $or->condition($field, $token, 'CONTAINS');
          $present = TRUE;
        }
      }
      if (!$present) {
        continue;
      }
      $ids = $query->condition($or)->range(0, $limit * 4)->execute();
      foreach ($ids as $revisionId => $id) {
        $entity = $storage->loadRevision($revisionId) ?? $storage->load($id);
        if ($entity === NULL || !$this->mentions($entity, $fields, $pattern)) {
          continue;
        }
        $out[] = ($entityType === 'node' ? 'Page: ' : 'Block: ') . $entity->label();
        if (count($out) >= $limit) {
          return $out;
        }
      }
    }

    foreach (self::CONFIGS as $name => $label) {
      $raw = $this->configFactory->get($name)->getRawData();
      if ($raw !== [] && preg_match($pattern, (string) json_encode($raw))) {
        $out[] = $label;
      }
    }

    return array_slice($out, 0, $limit);
  }

  /**
   * Whether an entity's fields really hold the token (not a longer id).
   */
  private function mentions(object $entity, array $fields, string $pattern): bool {
    foreach ($entity->getTranslationLanguages() as $langcode => $language) {
      $translation = $entity->getTranslation($langcode);
      foreach ($fields as $field) {
        if ($translation->hasField($field) && preg_match($pattern, (string) $translation->get($field)->value)) {
          return TRUE;
        }
      }
    }
    return FALSE;
  }

  private function hasField(string $entityType, string $bundle, string $field): bool {
    $definitions = \Drupal::service('entity_field.manager')->getFieldDefinitions($entityType, $bundle);
    return isset($definitions[$field]);
  }

}
