<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Unit;

use Drupal\aincient_pages\ComponentCatalog;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Yaml\Yaml;

/**
 * Lints the component & layout naming convention (see GRAMMAR.md).
 *
 * These tests are the TEETH of the convention: they fail the build if the two
 * rules the page agent depends on are ever violated as the library grows.
 *
 * @group aincient
 * @coversDefaultClass \Drupal\aincient_pages\ComponentCatalog
 */
#[RunTestsInSeparateProcesses]
final class ComponentCatalogTest extends UnitTestCase {

  /**
   * RULE 1: every emitted identifier is globally unique (one word, one concept).
   */
  public function testNamesAreUnique(): void {
    $names = ComponentCatalog::reservedNames();
    $this->assertSame(
      array_values(array_unique($names)),
      array_values($names),
      'Component/layout names must be globally unique across all tiers.',
    );
  }

  /**
   * RULE 1 (corollary): a non-layout name never reuses a reserved layout word —
   * `grid` belongs to the layout tier alone, so `feature-grid` is forbidden.
   */
  public function testNamesDoNotReuseLayoutWords(): void {
    $nonLayout = array_merge(
      ComponentCatalog::sectionNames(),
      ComponentCatalog::CHROME,
      ComponentCatalog::CONTENT,
    );
    foreach ($nonLayout as $name) {
      $segments = preg_split('/[-_]/', $name);
      foreach (ComponentCatalog::LAYOUT_RESERVED as $reserved) {
        $this->assertNotContains(
          $reserved,
          $segments,
          sprintf('"%s" reuses the reserved layout word "%s" — specialise the name instead.', $name, $reserved),
        );
      }
    }
  }

  /**
   * RULE 2: layout/props are a SHARED, locked vocabulary — every prop a
   * placeable (section OR layout container) uses must be declared in PROP_VOCAB
   * (no synonyms or abbreviations creep in).
   */
  public function testEveryPlaceablePropIsInTheLockedVocab(): void {
    $placeables = ComponentCatalog::SECTIONS + ComponentCatalog::LAYOUT;
    foreach ($placeables as $name => $def) {
      foreach (array_keys($def['props']) as $prop) {
        $this->assertArrayHasKey(
          $prop,
          ComponentCatalog::PROP_VOCAB,
          sprintf('Placeable "%s" uses prop "%s" which is not in the locked PROP_VOCAB.', $name, $prop),
        );
      }
    }
  }

  /**
   * Every placeable variant enum is declared in VARIANTS, and vice versa — the
   * map the validator clamps against must match the components that have a
   * `variant` prop, so an unknown/missing variant can never trip the SDC enum.
   */
  public function testVariantMapMatchesPlaceablesWithAVariantProp(): void {
    $placeables = ComponentCatalog::SECTIONS + ComponentCatalog::LAYOUT;
    foreach ($placeables as $name => $def) {
      $hasVariant = array_key_exists('variant', $def['props']);
      $this->assertSame(
        $hasVariant,
        isset(ComponentCatalog::VARIANTS[$name]),
        sprintf('"%s" variant prop and the VARIANTS clamp map must agree.', $name),
      );
    }
  }

  /**
   * The agent manifest lists every placeable (section + layout container) from
   * the single source of truth, so the prompt can never drift from the
   * validator's allow-list.
   */
  public function testManifestCoversEveryPlaceable(): void {
    $manifest = ComponentCatalog::manifest();
    foreach (ComponentCatalog::placeableNames() as $name) {
      $this->assertStringContainsString($name, $manifest);
    }
    // The rename is real: the dropped name must not survive in the prompt.
    $this->assertStringNotContainsString('feature-grid', $manifest);
  }

  /**
   * The page agent's STORED prompt carries the `{{ component_catalog() }}` Twig
   * call, never a copy of the menu — and rendering it (as the Prompt Template
   * node does through ComponentCatalogExtension) puts every placeable, with its
   * prop signature, in front of the model.
   *
   * This is the runtime half of the drift guard: testManifestCoversEveryPlaceable
   * proves the manifest is complete; this proves the shipped prompt actually
   * pulls it in. If anyone re-inlines the menu (dropping the Twig call) or the
   * menu stops listing a placeable, the build goes red.
   */
  public function testPageAgentPromptInjectsTheWholeCatalog(): void {
    $prompt = $this->pageAgentSystemPrompt();
    $this->assertStringContainsString(
      ComponentCatalog::MANIFEST_TOKEN,
      $prompt,
      'The page-agent prompt must carry the manifest token (not an inlined copy of the menu) so the catalogue can never drift.',
    );

    // Substitute what the Twig render produces for this exact call.
    $injected = str_replace(ComponentCatalog::MANIFEST_TOKEN, ComponentCatalog::manifest(), $prompt);
    $this->assertStringNotContainsString(ComponentCatalog::MANIFEST_TOKEN, $injected);

    foreach (ComponentCatalog::placeableNames() as $name) {
      // The menu line ("- name — …") and the component's prop signature both
      // reach the model — names AND props are guarded against drift.
      $this->assertStringContainsString(
        sprintf('- %s —', $name),
        $injected,
        sprintf('Placeable "%s" never reaches the page agent after manifest injection.', $name),
      );
      $this->assertStringContainsString(
        ComponentCatalog::signature($name),
        $injected,
        sprintf('Prop signature for "%s" never reaches the page agent after manifest injection.', $name),
      );
    }
  }

  /**
   * The stored system prompt of the page agent's reason node, read straight from
   * the shipped config so the test guards the REAL artifact (not a fixture).
   */
  private function pageAgentSystemPrompt(): string {
    // Walk up from this test to the repo's config/sync — robust to the module
    // being relocated, since we search rather than hard-code the depth.
    $dir = __DIR__;
    $config = NULL;
    for ($i = 0; $i < 12; $i++) {
      $candidate = $dir . '/config/sync/flowdrop_workflow.flowdrop_workflow.aincient_pages_agent.yml';
      if (is_file($candidate)) {
        $config = $candidate;
        break;
      }
      $dir = dirname($dir);
    }
    $this->assertNotNull($config, 'Could not locate the page-agent workflow config under config/sync.');

    $data = Yaml::parseFile($config);
    $prompts = [];
    // The shipped prompt may live on a Reason node's `systemPrompt` OR a
    // prompt_template node's `template` (the FlowDrop graph carries it via a
    // prompt_template → reason edge). Collect both so this drift guard tracks the
    // prompt wherever the graph holds it.
    $collect = static function ($node) use (&$collect, &$prompts): void {
      if (!is_array($node)) {
        return;
      }
      foreach ($node as $key => $value) {
        if (($key === 'systemPrompt' || $key === 'template') && is_string($value)) {
          $prompts[] = $value;
        }
        $collect($value);
      }
    };
    $collect($data);

    foreach ($prompts as $prompt) {
      if (str_contains($prompt, ComponentCatalog::MANIFEST_TOKEN)) {
        return $prompt;
      }
    }
    // Return the first prompt (if any) so the token assertion fails with a
    // clear message rather than this helper throwing.
    return $prompts[0] ?? '';
  }

  /**
   * The accordion child allow-list is BOUNDED and ONE level deep: every member
   * is a known placeable, never a container (no accordion/grid/stack), so a
   * panel can never nest a section or another container. The teeth behind the
   * "first heterogeneous container" decision (see GRAMMAR.md).
   */
  public function testAccordionBlocksAreBoundedLeafPlaceables(): void {
    $this->assertNotEmpty(ComponentCatalog::ACCORDION_BLOCKS);
    foreach (ComponentCatalog::ACCORDION_BLOCKS as $child) {
      $this->assertContains(
        $child,
        ComponentCatalog::placeableNames(),
        sprintf('ACCORDION_BLOCKS child "%s" must be a known placeable.', $child),
      );
      $this->assertNotContains(
        $child,
        ComponentCatalog::layoutNames(),
        sprintf('ACCORDION_BLOCKS must not nest a container ("%s") — panels are ONE level deep.', $child),
      );
      $this->assertNotContains(
        $child,
        ComponentCatalog::LAYOUT_RESERVED,
        sprintf('ACCORDION_BLOCKS must not reuse a reserved layout word ("%s").', $child),
      );
      $this->assertNotSame('accordion', $child, 'An accordion can never nest inside an accordion.');
      // The agent-facing `use` text names every allowed block (PHP const exprs
      // can't implode the list into it, so guard the literal against drift).
      $this->assertStringContainsString(
        $child,
        ComponentCatalog::SECTIONS['accordion']['use'],
        sprintf('The accordion `use` hint must name its allowed block "%s".', $child),
      );
    }
  }

  /**
   * The renderer-internal `variant` contract — the teeth behind the top-level
   * bare-variant 500 (an absent `variant` reaching the SDC enum as ""):
   *   1. Every accordion block renders chrome-light (rule 3), so the renderer
   *      owns a `variant` for it — it MUST be registered for backfill, or a
   *      top-level placement 500s on the unfilled enum.
   *   2. A renderer-internal variant is exactly that: NOT an author prop (absent
   *      from SECTIONS props) and NOT in the author VARIANTS clamp map — so the
   *      renderer, never PageStore, is responsible for filling it.
   */
  public function testRendererInternalVariantContract(): void {
    foreach (ComponentCatalog::ACCORDION_BLOCKS as $child) {
      $this->assertContains(
        $child,
        ComponentCatalog::RENDERER_VARIANT_COMPONENTS,
        sprintf('Accordion block "%s" renders `bare`, so it must be in RENDERER_VARIANT_COMPONENTS for the renderer to backfill its variant (else a top-level placement 500s).', $child),
      );
    }
    foreach (ComponentCatalog::RENDERER_VARIANT_COMPONENTS as $name) {
      $this->assertContains(
        $name,
        ComponentCatalog::placeableNames(),
        sprintf('RENDERER_VARIANT_COMPONENTS member "%s" must be a known placeable.', $name),
      );
      $this->assertArrayNotHasKey(
        'variant',
        ComponentCatalog::placeable($name)['props'] ?? [],
        sprintf('"%s" variant is renderer-internal — it must NOT be exposed as an author prop in SECTIONS.', $name),
      );
      $this->assertArrayNotHasKey(
        $name,
        ComponentCatalog::VARIANTS,
        sprintf('"%s" variant is renderer-internal — it must NOT be in the author VARIANTS clamp map.', $name),
      );
    }
  }

  /**
   * IMAGE_PROPS names real prop/row-field words and nothing else — the media
   * picker (studio) and the per-prop image style (renderer) both key off it, so
   * a stray name there would mis-render a non-image prop as an image control.
   */
  public function testImagePropsAreRealAndLocked(): void {
    foreach (ComponentCatalog::IMAGE_PROPS as $prop) {
      $this->assertTrue(ComponentCatalog::isImageProp($prop));
    }
    // `image` is a top-level prop word AND a repeatable row field; `avatar` /
    // `cover` are row / blog words — assert the canonical set, no drift.
    $this->assertSame(['image', 'avatar', 'cover'], ComponentCatalog::IMAGE_PROPS);
    $this->assertFalse(ComponentCatalog::isImageProp('heading'));
    $this->assertFalse(ComponentCatalog::isImageProp('logo'));
    // Every top-level image prop is a real entry in the locked vocab.
    foreach (['image'] as $prop) {
      $this->assertArrayHasKey($prop, ComponentCatalog::PROP_VOCAB);
    }
  }

}
