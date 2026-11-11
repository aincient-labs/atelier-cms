<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Path entity ids on Atelier routes must fit PostgreSQL `int4` (KI-1).
 *
 * Core's entity param converter hands any numeric string to the storage, and
 * PostgreSQL then throws "numeric value out of range": a 500, raised during
 * param conversion, so before the access check and for anonymous callers too.
 * A route requirement stops it earlier: an id that doesn't match the console
 * contract's own pattern (`EntityIdRef`, 1–999,999,999) matches no route, which
 * is an ordinary 404 before any query runs.
 */
final class EntityIdRouteSubscriber extends RouteSubscriberBase {

  /**
   * The contract's entity-id pattern (docs/api: EntityIdRef), without anchors.
   */
  public const PATTERN = '[1-9][0-9]{0,8}';

  /**
   * Digits-only requirements that still let an out-of-range id through.
   */
  private const LOOSE = ['\\d+', '[0-9]+'];

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    foreach ($collection->all() as $name => $route) {
      if (str_starts_with($name, 'aincient')) {
        self::guard($route);
      }
    }
  }

  /**
   * Adds the id requirement to each entity-typed path parameter of a route.
   */
  public static function guard(Route $route): void {
    $parameters = $route->getOption('parameters') ?? [];
    foreach ($parameters as $param => $definition) {
      $type = is_array($definition) ? (string) ($definition['type'] ?? '') : '';
      if (!str_starts_with($type, 'entity:') || str_contains($type, '{')) {
        continue;
      }
      if (!str_contains($route->getPath(), '{' . $param . '}')) {
        continue;
      }
      // A loose digits-only requirement is tightened; anything more specific a
      // route declared is its own business and stays.
      if ($route->hasRequirement($param) && !in_array($route->getRequirement($param), self::LOOSE, TRUE)) {
        continue;
      }
      $route->setRequirement($param, self::PATTERN);
    }
  }

}
