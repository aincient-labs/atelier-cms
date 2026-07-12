<?php

declare(strict_types=1);

namespace Drupal\aincient_audit\EventSubscriber;

use Drupal\aincient_audit\PolicyRevisioner;
use Drupal\Core\Config\ConfigCrudEvent;
use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\Database\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Snapshots a policy into history on every save — the single robust seam.
 *
 * Reacting to ConfigEvents::SAVE catches every policy write (the studio Publish
 * endpoint, the bare admin form, the BrandRepository-style repository, config
 * import, a restore, and the install/seed) without instrumenting each writer.
 * The change summary is computed by diffing the pre-save (original) config
 * against the saved values, so it needs no cooperation from the writer.
 * Recording history is best-effort: a failure must never block a policy save.
 *
 * Mirrors {@see \Drupal\aincient_pages\EventSubscriber\BrandConfigSubscriber},
 * scoped to the many `aincient_audit.policy.<id>` configs rather than one brand.
 */
final class PolicyConfigSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly PolicyRevisioner $revisioner,
    private readonly Connection $database,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [ConfigEvents::SAVE => 'onSave'];
  }

  public function onSave(ConfigCrudEvent $event): void {
    $config = $event->getConfig();
    $name = $config->getName();
    if (!str_starts_with($name, PolicyRevisioner::CONFIG_PREFIX)) {
      return;
    }
    $policyId = substr($name, strlen(PolicyRevisioner::CONFIG_PREFIX));
    if ($policyId === '') {
      return;
    }

    // The history table may not exist yet (e.g. before update hooks run, or in
    // unrelated kernel tests that don't install this entity) — skip cleanly.
    if (!$this->database->schema()->tableExists(PolicyRevisioner::ENTITY_TYPE)) {
      return;
    }

    $new = $config->get();
    $original = $config->getOriginal('', FALSE) ?: [];
    $summary = $this->summarise($original, $new);
    if ($summary === '') {
      // Nothing meaningful changed (e.g. a re-save of identical data).
      return;
    }

    try {
      $this->revisioner->snapshot($policyId, $new, $summary);
    }
    catch (\Throwable $e) {
      // History is best-effort; never break the policy save itself.
      $this->logger->warning('Could not record policy revision: @msg', ['@msg' => $e->getMessage()]);
    }
  }

  /**
   * A short human description of what changed between two policy snapshots.
   */
  private function summarise(array $old, array $new): string {
    // The very first save (no prior config) is the baseline.
    if (!$old) {
      return 'created';
    }

    $parts = [];

    if (($old['status'] ?? NULL) !== ($new['status'] ?? NULL)) {
      $parts[] = !empty($new['status']) ? 'enabled' : 'disabled';
    }
    if (($old['label'] ?? NULL) !== ($new['label'] ?? NULL)) {
      $parts[] = 'renamed';
    }
    if (($old['weight'] ?? NULL) !== ($new['weight'] ?? NULL)) {
      $parts[] = 'reordered';
    }
    if (($old['enforcement'] ?? NULL) !== ($new['enforcement'] ?? NULL)) {
      $parts[] = 'enforcement → ' . (string) ($new['enforcement'] ?? '');
    }
    if (($old['kind'] ?? NULL) !== ($new['kind'] ?? NULL)) {
      $parts[] = 'mode → ' . (string) ($new['kind'] ?? '');
    }
    if (($old['selector'] ?? []) !== ($new['selector'] ?? [])) {
      $parts[] = 'selector';
    }

    $newParams = $new['parameters'] ?? [];
    $changedParams = $this->changedKeys($old['parameters'] ?? [], $newParams);
    if ($changedParams) {
      $parts[] = count($changedParams) === 1
        ? $changedParams[0] . ' → ' . (is_scalar($newParams[$changedParams[0]] ?? NULL) ? (string) $newParams[$changedParams[0]] : '…')
        : count($changedParams) . ' parameters';
    }

    // No itemised change (e.g. a re-save of identical data) — treat as a no-op.
    return $parts ? implode(', ', $parts) : '';
  }

  /**
   * Keys whose values differ (added, removed, or changed) between two maps.
   *
   * @return string[]
   *   The changed keys.
   */
  private function changedKeys(array $old, array $new): array {
    $keys = array_unique(array_merge(array_keys($old), array_keys($new)));
    $changed = [];
    foreach ($keys as $key) {
      if (($old[$key] ?? NULL) !== ($new[$key] ?? NULL)) {
        $changed[] = (string) $key;
      }
    }
    return $changed;
  }

}
