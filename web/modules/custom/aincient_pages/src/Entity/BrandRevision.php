<?php

declare(strict_types=1);

namespace Drupal\aincient_pages\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\user\EntityOwnerInterface;
use Drupal\user\EntityOwnerTrait;

/**
 * A point-in-time snapshot of the site brand / design system.
 *
 * One row per brand-config save, written by the BrandConfigSubscriber — which
 * reacts to ConfigEvents::SAVE and so catches EVERY write path (the no-AI
 * BrandForm, the brand studio Publish endpoint, the BrandRepository, config import, and a
 * restore). Stores the full brand config as JSON so any past look can be
 * restored verbatim, plus who changed it and an auto-computed summary of what
 * changed. This is the brand's revision history.
 */
#[ContentEntityType(
  id: 'aincient_brand_revision',
  label: new TranslatableMarkup('Brand revision'),
  label_collection: new TranslatableMarkup('Brand history'),
  handlers: [
    'access' => 'Drupal\Core\Entity\EntityAccessControlHandler',
  ],
  base_table: 'aincient_brand_revision',
  admin_permission: 'administer aincient pages',
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'owner' => 'uid',
  ],
)]
final class BrandRevision extends ContentEntityBase implements EntityOwnerInterface {

  use EntityOwnerTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::ownerBaseFieldDefinitions($entity_type);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'));

    $fields['summary'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Summary'))
      ->setDescription(new TranslatableMarkup('Auto-computed description of what changed in this revision.'))
      ->setSetting('max_length', 512);

    $fields['data'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Brand snapshot'))
      ->setDescription(new TranslatableMarkup('The full brand config (JSON) as it stood after this save.'));

    return $fields;
  }

}
