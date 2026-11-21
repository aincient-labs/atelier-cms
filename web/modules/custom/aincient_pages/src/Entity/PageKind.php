<?php

declare(strict_types=1);

namespace Drupal\aincient_pages\Entity;

use Aincient\PageModel\ComponentId;
use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\Entity\EntityStorageInterface;
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
    'fragment',
    'removed',
    'include_new',
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
   * TRUE for a composition that is not a page (the built-in `block` kind).
   */
  protected bool $fragment = FALSE;

  /**
   * The deny list: components this kind never offers, even when its allow
   * list is empty ("allowed automatically", DECISIONS 0455).
   *
   * @var string[]
   */
  protected array $removed = [];

  /**
   * Whether components the allow map does not name are offered (TRUE =
   * "allowed automatically", the map then only narrows variants/tones), or
   * withheld (FALSE = "off until I allow them"). NULL = the legacy rule: an
   * empty map offers everything, a non-empty one is an allow list.
   */
  protected ?bool $include_new = NULL;

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
    return ComponentId::shortenKind(['components' => $this->components])['components'];
  }

  /**
   * {@inheritdoc}
   */
  public function opener(): ?string {
    return $this->opener === '' ? NULL : ComponentId::short($this->opener);
  }

  /**
   * {@inheritdoc}
   */
  public function limits(): array {
    return ComponentId::shortenKind(['limits' => $this->limits])['limits'];
  }

  /**
   * {@inheritdoc}
   */
  public function includesNew(): bool {
    return $this->include_new ?? $this->components === [];
  }

  public function isFragment(): bool {
    return $this->fragment;
  }

  public function removed(): array {
    return array_values(array_map(ComponentId::short(...), array_filter($this->removed, 'is_string')));
  }

  /**
   * Store full component ids (DECISIONS 0536).
   *
   * The getters hand out short names (everything above storage speaks them
   * in A0); the stored values are `provider:name`. The Components studio
   * qualifies before it saves; anything still bare here came from config
   * written before 0.30.0 — a pack's config/install — and is qualified with a
   * warning: bare names are refused from Atelier 0.32.0.
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);
    $values = [
      'components' => $this->components,
      'limits' => $this->limits,
      'opener' => $this->opener,
      'removed' => $this->removed,
    ];
    $bare = ComponentId::bareNamesInKind($values);
    if ($bare === []) {
      return;
    }
    $providers = ComponentId::providers(\Drupal::service('aincient_pages.catalog')->discovered());
    $values = ComponentId::qualifyKind($values, $providers);
    $this->components = $values['components'];
    $this->limits = $values['limits'];
    $this->opener = $values['opener'];
    $this->removed = $values['removed'];
    \Drupal::logger('aincient_pages')->warning('Page kind @kind names components without their provider (@names). They were stored as @full. Write the full ids in the config that ships this kind — bare names are refused from Atelier 0.32.0.', [
      '@kind' => $this->id(),
      '@names' => implode(', ', $bare),
      '@full' => implode(', ', array_map(static fn(string $n): string => ComponentId::qualify($n, $providers), $bare)),
    ]);
  }

  public function isCollectionSource(): bool {
    return $this->collection_source;
  }

}
