<?php

declare(strict_types=1);

namespace Drupal\aincient_chat\Access;

use Drupal\aincient_chat\Studio\StudioSwitch;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Symfony\Component\Routing\Route;

/**
 * `_aincient_studio_enabled: '<studio id>'` — a switched-off studio's routes 403.
 *
 * A studio is switched off by config, never by uninstalling its module
 * (DECISIONS 0430), so its routes still exist and its permission is still
 * held; this is what makes "off" mean off at the HTTP layer rather than only
 * in the console UI. The requirement is added for every studio route by
 * {@see \Drupal\aincient_chat\Routing\StudioRouteSubscriber}, and Drupal ANDs
 * requirements: the permission AND the switch must both pass.
 *
 * The result depends on `aincient_chat.settings` (where the switch lives), so
 * flipping a studio invalidates any cached access result without a router
 * rebuild — the requirement itself is static.
 */
final class StudioEnabledAccessCheck implements AccessInterface {

  public function __construct(
    private readonly StudioSwitch $switch,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Allowed while the route's studio is switched on, forbidden while it is off.
   */
  public function access(Route $route): AccessResultInterface {
    $id = (string) $route->getRequirement('_aincient_studio_enabled');
    $result = $this->switch->isEnabled($id)
      ? AccessResult::allowed()
      : AccessResult::forbidden(sprintf('The %s studio is switched off.', $id));
    return $result->addCacheableDependency($this->configFactory->get(StudioSwitch::CONFIG));
  }

}
