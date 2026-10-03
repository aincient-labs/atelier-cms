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

  /**
   * {@inheritdoc}
   */
  public function description(): string {
    return (string) ($this->getPluginDefinition()['description'] ?? '');
  }

  /**
   * {@inheritdoc}
   */
  public function help(): string {
    return (string) ($this->getPluginDefinition()['help'] ?? '');
  }

  /**
   * {@inheritdoc}
   */
  public function defaultEnabled(): bool {
    return (bool) ($this->getPluginDefinition()['default_enabled'] ?? TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function uiEntry(): ?string {
    return $this->uiString('entry');
  }

  /**
   * {@inheritdoc}
   */
  public function uiScript(): ?string {
    return $this->uiString('script');
  }

  /**
   * {@inheritdoc}
   */
  public function uiStyle(): ?string {
    return $this->uiString('style');
  }

  /**
   * {@inheritdoc}
   */
  public function uiName(): string {
    return $this->uiString('name') ?? $this->label();
  }

  /**
   * {@inheritdoc}
   */
  public function uiIcon(): ?string {
    return $this->uiString('icon');
  }

  /**
   * One non-empty string from the manifest's `ui` map, or NULL.
   */
  private function uiString(string $key): ?string {
    $ui = $this->getPluginDefinition()['ui'] ?? NULL;
    $value = is_array($ui) ? ($ui[$key] ?? NULL) : NULL;
    return is_string($value) && $value !== '' ? $value : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function flows(): array {
    return array_values(array_map('strval', (array) ($this->getPluginDefinition()['flows'] ?? [])));
  }

  /**
   * {@inheritdoc}
   *
   * The manifest lists bare slugs (`create_form`); the plugin id a capability
   * is dispatched by is `<provider>:<slug>`, so the provider is prepended here,
   * once. A slug that already carries a provider is left alone.
   */
  public function capabilities(): array {
    $definition = $this->getPluginDefinition();
    $provider = (string) ($definition['provider'] ?? '');
    $ids = [];
    foreach ((array) ($definition['capabilities'] ?? []) as $slug) {
      $slug = (string) $slug;
      $ids[] = str_contains($slug, ':') || $provider === '' ? $slug : $provider . ':' . $slug;
    }
    return $ids;
  }

  /**
   * {@inheritdoc}
   */
  public function demoPath(): ?string {
    $demo = $this->getPluginDefinition()['demo'] ?? NULL;
    return is_string($demo) && $demo !== '' ? $demo : NULL;
  }

}
