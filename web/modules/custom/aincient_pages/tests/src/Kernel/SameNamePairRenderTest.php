<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Kernel;

use Drupal\aincient_pages\Controller\PageSpikeController;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * A same-name pair renders by identity, never by short name (DECISIONS 0539).
 *
 * atelier_test_pair_pack ships a `testimonials` beside the built-in. The
 * palette offers the pack's; a page that stores the built-in keeps its full id
 * through the read and renders the built-in, not the pack's.
 *
 * @group aincient
 */
#[RunTestsInSeparateProcesses]
final class SameNamePairRenderTest extends KernelTestBase {

  protected static $modules = [
    'system',
    'user',
    'link',
    'menu_link_content',
    'workflows', 'content_moderation', 'aincient_core', 'atelier', 'aincient_pages',
    'atelier_test_pair_pack',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['workflows', 'content_moderation', 'aincient_pages']);
  }

  private function renderSection(string $component, array $props): string {
    return (string) PageSpikeController::create($this->container)->renderFragment([
      ['id' => 's1', 'component' => $component, 'props' => $props],
    ], 'T')->getContent();
  }

  /**
   * The short name renders the offered one (the pack's); the built-in's full
   * id renders the built-in.
   */
  public function testEachOfThePairRendersAsItself(): void {
    $discovered = $this->container->get('aincient_pages.catalog')->discovered();
    $this->assertSame('atelier_test_pair_pack:testimonials', $discovered->pluginId('testimonials'));
    $this->assertSame(['atelier:testimonials'], $discovered->hiddenIds());
    $this->assertTrue($discovered->isPlaceable('atelier:testimonials'), 'A page that holds the hidden one keeps it (0455).');
    $this->assertSame('atelier:testimonials', $discovered->pluginId('atelier:testimonials'));

    $pack = $this->renderSection('testimonials', ['heading' => 'Pack', 'quote' => 'It just works.', 'author' => 'Ada']);
    $this->assertStringContainsString('pair-testimonial__quote', $pack);

    $builtin = $this->renderSection('atelier:testimonials', ['heading' => 'Built-in', 'quotes' => [['quote' => 'Solid.', 'author' => 'Grace']]]);
    $this->assertStringNotContainsString('pair-testimonial', $builtin);
    $this->assertStringContainsString('Solid.', $builtin);
  }

  /**
   * The read keeps the hidden one's full id and shortens everything else.
   */
  public function testTheReadKeepsOnlyTheHiddenFullId(): void {
    $hidden = $this->container->get('aincient_pages.catalog')->discovered()->hiddenIds();
    $structure = ['slots' => [
      ['id' => 'a', 'component' => 'atelier:hero'],
      ['id' => 'b', 'component' => 'atelier:testimonials'],
      ['id' => 'c', 'component' => 'atelier_test_pair_pack:testimonials'],
    ]];
    $read = \Aincient\PageModel\ComponentId::shortenStructure($structure, $hidden);
    $this->assertSame(['hero', 'atelier:testimonials', 'testimonials'], array_column($read['slots'], 'component'));
  }

}
