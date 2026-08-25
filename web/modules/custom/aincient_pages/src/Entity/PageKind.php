<?php

declare(strict_types=1);

namespace Drupal\aincient_pages\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * The page_kind config entity (plans/byo-components.md W2).
 *
 * SITE-OWNED config: fenced from config:import via
 * aincient_pages_config_ignore_settings_alter() so a console tune survives the
 * appliance's boot-time full-set sync. The two defaults ('landing', 'blog')
 * ship from config/install — module-owned seeds, never config/sync files.
 *
 * NEVER FATAL (ratified decision #4): a kind naming an unknown component,
 * variant or opener degrades at catalog-compile time (warn, skip the entry,
 * fall back to landing semantics) — it must not 500 the studio or blank the
 * agent manifest. The compiler owns that floor; this entity is dumb storage.
 */
#[ConfigEntityType(
  id: 'page_kind',
  label: new TranslatableMarkup('Page kind'),
  label_collection: new TranslatableMarkup('Page kinds'),
  label_singular: new TranslatableMarkup('page kind'),
  label_plural: new TranslatableMarkup('page kinds'),
  config_prefix: 'page_kind',
  entity_keys: [
    'id' => 'id',
    'label' => 'label',
    'uuid' => 'uuid',
  ],
  admin_permission: 'administer aincient pages',
  config_export: [
    'id',
    'label',
    'hint',
    'mode',
    'components',
    'opener',
    'limits',
    'collection_source',
  ],
)]
final class PageKind extends ConfigEntityBase implements PageKindInterface {

  /**
   * The kind id — also the stored field_page_type value. Permanent.
   */
  protected string $id;

  /**
   * The human label ("Landing page").
   */
  protected string $label;

  /**
   * One-line picker/agent hint.
   */
  protected string $hint = '';

  /**
   * composition | recipe.
   */
  protected string $mode = self::MODE_COMPOSITION;

  /**
   * Component narrowing map; empty = the whole discovered palette.
   *
   * @var array<string, array{variants?: list<string>, tones?: list<string>}>
   */
  protected array $components = [];

  /**
   * The required first section, '' = unconstrained.
   */
  protected string $opener = '';

  /**
   * Per-component placement limits.
   *
   * @var array<string, int>
   */
  protected array $limits = [];

  /**
   * Whether a collection may list pages of this kind.
   */
  protected bool $collection_source = FALSE;

  /**
   * {@inheritdoc}
   */
  public function mode(): string {
    return $this->mode === self::MODE_RECIPE ? self::MODE_RECIPE : self::MODE_COMPOSITION;
  }

  /**
   * {@inheritdoc}
   */
  public function isComposition(): bool {
    return $this->mode() === self::MODE_COMPOSITION;
  }

  /**
   * {@inheritdoc}
   */
  public function hint(): string {
    return $this->hint;
  }

  /**
   * {@inheritdoc}
   */
  public function components(): array {
    return $this->components;
  }

  /**
   * {@inheritdoc}
   */
  public function opener(): ?string {
    return $this->opener === '' ? NULL : $this->opener;
  }

  /**
   * {@inheritdoc}
   */
  public function limits(): array {
    return $this->limits;
  }

  /**
   * {@inheritdoc}
   */
  public function isCollectionSource(): bool {
    return $this->collection_source;
  }

}
