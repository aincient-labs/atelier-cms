<?php

declare(strict_types=1);

namespace Drupal\aincient_pages\Routing;

use Drupal\aincient_pages\Controller\PageSpikeController;
use Drupal\Core\Routing\RouteSubscriberBase;
use Drupal\Core\Routing\RoutingEvents;
use Symfony\Component\Routing\RouteCollection;

/**
 * Serves aincient_page nodes chrome-less at their own canonical URL.
 *
 * Agent-composed pages render full-bleed (no themed regions). Rather than a
 * separate /page route — which pathauto can't alias and search engines wouldn't
 * treat as canonical — we override the node canonical controller so the composed
 * page lives at /node/{nid} (and its pathauto alias), where metatag/pathauto/
 * sitemap all apply natively. Non-aincient_page nodes are delegated back to the
 * stock NodeViewController unchanged.
 */
final class PageRouteSubscriber extends RouteSubscriberBase {

  protected function alterRoutes(RouteCollection $collection): void {
    if ($route = $collection->get('entity.node.canonical')) {
      $route->setDefault('_controller', PageSpikeController::class . '::nodeCanonical');
    }
  }

  /**
   * {@inheritdoc}
   *
   * Run late (negative priority) on the ALTER pass so our controller override
   * is the last writer of entity.node.canonical's _controller — anything that
   * alters it at the default priority (0) runs before us and won't clobber it.
   * Stays above core's final validators (e.g. SpecialAttributesRouteSubscriber
   * at -1024) so we don't interfere with route-collection sanity checks.
   */
  public static function getSubscribedEvents(): array {
    return [
      RoutingEvents::ALTER => ['onAlterRoutes', -300],
    ];
  }

}
