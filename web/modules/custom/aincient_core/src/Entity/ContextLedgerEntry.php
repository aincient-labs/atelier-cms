<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\user\EntityOwnerInterface;
use Drupal\user\EntityOwnerTrait;

/**
 * One row per image an operator attaches to a chat turn as agent context.
 *
 * This is the ledger of the private, forensic context store (DECISIONS 0347,
 * 0350, 0359): attachments are NOT media-library content and are never publicly
 * reachable. Each entry is a permanent record of an ingest — who attached what,
 * to which thread/turn, the ingest identity (sha256 of the ORIGINAL bytes) and
 * the identity of the one normalized derivative we keep. The original bytes are
 * NOT stored by default (they carry the polyglot/EXIF risk); only the derivative
 * File is required. The client filename lives here and ONLY here — never in the
 * stored URI, because a filename in a URL or a log is itself a disclosure.
 *
 * Non-revisionable, no UI routes, no views_data: a minimal storage substrate the
 * later chat-wiring / vision / taint steps build on.
 */
#[ContentEntityType(
  id: 'aincient_context_entry',
  label: new TranslatableMarkup('Context ledger entry'),
  label_collection: new TranslatableMarkup('Context ledger'),
  handlers: [
    'access' => 'Drupal\Core\Entity\EntityAccessControlHandler',
  ],
  base_table: 'aincient_context_entry',
  admin_permission: 'administer aincient context store',
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'owner' => 'uid',
  ],
)]
final class ContextLedgerEntry extends ContentEntityBase implements EntityOwnerInterface {

  use EntityOwnerTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::ownerBaseFieldDefinitions($entity_type);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'));

    $fields['thread_id'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Thread'))
      ->setDescription(new TranslatableMarkup('The FlowDrop console thread id (thr_…) this attachment belongs to.'))
      ->setSetting('max_length', 64);

    $fields['turn'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Turn'))
      ->setDescription(new TranslatableMarkup('The user message sequence_number, or null when unknown at write time.'))
      ->setRequired(FALSE);

    $fields['kind'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Kind'))
      ->setDescription(new TranslatableMarkup('The attachment kind.'))
      ->setSetting('max_length', 32)
      ->setDefaultValue('image');

    $fields['sha256'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Original SHA-256'))
      ->setDescription(new TranslatableMarkup('Hex SHA-256 of the ORIGINAL uploaded bytes — the ingest identity.'))
      ->setSetting('max_length', 64);

    $fields['derivative_sha256'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Derivative SHA-256'))
      ->setDescription(new TranslatableMarkup('Hex SHA-256 of the stored normalized derivative bytes (later promotion integrity check).'))
      ->setSetting('max_length', 64);

    $fields['size'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Original size'))
      ->setDescription(new TranslatableMarkup('Original byte size of the uploaded file.'));

    $fields['declared_mime'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Declared MIME'))
      ->setDescription(new TranslatableMarkup('The client-declared MIME type.'))
      ->setSetting('max_length', 128);

    $fields['sniffed_mime'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Sniffed MIME'))
      ->setDescription(new TranslatableMarkup('The validator-canonical MIME type sniffed from the bytes.'))
      ->setSetting('max_length', 128);

    $fields['filename'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Filename'))
      ->setDescription(new TranslatableMarkup('The client display name. The ONLY place the client filename is stored — never in the URI.'))
      ->setSetting('max_length', 255);

    $fields['width'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Width'))
      ->setDescription(new TranslatableMarkup('Derivative width in pixels.'));

    $fields['height'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Height'))
      ->setDescription(new TranslatableMarkup('Derivative height in pixels.'));

    // Plain extracted text / a vision description — never filtered HTML, so
    // string_long (no text format), which also keeps this entity free of a
    // filter-module dependency. token/pathauto walks every entity's fields on
    // any node save, so a `format` property here would demand `filter` in every
    // test that transitively enables aincient_core (the 0359 lesson).
    $fields['description'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Description'))
      ->setDescription(new TranslatableMarkup('Extracted text / vision description. Filled by a later step; null for now.'))
      ->setRequired(FALSE);

    $fields['file'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Derivative file'))
      ->setDescription(new TranslatableMarkup('The stored normalized derivative File entity.'))
      ->setSetting('target_type', 'file')
      ->setRequired(TRUE);

    $fields['original_file'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Original file'))
      ->setDescription(new TranslatableMarkup('The stored original File entity — null unless retention keeps it.'))
      ->setSetting('target_type', 'file')
      ->setRequired(FALSE);

    return $fields;
  }

}
