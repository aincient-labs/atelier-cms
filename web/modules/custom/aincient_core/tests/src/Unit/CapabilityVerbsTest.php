<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_core\Unit;

use Drupal\aincient_core\CapabilitySet;
use Drupal\aincient_core\CapabilityVerbs;
use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Tests which verbs a ROOM raises — derived from the tools its agent wires.
 *
 * The defect: every room showed all three capability chips, so the General
 * studio advertised "Draw — needs an image provider" for a picture it has no
 * tool to make. The fix is a per-room answer, and the load-bearing claim is that
 * it is DERIVED (from the workflow's placed capability nodes) rather than typed
 * into a studio → verbs table that would drift the first time an agent gained a
 * tool.
 *
 * @covers \Drupal\aincient_core\CapabilityVerbs
 * @group aincient
 */
final class CapabilityVerbsTest extends UnitTestCase {

  private const PREFIX = 'flowdrop_node_type.flowdrop_node_type.aincient_flows_aincient_capability_';

  /**
   * A CapabilityVerbs over a scripted capability registry.
   */
  private function verbs(): CapabilityVerbs {
    $manager = $this->createMock(PluginManagerInterface::class);
    $manager->method('getDefinitions')->willReturn([
      // The two capabilities that spend more than words.
      'aincient_pages:generate_image' => ['verbs' => [CapabilitySet::DRAW]],
      'aincient_pages:generate_alt_text' => ['verbs' => [CapabilitySet::DESCRIBE]],
      // The overwhelming majority: no declaration at all.
      'aincient_pages:list_pages' => [],
      'aincient_brand:preview_brand' => ['verbs' => []],
    ]);
    return new CapabilityVerbs($manager);
  }

  /**
   * A room with no capability nodes still writes: that is what makes it a room.
   */
  public function testWriteIsUniversal(): void {
    $this->assertSame([CapabilitySet::WRITE], $this->verbs()->forDependencies([]));
    $this->assertSame(
      [CapabilitySet::WRITE],
      $this->verbs()->forDependencies([self::PREFIX . 'list_pages']),
      'A tool that spends nothing but words adds no verb.',
    );
  }

  /**
   * THE REGRESSION: the General studio's agent raises Write and nothing else.
   */
  public function testAnAgentWithoutImageToolsRaisesWriteAlone(): void {
    $found = $this->verbs()->forDependencies([
      self::PREFIX . 'list_pages',
      self::PREFIX . 'studio_tour',
      // Non-capability dependencies are ignored, not mistaken for slugs.
      'flowdrop_node_type.flowdrop_node_type.flowdrop_node_processor_toolbox',
      'field.field.node.aincient_page.field_page_content',
    ]);
    $this->assertSame([CapabilitySet::WRITE], $found);
  }

  /**
   * The Media agent wires both image tools, so its room raises all three.
   */
  public function testImageToolsRaiseTheirVerbs(): void {
    $found = $this->verbs()->forDependencies([
      self::PREFIX . 'generate_image',
      self::PREFIX . 'generate_alt_text',
      self::PREFIX . 'find_reference',
    ]);
    // In CapabilitySet display order, so a room's row reads the same way the
    // install-wide row does — not in dependency order.
    $this->assertSame(
      [CapabilitySet::WRITE, CapabilitySet::DESCRIBE, CapabilitySet::DRAW],
      $found,
    );
  }

  /**
   * One tool is enough, and a verb is never counted twice.
   */
  public function testOneToolRaisesJustItsVerb(): void {
    $this->assertSame(
      [CapabilitySet::WRITE, CapabilitySet::DESCRIBE],
      $this->verbs()->forDependencies([
        self::PREFIX . 'generate_alt_text',
        self::PREFIX . 'generate_alt_text',
      ]),
    );
  }

  /**
   * An unknown slug is ignored — a stale dependency never invents a promise.
   */
  public function testUnknownSlugsAreIgnored(): void {
    $this->assertSame(
      [CapabilitySet::WRITE],
      $this->verbs()->forDependencies([self::PREFIX . 'a_capability_we_removed']),
    );
  }

}
