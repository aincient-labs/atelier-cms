<?php

declare(strict_types=1);

namespace Drupal\aincient_audit\Entity;

use Drupal\aincient_audit\PolicyListBuilder;
use Drupal\aincient_audit\Form\PolicyForm;
use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\Entity\EntityDeleteForm;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * A stored page-health policy (Phase 3, DECISIONS 0134).
 *
 * The `id` IS the policy id — the report check `key`, the finding `policyId`, and
 * (via {@see \Drupal\aincient_audit\Check\CheckRegistry}) the label fallback. The
 * shipped defaults (`seo`, `links`) live in `config/install`; their `parameters`
 * equal the checks' historical constants so the report stays byte-identical until
 * a user tunes one.
 */
#[ConfigEntityType(
  id: 'aincient_policy',
  label: new TranslatableMarkup('Policy'),
  label_collection: new TranslatableMarkup('Policies'),
  label_singular: new TranslatableMarkup('policy'),
  label_plural: new TranslatableMarkup('policies'),
  config_prefix: 'policy',
  entity_keys: [
    'id' => 'id',
    'label' => 'label',
    'uuid' => 'uuid',
    'status' => 'status',
    'weight' => 'weight',
  ],
  handlers: [
    'list_builder' => PolicyListBuilder::class,
    'route_provider' => [
      'html' => AdminHtmlRouteProvider::class,
    ],
    'form' => [
      'edit' => PolicyForm::class,
      'delete' => EntityDeleteForm::class,
    ],
  ],
  links: [
    'collection' => '/admin/config/aincient/policies',
    'edit-form' => '/admin/config/aincient/policies/{aincient_policy}',
    'delete-form' => '/admin/config/aincient/policies/{aincient_policy}/delete',
  ],
  admin_permission: 'administer aincient pages',
  config_export: [
    'id',
    'label',
    'weight',
    'workflow',
    'kind',
    'enforcement',
    'selector',
    'parameters',
  ],
)]
final class Policy extends ConfigEntityBase implements PolicyInterface {

  /**
   * The policy id (= report key = finding policyId).
   */
  protected string $id;

  /**
   * The report group label.
   */
  protected string $label;

  /**
   * The report weight — lower sorts first.
   */
  protected int $weight = 0;

  /**
   * The flowdrop_workflow id run to produce findings.
   */
  protected string $workflow = '';

  /**
   * The execution mode.
   */
  protected string $kind = self::KIND_DETERMINISTIC;

  /**
   * The enforcement mode (advisory honored in v1; enforcing stored-but-inert).
   */
  protected string $enforcement = self::ENFORCEMENT_ADVISORY;

  /**
   * The PHP selector prefilter data. Empty list per axis = "any".
   *
   * @var array{bundles: list<string>, moderation_states: list<string>, langcodes: list<string>}
   */
  protected array $selector = [
    'bundles' => [],
    'moderation_states' => [],
    'langcodes' => [],
  ];

  /**
   * The tunable knobs passed into the workflow.
   *
   * @var array<string, mixed>
   */
  protected array $parameters = [];

  /**
   * {@inheritdoc}
   *
   * A policy depends on the workflow it runs — so config import orders the
   * workflow first, and removing the workflow flags the dangling policy.
   */
  public function calculateDependencies(): static {
    parent::calculateDependencies();
    if ($this->workflow !== '') {
      $workflow = $this->entityTypeManager()
        ->getStorage('flowdrop_workflow')
        ->load($this->workflow);
      if ($workflow !== NULL) {
        $this->addDependency('config', $workflow->getConfigDependencyName());
      }
    }
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getWorkflow(): string {
    return $this->workflow;
  }

  /**
   * {@inheritdoc}
   */
  public function getKind(): string {
    return $this->kind;
  }

  /**
   * {@inheritdoc}
   */
  public function getEnforcement(): string {
    return $this->enforcement;
  }

  /**
   * {@inheritdoc}
   */
  public function getWeight(): int {
    return $this->weight;
  }

  /**
   * {@inheritdoc}
   */
  public function getParameters(): array {
    return $this->parameters;
  }

  /**
   * {@inheritdoc}
   */
  public function getSelector(): array {
    return $this->selector + [
      'bundles' => [],
      'moderation_states' => [],
      'langcodes' => [],
    ];
  }

}
