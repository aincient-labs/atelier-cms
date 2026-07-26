<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_core\Unit\Fixtures;

use Drupal\Core\State\StateInterface;

/**
 * An in-memory StateInterface for unit tests.
 *
 * A mock won't do here: the tests care that a value SURVIVES a set and is really
 * gone after a delete, which is state, not an expectation.
 */
final class MemoryState implements StateInterface {

  /**
   * The stored values.
   *
   * @var array<string, mixed>
   */
  private array $values = [];

  /**
   * {@inheritdoc}
   */
  public function get($key, $default = NULL) {
    return $this->values[$key] ?? $default;
  }

  /**
   * {@inheritdoc}
   */
  public function getMultiple(array $keys) {
    $out = [];
    foreach ($keys as $key) {
      $out[$key] = $this->values[$key] ?? NULL;
    }
    return $out;
  }

  /**
   * {@inheritdoc}
   */
  public function set($key, $value): void {
    $this->values[$key] = $value;
  }

  /**
   * {@inheritdoc}
   */
  public function setMultiple(array $data): void {
    foreach ($data as $key => $value) {
      $this->values[$key] = $value;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function delete($key): void {
    unset($this->values[$key]);
  }

  /**
   * {@inheritdoc}
   */
  public function deleteMultiple(array $keys): void {
    foreach ($keys as $key) {
      unset($this->values[$key]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getValuesSetDuringRequest(string $key): ?array {
    return isset($this->values[$key]) ? [$key => $this->values[$key]] : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function resetCache(): void {
    // Nothing is cached beyond the array itself.
  }

}
