<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Routing;

use Drupal\aincient_core\Controller\StudioLandingController;
use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Points the backend's front door at the curated Studio landing.
 *
 * - system.admin (/admin): core's SystemController::overview is a sitemap of
 *   rooms aincient_deny has locked (see StudioLandingController) — swap in
 *   the curated landing.
 * - entity.user.collection (/admin/people): the heavy core listing (VBO,
 *   filters, role tabs) is retired in favour of the simple aincient_users
 *   view at /admin/users — redirect, only when that view's route exists.
 */
final class AdminLandingRouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    if ($route = $collection->get('system.admin')) {
      $defaults = $route->getDefaults();
      $defaults['_controller'] = StudioLandingController::class . '::landing';
      $defaults['_title'] = 'Studio';
      $route->setDefaults($defaults);
    }

    // Redirect the retired listing — but never break /admin/people outright
    // if the aincient_users view is disabled or deleted.
    if ($collection->get('view.aincient_users.page_1') && ($route = $collection->get('entity.user.collection'))) {
      $defaults = $route->getDefaults();
      // _entity_list would win the controller-resolution race otherwise.
      unset($defaults['_entity_list']);
      $defaults['_controller'] = StudioLandingController::class . '::peopleRedirect';
      $route->setDefaults($defaults);
    }
  }

}
