<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Unit;

use Drupal\aincient_pages\Catalog\CatalogCompiler;
use Drupal\aincient_pages\Catalog\EffectiveCatalog;
use Drupal\aincient_pages\ComponentCatalog;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Yaml\Yaml;

/**
 * Lints the component & layout naming convention (see GRAMMAR.md).
 *
 * These tests are the TEETH of the convention: they fail the build if the two
 * rules the page agent depends on are ever violated as the library grows. The
 * palette is DISCOVERED now (each .component.yml carries its atelier contract),
 * so the lints run over the REAL shipped files compiled through the same
 * CatalogCompiler the site uses — a config-contract test, not a fixture one.
 *
 * @group aincient
 * @coversDefaultClass \Drupal\aincient_pages\ComponentCatalog
 */
#[RunTestsInSeparateProcesses]
final class ComponentCatalogTest extends UnitTestCase {

  /**
   * The compiled bare landing palette (memoized per process).
   */
  private static ?EffectiveCatalog $catalog = NULL;

  /**
   * Compile the REAL .component.yml files on disk into the effective catalog,
   * synthesizing the provider + machineName keys discovery would add.
   */
  private static function catalog(): EffectiveCatalog {
    if (self::$catalog === NULL) {
      $definitions = [];
      foreach (glob(dirname(__DIR__, 3) . '/components/*/*.component.yml') as $file) {
        $name = basename(dirname($file));
        $definitions['aincient_pages:' . $name] = Yaml::parseFile($file) + [
          'provider' => 'aincient_pages',
          'machineName' => $name,
        ];
      }
      self::assertNotEmpty($definitions, 'No .component.yml files found on disk.');
      self::$catalog = CatalogCompiler::compile($definitions, NULL);
    }
    return self::$catalog;
  }

  /**
   * The placeable defs (section + layout + reference), name => def.
   */
  private static function placeableDefs(): array {
    $catalog = self::catalog();
    return $catalog->sections() + $catalog->layout() + $catalog->reference();
  }

  /**
   * RULE 1: every emitted identifier is globally unique (one word, one concept).
   *
   * Merged WITHOUT dedupe across the discovered tiers, so a name landing in two
   * tiers (or twice in one) fails — reservedNames() itself dedupes, which would
   * hide exactly the collision this test exists to catch.
   */
  public function testNamesAreUnique(): void {
    $catalog = self::catalog();
    $names = array_merge(
      $catalog->sectionNames(),
      $catalog->layoutNames(),
      $catalog->referenceNames(),
      $catalog->chrome(),
      $catalog->contentAtoms(),
    );
    $this->assertSame(
      array_values(array_unique($names)),
      array_values($names),
      'Component/layout names must be globally unique across all tiers.',
    );
    // The reserved-word set the grammar lints against covers every tier name.
    foreach ($names as $name) {
      $this->assertContains($name, $catalog->reservedNames());
    }
  }

  /**
   * RULE 1 (corollary): a non-layout name never reuses a reserved layout word —
   * `grid` belongs to the layout tier alone, so `feature-grid` is forbidden. A
   * name that IS a reserved layout word (`card`, the SDC `grid` renders per
   * tile) is the layout word itself, not a reuse — exempt.
   */
  public function testNamesDoNotReuseLayoutWords(): void {
    $catalog = self::catalog();
    $nonLayout = array_merge(
      $catalog->sectionNames(),
      $catalog->chrome(),
      $catalog->contentAtoms(),
    );
    foreach ($nonLayout as $name) {
      if (in_array($name, ComponentCatalog::LAYOUT_RESERVED, TRUE)) {
        continue;
      }
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
   * placeable (section, layout container OR reference) uses must be declared in
   * PROP_VOCAB (no synonyms or abbreviations creep in).
   */
  public function testEveryPlaceablePropIsInTheLockedVocab(): void {
    foreach (self::placeableDefs() as $name => $def) {
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
   * Every placeable variant enum is in the compiled variants() map, and vice
   * versa — the map the validator clamps against must match the components that
   * declare a `variant` prop, so an unknown/missing variant can never trip the
   * SDC enum.
   */
  public function testVariantMapMatchesPlaceablesWithAVariantProp(): void {
    $catalog = self::catalog();
    foreach (self::placeableDefs() as $name => $def) {
      $hasVariant = array_key_exists('variant', $def['props']);
      $this->assertSame(
        $hasVariant,
        isset($catalog->variants()[$name]),
        sprintf('"%s" variant prop and the compiled variants clamp map must agree.', $name),
      );
    }
    foreach (array_keys($catalog->variants()) as $name) {
      $this->assertContains($name, $catalog->placeableNames());
    }
  }

  /**
   * The agent manifest lists every placeable (section + layout container) from
   * the single source of truth, so the prompt can never drift from the
   * validator's allow-list.
   */
  public function testManifestCoversEveryPlaceable(): void {
    $catalog = self::catalog();
    $manifest = $catalog->manifest();
    foreach ($catalog->placeableNames() as $name) {
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
    $catalog = self::catalog();
    $prompt = $this->pageAgentSystemPrompt();
    $this->assertStringContainsString(
      ComponentCatalog::MANIFEST_TOKEN,
      $prompt,
      'The page-agent prompt must carry the manifest token (not an inlined copy of the menu) so the catalogue can never drift.',
    );

    // Phase 5: the kind list is generated into the prompt the same way — the
    // registry call, never an inlined copy of "landing | blog".
    $this->assertStringContainsString(
      ComponentCatalog::KINDS_TOKEN,
      $prompt,
      'The page-agent prompt must carry the page_kinds() token so the kind list is generated from the registry.',
    );

    // Substitute what the Twig render produces for this exact call.
    $injected = str_replace(ComponentCatalog::MANIFEST_TOKEN, $catalog->manifest(), $prompt);
    $this->assertStringNotContainsString(ComponentCatalog::MANIFEST_TOKEN, $injected);

    foreach ($catalog->placeableNames() as $name) {
      // The menu line ("- name — …") and the component's prop signature both
      // reach the model — names AND props are guarded against drift.
      $this->assertStringContainsString(
        sprintf('- %s —', $name),
        $injected,
        sprintf('Placeable "%s" never reaches the page agent after manifest injection.', $name),
      );
      $this->assertStringContainsString(
        $catalog->signature($name),
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
   * The agent's prompt carries prop NAMES, not PROP_VOCAB meanings, so the link
   * grammar has to reach it some other way: the manifest ends with the LINK
   * TARGETS note. Without it the agent has no way to know a link prop takes
   * anything but a URL, and invents a path for a page that may not exist.
   */
  public function testManifestTeachesTheLinkGrammar(): void {
    $manifest = self::catalog()->manifest();
    foreach (ComponentCatalog::LINK_PROPS as $prop) {
      $this->assertStringContainsString($prop, $manifest);
    }
    // The note is the manifest's closing block — the fixed-size paragraph, never
    // an inlined site listing.
    $this->assertStringEndsWith(ComponentCatalog::linkTargetNote(), $manifest);
    // The token form, and the retrieval route for anything not already listed.
    $this->assertStringContainsString('entity:node:15', $manifest);
    $this->assertStringContainsString('find_reference', $manifest);
    $this->assertStringContainsString('SITE DESTINATIONS', $manifest);
  }

  /**
   * The prompt-side of the "don't inline the site" rule: the page agent is given
   * the site's destinations through the `site_destinations()` Twig call — a
   * BOUNDED block built from the main menu — and the manifest itself stays a
   * pure function of the grammar. If either the call goes missing or the
   * manifest starts carrying live site content, the scalability property (prompt
   * size independent of page count) is gone.
   */
  public function testPageAgentGetsDestinationsThroughTheBoundedCall(): void {
    $this->assertStringContainsString('site_destinations()', $this->pageAgentSystemPrompt());
    // The manifest is grammar only — it may POINT at the destinations block, but
    // it must never carry destination lines itself (that is live site content).
    $this->assertStringNotContainsString('→', self::catalog()->manifest());
  }

  /**
   * The accordion child allow-list is BOUNDED and ONE level deep: every member
   * is a known placeable, never a container (no accordion/grid/stack), so a
   * panel can never nest a section or another container. The teeth behind the
   * "first heterogeneous container" decision (see GRAMMAR.md).
   */
  public function testAccordionBlocksAreBoundedLeafPlaceables(): void {
    $catalog = self::catalog();
    $this->assertNotEmpty(ComponentCatalog::ACCORDION_BLOCKS);
    foreach (ComponentCatalog::ACCORDION_BLOCKS as $child) {
      $this->assertNotNull(
        $catalog->placeable($child),
        sprintf('ACCORDION_BLOCKS child "%s" must be a known placeable.', $child),
      );
      $this->assertNotContains(
        $child,
        $catalog->layoutNames(),
        sprintf('ACCORDION_BLOCKS must not nest a container ("%s") — panels are ONE level deep.', $child),
      );
      $this->assertNotContains(
        $child,
        ComponentCatalog::LAYOUT_RESERVED,
        sprintf('ACCORDION_BLOCKS must not reuse a reserved layout word ("%s").', $child),
      );
      $this->assertNotSame('accordion', $child, 'An accordion can never nest inside an accordion.');
      // The agent-facing `use` text names every allowed block — guard the
      // shipped hint against drift.
      $this->assertStringContainsString(
        $child,
        $catalog->placeable('accordion')['use'] ?? '',
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
   *      from the def's atelier props) and NOT in the compiled variants clamp
   *      map — so the renderer, never PageStore, is responsible for filling it.
   *      Its SDC schema, though, DOES declare the enum with `bare` in it — that
   *      is what the renderer backfills against.
   */
  public function testRendererInternalVariantContract(): void {
    $catalog = self::catalog();
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
        $catalog->placeableNames(),
        sprintf('RENDERER_VARIANT_COMPONENTS member "%s" must be a known placeable.', $name),
      );
      $this->assertArrayNotHasKey(
        'variant',
        $catalog->placeable($name)['props'] ?? [],
        sprintf('"%s" variant is renderer-internal — it must NOT be exposed as an author prop in the atelier contract.', $name),
      );
      $this->assertNull(
        $catalog->variantsFor($name),
        sprintf('"%s" variant is renderer-internal — it must NOT be in the author variants clamp map.', $name),
      );
      // The SDC schema itself DOES carry the enum, with the chrome-light `bare`
      // value the renderer backfills for a panel child.
      $sdc = Yaml::parseFile(sprintf('%s/components/%s/%s.component.yml', dirname(__DIR__, 3), $name, $name));
      $this->assertContains(
        'bare',
        $sdc['props']['properties']['variant']['enum'] ?? [],
        sprintf('"%s" must declare a `bare` value in its SDC variant enum for the renderer to select.', $name),
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

  /**
   * LINK_PROPS names real prop/row-field words, and every pairing in
   * LINK_LABELS points at a real label word — the studio renders the URL｜Page
   * control off the first, and the renderer DROPS the pair off the second, so a
   * stray/misspelled name would either mis-render a control or silently fail to
   * remove a dead button's label.
   */
  public function testLinkPropsAreRealAndPairedWithLabels(): void {
    foreach (ComponentCatalog::LINK_PROPS as $prop) {
      $this->assertTrue(ComponentCatalog::isLinkProp($prop));
    }
    // The top-level link words are real entries in the locked vocab (`url` is a
    // row field only — the logos shape — exactly like `avatar` / `cover`).
    foreach (['cta_url', 'secondary_url'] as $prop) {
      $this->assertArrayHasKey($prop, ComponentCatalog::PROP_VOCAB);
    }
    $this->assertSame(['cta_url', 'secondary_url', 'url'], ComponentCatalog::LINK_PROPS);
    $this->assertFalse(ComponentCatalog::isLinkProp('cta_label'));
    $this->assertFalse(ComponentCatalog::isLinkProp('image'));
    // A link prop and an image prop are disjoint sets — the studio picks ONE
    // control per prop word, and they'd fight over the same field.
    $this->assertSame([], array_intersect(ComponentCatalog::LINK_PROPS, ComponentCatalog::IMAGE_PROPS));
    foreach (ComponentCatalog::LINK_LABELS as $url => $label) {
      $this->assertTrue(ComponentCatalog::isLinkProp($url), sprintf('"%s" pairs a label but is not a link prop.', $url));
      $this->assertArrayHasKey($label, ComponentCatalog::PROP_VOCAB, sprintf('"%s" must be a locked vocab word.', $label));
    }
    // A logo row's `url` has no label — it degrades to an unlinked mark.
    $this->assertArrayNotHasKey('url', ComponentCatalog::LINK_LABELS);
  }

  /**
   * Every link prop / label word the twigs actually use appears in the maps —
   * scanned from the placeable defs (top-level props AND repeatable row shapes),
   * so adding a `cta_url` to a new component can't quietly skip the picker.
   */
  public function testEveryPlaceableLinkPropIsCovered(): void {
    $seen = [];
    foreach (self::placeableDefs() as $def) {
      foreach ($def['props'] as $prop => $hint) {
        $seen[] = $prop;
        // Row shapes, e.g. "[{title,body,cta_label,cta_url}]".
        if (str_starts_with((string) $hint, '[') && preg_match('/\{([^}]*)\}/', (string) $hint, $m)) {
          foreach (explode(',', $m[1]) as $field) {
            $seen[] = trim($field);
          }
        }
      }
    }
    foreach (array_unique($seen) as $name) {
      if (str_ends_with($name, '_url') || $name === 'url') {
        $this->assertTrue(
          ComponentCatalog::isLinkProp($name),
          sprintf('"%s" looks like a link target but is missing from LINK_PROPS (the studio would render a bare text input and the renderer would not resolve a page token).', $name),
        );
      }
    }
  }

  /**
   * The PROMPT BUDGET (plans/byo-components.md decision #3): the compiled
   * manifest is inlined into the page agent's system prompt on every turn, so
   * its rendered size is a per-turn cost every site pays. Growing the palette
   * is fine — growing it PAST this ceiling needs a deliberate decision (tighter
   * `use` hints, or per-kind narrowing) rather than drift. The shipped landing
   * manifest is ~7.0k chars (~1.7k tokens) today; the ceiling leaves headroom
   * for a handful of components, not for prose creep.
   */
  public function testManifestStaysInsideThePromptBudget(): void {
    $length = strlen(self::catalog()->manifest());
    $this->assertLessThanOrEqual(9000, $length, sprintf(
      'The landing manifest is %d chars (~%d tokens) — past the prompt budget. Tighten `use` hints or narrow the kind before raising the ceiling.',
      $length,
      intdiv($length, 4),
    ));
  }

}
