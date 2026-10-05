<?php

declare(strict_types=1);

namespace Drupal\aincient_chat\Routing;

use Drupal\aincient_chat\Controller\OneTimeLoginController;
use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Opens core's one-time login route to signed-in users.
 *
 * Core gates `user.reset.login` on `_user_is_logged_in: FALSE`, so a manager
 * "Edit my site" link (`drush user:login`) opened in a browser that is already
 * signed in lands on a bare 403. The route now admits everyone and
 * {@see OneTimeLoginController} decides: anonymous → core's own login, same
 * user → straight on, another user → confirm the switch.
 */
final class OneTimeLoginRouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    $route = $collection->get('user.reset.login');
    if ($route === NULL) {
      return;
    }
    $requirements = $route->getRequirements();
    unset($requirements['_user_is_logged_in']);
    $requirements['_access'] = 'TRUE';
    $route->setRequirements($requirements);
    $route->setDefault('_controller', OneTimeLoginController::class . '::login');
  }

}
