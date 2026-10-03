<?php

declare(strict_types=1);

namespace Drupal\aincient_audit\Check;

/**
 * A by-id lookup over the tagged {@see CheckInterface} checks.
 *
 * Exists to bridge a Drupal DI limitation: a plugin's static `create()` gets the
 * container but CANNOT consume a `!tagged_iterator` argument (that's a
 * compiler-pass feature wired at service-definition time, not reachable via
 * `$container->get()`). The FlowDrop `policy_check` node processor
 * ({@see \Drupal\aincient_flows\Plugin\FlowDropNodeProcessor\PolicyCheck}) needs
 * exactly one check by id, so it injects THIS service and calls `get($id)`.
 *
 * The registry itself IS wired with `!tagged_iterator`, so it collects the same
 * checks — in the same priority order — that {@see \Drupal\aincient_audit\AuditEngine}
 * and {@see \Drupal\aincient_audit\PolicyEvaluator} see (seo priority 20 before
 * links priority 10). Read-only; it holds no state beyond the id index.
 */
final class CheckRegistry {

  /**
   * The checks keyed by id, in priority (report) order.
   *
   * @var array<string, \Drupal\aincient_audit\Check\CheckInterface>
   */
  private array $byId = [];

  /**
   * @param iterable<\Drupal\aincient_audit\Check\CheckInterface> $checks
   *   The tagged `aincient_audit.check` services, in priority order.
   */
  public function __construct(iterable $checks) {
    foreach ($checks as $check) {
      $this->byId[$check->id()] = $check;
    }
  }

  /**
   * The check with this id, or NULL when none is registered.
   */
  public function get(string $id): ?CheckInterface {
    return $this->byId[$id] ?? NULL;
  }

  /**
   * All checks keyed by id, in priority (report) order.
   *
   * @return array<string, \Drupal\aincient_audit\Check\CheckInterface>
   */
  public function all(): array {
    return $this->byId;
  }

}
