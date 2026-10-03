<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_chat\Unit;

use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\Yaml\Yaml;

/**
 * The kit gallery route stays behind the dev floor AND the console permission.
 *
 * `/atelier/dev/kit` is a development surface (plans/studio-modules.md, "the
 * kit"). Dropping `_atelier_dev` would put it on every production appliance;
 * dropping the permission would open it to anonymous visitors on a dev stack.
 * Both are one deleted line in a YAML file, so the YAML is pinned here.
 *
 * @group aincient
 */
#[CoversNothing]
final class KitGalleryRouteTest extends UnitTestCase {

  public function testGalleryRouteIsDevOnlyAndPermissioned(): void {
    $routes = Yaml::parseFile(dirname(__DIR__, 3) . '/aincient_chat.routing.yml');
    $route = $routes['aincient_chat.dev_kit'] ?? NULL;
    $this->assertIsArray($route, 'The kit gallery route exists.');
    $this->assertSame('/atelier/dev/kit', $route['path']);
    $this->assertSame('TRUE', $route['requirements']['_atelier_dev'] ?? NULL, 'Gated on the dev floor.');
    $this->assertSame('use aincient operator console', $route['requirements']['_permission'] ?? NULL);
    $this->assertSame(['GET'], $route['methods'] ?? NULL);
  }

}
