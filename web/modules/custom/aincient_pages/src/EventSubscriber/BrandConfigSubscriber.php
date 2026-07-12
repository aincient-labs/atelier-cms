<?php

declare(strict_types=1);

namespace Drupal\aincient_pages\EventSubscriber;

use Drupal\aincient_pages\BrandFontVendor;
use Drupal\aincient_pages\BrandRepository;
use Drupal\aincient_pages\BrandRevisioner;
use Drupal\Core\Config\ConfigCrudEvent;
use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\Database\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Snapshots the brand into history on every save — the single robust seam.
 *
 * Reacting to ConfigEvents::SAVE catches every brand write (the no-AI
 * BrandForm, the brand studio Publish endpoint, the BrandRepository, config import, and a
 * restore) without instrumenting each writer. The change summary is computed by
 * diffing the pre-save (original) config against the saved values, so it needs
 * no cooperation from the writer. Recording history is best-effort: a failure
 * must never block a brand save.
 */
final class BrandConfigSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly BrandRevisioner $revisioner,
    private readonly Connection $database,
    private readonly LoggerInterface $logger,
    private readonly BrandFontVendor $fontVendor,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [ConfigEvents::SAVE => 'onSave'];
  }

  public function onSave(ConfigCrudEvent $event): void {
    $config = $event->getConfig();
    if ($config->getName() !== BrandRepository::CONFIG) {
      return;
    }

    // Self-host delivery: vendor the chosen families to our origin so the public
    // pages need no Google request. Best-effort + idempotent (a re-save of the
    // same fonts is a no-op); a fetch failure just leaves the render path on the
    // system-font fallback. This is the one outbound-HTTP step, and it lives on
    // the save path (an admin action), never on render.
    $new = $config->get();
    if (($new['font_delivery'] ?? BrandRepository::DELIVERY_GOOGLE) === BrandRepository::DELIVERY_SELFHOST) {
      $families = $new['font_families'] ?? [];
      if ($families) {
        $this->fontVendor->ensure($families);
      }
    }
    // The history table may not exist yet (e.g. before update hooks run, or in
    // unrelated kernel tests that don't install this entity) — skip cleanly.
    if (!$this->database->schema()->tableExists(BrandRevisioner::ENTITY_TYPE)) {
      return;
    }

    $original = $config->getOriginal('', FALSE) ?: [];
    $summary = $this->summarise($original, $new);
    if ($summary === '') {
      // Nothing meaningful changed (e.g. a re-save of identical data).
      return;
    }

    try {
      $this->revisioner->snapshot($new, $summary);
    }
    catch (\Throwable $e) {
      // History is best-effort; never break the brand save itself.
      $this->logger->warning('Could not record brand revision: @msg', ['@msg' => $e->getMessage()]);
    }
  }

  /**
   * A short human description of what changed between two brand snapshots.
   */
  private function summarise(array $old, array $new): string {
    // The very first save (no prior config) is the baseline — every key would
    // read as "changed", so label it cleanly instead of itemising everything.
    if (!$old) {
      return 'initial brand';
    }

    $parts = [];

    $newTokens = $new['tokens'] ?? [];
    $changedTokens = $this->changedKeys($old['tokens'] ?? [], $newTokens);
    if ($changedTokens) {
      $parts[] = count($changedTokens) === 1
        ? $changedTokens[0] . ' → ' . $newTokens[$changedTokens[0]]
        : count($changedTokens) . ' tokens';
    }

    if (($old['font_families'] ?? []) !== ($new['font_families'] ?? [])) {
      $parts[] = 'web fonts';
    }

    // Status (stage + lock) is persisted out-of-band from tokens but still lives
    // in this config, so a status-only change is a real, revisionable edit.
    if (($old['status'] ?? []) !== ($new['status'] ?? [])) {
      $status = $new['status'] ?? [];
      $stage = (string) ($status['stage'] ?? BrandRepository::STAGE_IDEATING);
      $parts[] = 'status → ' . $stage . (!empty($status['locked']) ? ' (locked)' : '');
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
