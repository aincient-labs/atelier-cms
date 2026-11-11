<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Every Atelier write route requires Drupal's `X-CSRF-Token` header (0511).
 *
 * The console's API is cookie-authenticated, and until 0511 the only thing
 * between a hostile page and a cross-site POST (multipart uploads included)
 * was the session cookie's `SameSite=Lax`, which does not cover same-site
 * origins such as sibling subdomains or other `localhost` apps. Core's
 * `_csrf_request_header_token` check adds the second lock: an unsafe method
 * from a cookie session must echo the session's token, which another origin
 * cannot read. Basic-auth and anonymous requests carry no session, so core
 * lets them through unchanged.
 *
 * Applied here rather than per route so a new write route cannot forget it.
 * A route is covered when it belongs to an Atelier module (`aincient*`) and
 * declares at least one unsafe method. Form API routes are skipped (their
 * POST is a browser form submission, protected by the form token), as is a
 * route that already uses the link-style `_csrf_token`.
 */
final class CsrfHeaderRouteSubscriber extends RouteSubscriberBase {

  public const REQUIREMENT = '_csrf_request_header_token';

  private const UNSAFE = ['POST', 'PUT', 'PATCH', 'DELETE'];

  private const FORM_DEFAULTS = ['_form', '_entity_form', '_wizard'];

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    foreach ($collection->all() as $name => $route) {
      if (self::covers($name, $route)) {
        $route->setRequirement(self::REQUIREMENT, 'TRUE');
      }
    }
  }

  /**
   * Whether a route gets the header requirement.
   */
  public static function covers(string $name, Route $route): bool {
    if (!str_starts_with($name, 'aincient')) {
      return FALSE;
    }
    if ($route->hasRequirement(self::REQUIREMENT) || $route->hasRequirement('_csrf_token')) {
      return FALSE;
    }
    foreach (self::FORM_DEFAULTS as $default) {
      if ($route->hasDefault($default)) {
        return FALSE;
      }
    }
    return array_intersect(self::UNSAFE, $route->getMethods()) !== [];
  }

}
