<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Kernel;

use Drupal\aincient_pages\Controller\PageSpikeController;
use Drupal\aincient_pages\Routing\PageRouteSubscriber;
use Drupal\Core\Routing\RoutingEvents;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Guards the entity.node.canonical controller override.
 *
 * The override only holds if our subscriber is the last writer of the route's
 * _controller. These tests pin both halves of that contract: the late ALTER
 * priority, and the rebuilt route actually resolving to our controller.
 *
 * @group aincient
 */
#[RunTestsInSeparateProcesses]
final class PageRouteSubscriberTest extends KernelTestBase {

  protected static $modules = ['system', 'user', 'field', 'text', 'node', 'workflows', 'content_moderation', 'aincient_pages'];

  public function testSubscribesToAlterAfterDefaultPriority(): void {
    // Default RouteSubscriberBase priority is 0; we must run later (negative)
    // so anything altering the controller at 0 can't clobber us afterwards.
    $events = PageRouteSubscriber::getSubscribedEvents();
    $this->assertArrayHasKey(RoutingEvents::ALTER, $events);
    [$method, $priority] = $events[RoutingEvents::ALTER];
    $this->assertSame('onAlterRoutes', $method);
    $this->assertLessThan(0, $priority, 'Override must run after the default (0) priority to win.');
  }

  public function testCanonicalNodeRouteUsesOurController(): void {
    $this->container->get('router.builder')->rebuild();
    $route = $this->container->get('router.route_provider')->getRouteByName('entity.node.canonical');
    $this->assertSame(
      PageSpikeController::class . '::nodeCanonical',
      $route->getDefault('_controller'),
    );
  }

}
