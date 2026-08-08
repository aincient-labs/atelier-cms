<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Unit;

use Drupal\aincient_pages\CollectionInventory;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * The spec → canonical form → content hash contract (DECISIONS 0329).
 *
 * The hash is the address of a collection's JSON file, shared by the route, the
 * export inventory and the component — so its stability under authoring noise,
 * and its INstability across genuine query changes, is load-bearing.
 *
 * @group aincient
 */
final class CollectionInventoryTest extends TestCase {

  private function inventory(): CollectionInventory {
    // normalize()/specHash() never touch the entity-type manager; a bare double
    // is enough for the pure query-identity logic under test.
    return new CollectionInventory($this->createMock(EntityTypeManagerInterface::class));
  }

  public function testNormalizeClampsUnknownSourceAndSortToDefaults(): void {
    $norm = $this->inventory()->normalize(['source' => 'nope', 'sort' => 'sideways']);
    $this->assertSame(CollectionInventory::DEFAULT_SOURCE, $norm['source']);
    $this->assertSame(CollectionInventory::DEFAULT_SORT, $norm['sort']);
    $this->assertSame([], $norm['filter']);
  }

  public function testNormalizeKeepsValidValues(): void {
    $norm = $this->inventory()->normalize(['source' => 'blog', 'sort' => 'oldest']);
    $this->assertSame('blog', $norm['source']);
    $this->assertSame('oldest', $norm['sort']);
  }

  public function testHashIsStableAcrossFilterKeyOrder(): void {
    $inventory = $this->inventory();
    $a = $inventory->specHash(['source' => 'blog', 'filter' => ['b' => 1, 'a' => 2]]);
    $b = $inventory->specHash(['source' => 'blog', 'filter' => ['a' => 2, 'b' => 1]]);
    $this->assertSame($a, $b, 'Filter key order must not change the hash.');
  }

  public function testHashIgnoresPageSize(): void {
    $inventory = $this->inventory();
    // limit / per_page bound how many tiles render, not WHICH posts match — so a
    // strip and the matching index resolve to the SAME JSON file.
    $strip = $inventory->specHash(['source' => 'blog', 'sort' => 'newest', 'limit' => 3]);
    $index = $inventory->specHash(['source' => 'blog', 'sort' => 'newest', 'per_page' => 12]);
    $this->assertSame($strip, $index);
  }

  public function testHashDiffersOnSort(): void {
    $inventory = $this->inventory();
    $this->assertNotSame(
      $inventory->specHash(['source' => 'blog', 'sort' => 'newest']),
      $inventory->specHash(['source' => 'blog', 'sort' => 'oldest']),
    );
  }

  public function testHashIsAShortHex(): void {
    $hash = $this->inventory()->specHash(['source' => 'blog']);
    $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $hash);
  }

}
