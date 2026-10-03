<?php

declare(strict_types=1);

namespace Drupal\aincient_chat\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Hangs the studio on/off check off every studio route, without editing one.
 *
 * Every studio route already carries the studio's DERIVED permission
 * (`use aincient studio <id>`, DECISIONS 0425) as its `_permission`
 * requirement — that is the one word a studio route has in common. So a route
 * that gates on a studio's permission is, by definition, that studio's route,
 * and this subscriber adds `_aincient_studio_enabled: <id>` to it
 * ({@see \Drupal\aincient_chat\Access\StudioEnabledAccessCheck}). No
 * routing.yml learns about the switch, and a new studio module's routes are
 * covered the moment they use its permission (DECISIONS 0430, "a disabled
 * studio 403s its routes").
 *
 * Only an EXACT single-permission requirement is matched. A `+` (OR) or `,`
 * (AND) combination is a route that is not one studio's alone — it may be
 * reachable through another studio's permission, and switching one studio off
 * must not take the other's route with it — so those are left alone.
 *
 * Drupal ANDs requirements, so the permission check is untouched: the result
 * is permission AND enabled.
 */
final class StudioRouteSubscriber extends RouteSubscriberBase {

  private const PATTERN = '/^use aincient studio ([a-z0-9_]+)$/';

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    foreach ($collection as $route) {
      if ($route->hasRequirement('_aincient_studio_enabled')) {
        continue;
      }
      $permission = $route->getRequirement('_permission');
      if (is_string($permission) && preg_match(self::PATTERN, $permission, $m)) {
        $route->setRequirement('_aincient_studio_enabled', $m[1]);
      }
    }
  }

}
