<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Kernel;

use Drupal\aincient_pages\Catalog\KindCheck;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the kind-check analysis (plans/byo-components.md §6.3): the dry run
 * behind `drush atelier:kind-check` that reports which stored pages a catalog
 * narrowing would orphan. Exercises the SERVICE, not drush — the command is a
 * thin renderer over these rows.
 *
 * @group aincient
 */
#[RunTestsInSeparateProcesses]
final class KindCheckTest extends KernelTestBase {

  protected static $modules = ['system', 'user', 'field', 'text', 'node', 'workflows', 'content_moderation', 'aincient_core', 'aincient_pages'];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    // No installConfig: with the node module enabled, aincient_pages'
    // config/optional would create the aincient_page type and collide with
    // the hand-built one below. No page_kind entities is fine — the catalog's
    // BUILTIN_KINDS floor gives landing its composition semantics.

    // The aincient_page content type + the structure field ship as
    // distribution config (config/sync), not module config — build by hand.
    NodeType::create(['type' => 'aincient_page', 'name' => 'AIncient page'])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_page_structure',
      'entity_type' => 'node',
      'type' => 'string_long',
      'translatable' => TRUE,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_page_structure',
      'entity_type' => 'node',
      'bundle' => 'aincient_page',
      'label' => 'Page structure',
      'translatable' => TRUE,
    ])->save();
  }

  private function check(): KindCheck {
    return $this->container->get('aincient_pages.kind_check');
  }

  /**
   * A stored landing page with a hero (variant split) + newsletter stack —
   * the content a narrowing could orphan.
   */
  private function createPage(): int {
    $node = Node::create([
      'type' => 'aincient_page',
      'title' => 'Home',
      'status' => 1,
      'field_page_structure' => json_encode([
        'type' => 'landing',
        'slots' => [
          ['id' => 's1', 'component' => 'hero', 'variant' => 'split'],
          ['id' => 's2', 'component' => 'newsletter'],
        ],
      ]),
    ]);
    $node->save();
    return (int) $node->id();
  }

  /**
   * Re-compile the catalog after a constraint change (what a cache-tag
   * invalidation does on the live site).
   */
  private function resetCatalog(): void {
    $this->container->get('aincient_pages.catalog')->reset();
  }

  /**
   * An untouched page against the full catalog: zero impacts — the SAFE
   * verdict a CI gate passes on.
   */
  public function testUntouchedPageYieldsZeroImpacts(): void {
    $this->createPage();
    $report = $this->check()->run();
    $this->assertSame(1, $report['pages']);
    $this->assertSame(0, $report['impacts']);
    $this->assertSame(0, $report['warnings']);
    $this->assertSame([], $report['rows']);
  }

  /**
   * Removing a component the page uses is a breaking impact, named per
   * nid + slot so a human can decide what happens to the content.
   */
  public function testComponentRemovalIsReported(): void {
    $nid = $this->createPage();
    $this->config('aincient_pages.site_constraint')->set('components', ['newsletter'])->save();
    $this->resetCatalog();

    $report = $this->check()->run();
    $this->assertSame(1, $report['impacts']);
    $this->assertCount(1, $report['rows']);
    $row = $report['rows'][0];
    $this->assertSame((string) $nid, $row['nid']);
    $this->assertSame('s2', $row['slot']);
    $this->assertSame('newsletter', $row['component']);
    $this->assertSame('component removed', $row['impact']);
    $this->assertSame('landing', $row['kind']);
    $this->assertSame('published', $row['status']);
  }

  /**
   * Removing a variant the page stores is a breaking impact: hero declares
   * centered|split; the constraint removes split; the page uses split.
   */
  public function testVariantRemovalIsReported(): void {
    $nid = $this->createPage();
    $this->config('aincient_pages.site_constraint')->set('variants', ['hero' => ['split']])->save();
    $this->resetCatalog();

    $report = $this->check()->run();
    $this->assertSame(1, $report['impacts']);
    $this->assertCount(1, $report['rows']);
    $row = $report['rows'][0];
    $this->assertSame((string) $nid, $row['nid']);
    $this->assertSame('s1', $row['slot']);
    $this->assertSame('hero', $row['component']);
    $this->assertSame('variant removed', $row['impact']);
    $this->assertStringContainsString('variant "split"', $row['detail']);
  }

}
