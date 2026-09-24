<?php

declare(strict_types=1);

namespace Drupal\aincient_chat\Studio;

use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\Session\AccountInterface;

/**
 * The base every studio plugin extends — the whole implementation.
 *
 * A studio plugin class is expected to be EMPTY apart from its attribute: all
 * of a studio's server-side facts are declared there, and everything derivable
 * from them is derived here, once. A subclass that overrides
 * {@see self::permission} would be minting a permission the permissions page
 * never lists, so don't.
 */
abstract class StudioBase extends PluginBase implements StudioInterface {

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return (string) $this->getPluginId();
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return (string) ($this->getPluginDefinition()['label'] ?? $this->id());
  }

  /**
   * {@inheritdoc}
   */
  public function weight(): int {
    return (int) ($this->getPluginDefinition()['weight'] ?? 0);
  }

  /**
   * {@inheritdoc}
   */
  public function isOpen(): bool {
    return (bool) ($this->getPluginDefinition()['open'] ?? FALSE);
  }

  /**
   * {@inheritdoc}
   */
  public function permission(): ?string {
    return $this->isOpen() ? NULL : 'use aincient studio ' . $this->id();
  }

  /**
   * {@inheritdoc}
   */
  public function accessibleBy(AccountInterface $account): bool {
    $permission = $this->permission();
    return $permission === NULL || $account->hasPermission($permission);
  }

}
