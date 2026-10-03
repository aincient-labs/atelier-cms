<?php

declare(strict_types=1);

namespace Drupal\aincient_audit\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\user\EntityOwnerInterface;
use Drupal\user\EntityOwnerTrait;

/**
 * A point-in-time snapshot of a single policy's config (Phase 4).
 *
 * One row per policy-config save, written by the PolicyConfigSubscriber — which
 * reacts to ConfigEvents::SAVE and so catches EVERY write path (the studio
 * Publish endpoint, the bare admin form, config import, a restore, and the
 * install/seed). Stores the full policy config as JSON so any past state can be
 * restored verbatim, plus who changed it and an auto-computed summary of what
 * changed. This is a policy's revision history — the Brand-studio parallel
 * ({@see \Drupal\aincient_pages\Entity\BrandRevision}), keyed by `policy_id`
 * because — unlike the single brand config — there are many policy entities.
 */
#[ContentEntityType(
  id: 'aincient_policy_revision',
  label: new TranslatableMarkup('Policy revision'),
  label_collection: new TranslatableMarkup('Policy history'),
  handlers: [
    'access' => 'Drupal\Core\Entity\EntityAccessControlHandler',
  ],
  base_table: 'aincient_policy_revision',
  admin_permission: 'administer aincient pages',
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'owner' => 'uid',
  ],
)]
final class PolicyRevision extends ContentEntityBase implements EntityOwnerInterface {

  use EntityOwnerTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::ownerBaseFieldDefinitions($entity_type);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'));

    $fields['policy_id'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Policy'))
      ->setDescription(new TranslatableMarkup('The id of the policy this revision snapshots.'))
      ->setSetting('max_length', 64);

    $fields['summary'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Summary'))
      ->setDescription(new TranslatableMarkup('Auto-computed description of what changed in this revision.'))
      ->setSetting('max_length', 512);

    $fields['data'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Policy snapshot'))
      ->setDescription(new TranslatableMarkup('The full policy config (JSON) as it stood after this save.'));

    return $fields;
  }

}
