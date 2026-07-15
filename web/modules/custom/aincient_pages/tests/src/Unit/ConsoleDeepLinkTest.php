<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Unit;

use Drupal\aincient_pages\ConsoleDeepLink;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the entity → console-studio deep-link map.
 *
 * The pure {@see ConsoleDeepLink::route}/{@see ConsoleDeepLink::studioPermission}
 * contract that keeps the "Edit in studio" operation and the reference field's
 * edit_url pointing at the right studio (and off the raw Drupal entity form).
 *
 * @group aincient
 * @coversDefaultClass \Drupal\aincient_pages\ConsoleDeepLink
 */
final class ConsoleDeepLinkTest extends UnitTestCase {

  private function entity(string $type, string $bundle, int $id): EntityInterface {
    $entity = $this->createMock(EntityInterface::class);
    $entity->method('getEntityTypeId')->willReturn($type);
    $entity->method('bundle')->willReturn($bundle);
    $entity->method('id')->willReturn((string) $id);
    return $entity;
  }

  /**
   * @covers ::route
   */
  public function testPageNodeRoute(): void {
    $this->assertSame(
      ['aincient_chat.console_doc', ['studio' => 'content', 'doc_type' => 'node', 'nid' => '5']],
      ConsoleDeepLink::route($this->entity('node', 'aincient_page', 5)),
    );
  }

  /**
   * @covers ::route
   */
  public function testBlockMediaRoute(): void {
    $this->assertSame(
      ['aincient_chat.console_doc', ['studio' => 'content', 'doc_type' => 'block', 'nid' => '9']],
      ConsoleDeepLink::route($this->entity('media', 'block', 9)),
    );
  }

  /**
   * @covers ::route
   */
  public function testImageMediaRoute(): void {
    $this->assertSame(
      ['aincient_chat.console_media', ['bundle' => 'image', 'nid' => '7']],
      ConsoleDeepLink::route($this->entity('media', 'image', 7)),
    );
  }

  /**
   * @covers ::route
   */
  public function testUnownedEntitiesHaveNoRoute(): void {
    // A plain article node and a non-studio media bundle are not studio-owned.
    $this->assertNull(ConsoleDeepLink::route($this->entity('node', 'article', 3)));
    $this->assertNull(ConsoleDeepLink::route($this->entity('media', 'audio', 4)));
    $this->assertNull(ConsoleDeepLink::route($this->entity('user', 'user', 1)));
  }

  /**
   * @covers ::studioPermission
   */
  public function testStudioPermission(): void {
    $this->assertSame('use aincient studio content', ConsoleDeepLink::studioPermission($this->entity('node', 'aincient_page', 5)));
    $this->assertSame('use aincient studio content', ConsoleDeepLink::studioPermission($this->entity('media', 'block', 9)));
    $this->assertSame('use aincient studio media', ConsoleDeepLink::studioPermission($this->entity('media', 'image', 7)));
    $this->assertNull(ConsoleDeepLink::studioPermission($this->entity('node', 'article', 3)));
  }

}
