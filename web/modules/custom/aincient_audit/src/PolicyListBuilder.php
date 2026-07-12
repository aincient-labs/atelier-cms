<?php

declare(strict_types=1);

namespace Drupal\aincient_audit;

use Drupal\aincient_audit\Entity\PolicyInterface;
use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * The bare dev-tuning list of policies (Phase 3).
 *
 * Not the studio — the Phase-4 Policy studio is the real authoring surface. This
 * is a plain Drupal entity list behind `administer aincient pages` so a policy
 * can be enabled/disabled/tuned before that lands.
 */
final class PolicyListBuilder extends ConfigEntityListBuilder {

  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    return [
      'label' => $this->t('Policy'),
      'status' => $this->t('Status'),
      'kind' => $this->t('Mode'),
      'enforcement' => $this->t('Enforcement'),
    ] + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    assert($entity instanceof PolicyInterface);
    return [
      'label' => $entity->label(),
      'status' => $entity->status() ? $this->t('Enabled') : $this->t('Disabled'),
      'kind' => $entity->getKind(),
      'enforcement' => $entity->getEnforcement(),
    ] + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function load(): array {
    // Report order: lower weight first (seo before links).
    $entities = parent::load();
    uasort($entities, static fn (PolicyInterface $a, PolicyInterface $b): int => $a->getWeight() <=> $b->getWeight());
    return $entities;
  }

}
