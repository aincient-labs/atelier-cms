<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Kernel;

use Drupal\aincient_pages\Catalog\AdmissionGate;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The built-in components' declared `examples:` (DECISIONS 0455, P1).
 *
 * Every placeable built-in ships story-shaped examples the Components studio
 * renders as its preview: at least one, every variant shown at least once,
 * props the page grammar keeps as-is (an example is what the agent or Content
 * would place), images as shipped `placeholder:<name>` tokens, and a render
 * through the real page pipeline that does not fail. Built-ins stay OUT of the
 * agent's few-shot prompt (the 0402 budget).
 *
 * @group aincient
 */
#[RunTestsInSeparateProcesses]
final class BuiltinExamplesTest extends KernelTestBase {

  protected static $modules = [
    'system', 'user', 'file', 'image', 'media', 'link', 'menu_link_content', 'node', 'field', 'text',
    'workflows', 'content_moderation', 'aincient_core', 'aincient_pages',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installConfig(['system', 'workflows', 'content_moderation', 'aincient_pages']);
    // The collection example lists real pages: it queries aincient_page nodes
    // by the derived field_page_type (distribution config — built by hand).
    if (!NodeType::load('aincient_page')) {
      NodeType::create(['type' => 'aincient_page', 'name' => 'Page'])->save();
    }
    FieldStorageConfig::create(['field_name' => 'field_page_type', 'entity_type' => 'node', 'type' => 'string'])->save();
    FieldConfig::create(['field_name' => 'field_page_type', 'entity_type' => 'node', 'bundle' => 'aincient_page', 'label' => 'Type'])->save();
  }

  /**
   * The built-in placeables that must declare examples: every discovered
   * placeable from aincient_pages (the virtual `block` has no SDC).
   *
   * @return list<string>
   */
  private function builtins(): array {
    $discovered = $this->container->get('aincient_pages.catalog')->discovered();
    return array_values(array_filter(
      $discovered->placeableNames(),
      static fn(string $n): bool => ($discovered->placeable($n)['provider'] ?? '') === 'aincient_pages',
    ));
  }

  public function testEveryBuiltinPlaceableHasValidExamples(): void {
    $catalog = $this->container->get('aincient_pages.catalog');
    $discovered = $catalog->discovered();
    $renderer = $this->container->get('aincient_pages.example_renderer');
    $store = $this->container->get('aincient_pages.store');
    $placeholders = $this->container->get('extension.list.module')->getPath('aincient_pages') . '/images/placeholders';

    $builtins = $this->builtins();
    $this->assertCount(20, $builtins, 'The 20 placeable built-ins: ' . implode(', ', $builtins));

    foreach ($builtins as $name) {
      $examples = $renderer->examples($name);
      $this->assertNotEmpty($examples, "$name declares no examples.");

      // Every variant is shown by at least one example.
      $shown = [];
      foreach ($examples as $i => $example) {
        $label = "$name example #$i";
        $this->assertIsString($example['name'] ?? NULL, "$label needs a name.");
        $props = $example['props'] ?? NULL;
        $this->assertIsArray($props, "$label needs props.");
        if (isset($props['variant'])) {
          $shown[$props['variant']] = TRUE;
        }

        // Image values are shipped placeholder tokens that exist on disk.
        array_walk_recursive($props, function ($value, $key) use ($label, $placeholders): void {
          if (is_string($value) && str_starts_with($value, 'placeholder:')) {
            $file = substr($value, strlen('placeholder:'));
            $this->assertFileExists("$placeholders/$file.svg", "$label: unknown placeholder \"$value\".");
          }
          if (in_array($key, ['image', 'avatar', 'cover'], TRUE)) {
            $this->assertMatchesRegularExpression('/^placeholder:/', (string) $value, "$label: image \"$key\" must be a placeholder: token.");
          }
        });

        // The page grammar keeps the example as written: validate() of a page
        // holding it returns the same props (no prop dropped or clamped).
        $clean = $store->validate(['type' => 'landing', 'title' => 'x', 'sections' => [['component' => $name, 'props' => $props]]]);
        $this->assertSame($name, $clean['sections'][0]['component'] ?? NULL, "$label was dropped by validate().");
        $this->assertEquals($this->sorted($props), $this->sorted($clean['sections'][0]['props']), "$label is not grammar-clean (validate() changed it).");
      }
      foreach ($discovered->variantsFor($name) ?? [] as $variant) {
        $this->assertArrayHasKey($variant, $shown, "$name: no example shows variant \"$variant\".");
      }

      // It renders through the real page pipeline, every example.
      foreach (array_keys($examples) as $i) {
        $html = (string) $renderer->render([['component' => $name, 'example' => $i]])->getContent();
        $this->assertStringContainsString('data-ain-sec="' . $name . '"', $html, "$name example #$i rendered nothing.");
        $this->assertStringNotContainsString('placeholder:', $html, "$name example #$i left a placeholder token unresolved.");
      }
    }
  }

  /**
   * Built-ins never ride the few-shot prompt (0402 budget): the agent manifest
   * carries no `e.g.` line for any of them.
   */
  public function testBuiltinsStayOutOfTheFewShotPrompt(): void {
    $manifest = $this->container->get('aincient_pages.catalog')->for('landing')->manifest();
    foreach ($this->builtins() as $name) {
      $this->assertStringNotContainsString('"component":"' . $name . '"', $manifest, "$name leaked into the few-shot prompt.");
    }
  }

  /**
   * A toneless banner is brand-toned, so its eyebrow and subheading take the
   * on-colour ink — not the muted grey meant for a light band (it rendered
   * unreadable, grey on cinnabar). The SDC schema's `default: brand` never
   * reaches Twig; the template resolves the default itself.
   */
  public function testToneLessBannerUsesOnColourInk(): void {
    $html = (string) $this->container->get('aincient_pages.example_renderer')->renderRaw('banner');
    $this->assertStringContainsString('bg-primary text-primary-foreground', $html);
    $this->assertStringNotContainsString('text-muted-foreground', $html);
  }

  /**
   * The admission gate is clean on the built-ins (examples shape + no
   * "no examples declared" warning).
   */
  public function testGateHasNoExampleWarningsForBuiltins(): void {
    $definitions = $this->container->get('plugin.manager.sdc')->getDefinitions();
    $ours = array_filter($definitions, static fn(array $d): bool => ($d['provider'] ?? '') === 'aincient_pages');
    foreach (AdmissionGate::check($ours, ['block']) as $id => $verdict) {
      $name = (string) ($ours[$id]['machineName'] ?? $id);
      if (!in_array($name, $this->builtins(), TRUE)) {
        continue;
      }
      $this->assertSame([], $verdict['errors'] ?? [], "$name: gate errors.");
      foreach ($verdict['warnings'] ?? [] as $warning) {
        $this->assertStringNotContainsString('examples', $warning, "$name: $warning");
      }
    }
  }

  private function sorted(array $a): array {
    ksort($a);
    foreach ($a as &$v) {
      if (is_array($v)) {
        $v = $this->sorted($v);
      }
    }
    return $a;
  }

}
