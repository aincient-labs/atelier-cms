<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Kernel;

use Drupal\aincient_core\EventSubscriber\BodyEntityIdSubscriber;
use Drupal\aincient_core\Routing\EntityIdRouteSubscriber;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Core\ParamConverter\ParamNotConvertedException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

/**
 * Entity ids outside PostgreSQL int4 are refused before any query (KI-1).
 *
 * Found by the Schemathesis contract suite: `/atelier/page/99999999999/…` and a
 * body `node_id`/`base_vid` past int4 came back as a 500 (out-of-range error from
 * PostgreSQL), the path case even for anonymous callers.
 */
#[RunTestsInSeparateProcesses]
final class EntityIdGuardTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'text', 'node', 'workflows', 'content_moderation',
    'aincient_core', 'aincient_pages',
  ];

  /**
   * A path id must match the contract's pattern, or no route matches (404).
   */
  public function testPathIdsAreBoundedByTheRoute(): void {
    $route = $this->container->get('router.route_provider')->getRouteByName('aincient_pages.page_revisions');
    $this->assertSame(EntityIdRouteSubscriber::PATTERN, $route->getRequirement('node'));

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $router = $this->container->get('router.no_access_checks');
    // An in-range id reaches param conversion (node 12 doesn't exist, which is
    // the converter's 404, not "no route").
    try {
      $router->match('/atelier/page/12/revisions');
    }
    catch (ParamNotConvertedException) {
      $this->addToAssertionCount(1);
    }

    foreach (['99999999999', '0', '-1', '1e5', '01'] as $id) {
      try {
        $router->match("/atelier/page/$id/revisions");
        $this->fail("/atelier/page/$id/revisions should match no route");
      }
      catch (ResourceNotFoundException) {
        $this->addToAssertionCount(1);
      }
    }
  }

  /**
   * Body ids: absent/empty pass (controllers answer those), int4 passes, else 400.
   */
  #[DataProvider('bodyIds')]
  public function testBodyIdValidity(mixed $value, bool $valid): void {
    $this->assertSame($valid, BodyEntityIdSubscriber::valid($value));
  }

  /**
   * @return array<string, array{mixed, bool}>
   */
  public static function bodyIds(): array {
    return [
      'null' => [NULL, TRUE],
      'empty' => ['', TRUE],
      'int' => [42, TRUE],
      'string' => ['42', TRUE],
      'int4 max' => [2147483647, TRUE],
      'int4 max string' => ['2147483647', TRUE],
      'past int4' => [2147483648, FALSE],
      'past int4 string' => ['99999999999', FALSE],
      'zero' => [0, FALSE],
      'negative' => [-3, FALSE],
      'float' => [1.5, FALSE],
      'leading zero' => ['042', FALSE],
      'array' => [[1], FALSE],
    ];
  }

}
