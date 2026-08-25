<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Unit;

use Drupal\aincient_pages\Catalog\CatalogCompiler;
use Drupal\aincient_pages\ComponentCatalog;
use Drupal\aincient_pages\Entity\PageKindInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests the pure compile step: definitions ∩ site-constraint ∩ kind.
 *
 * Two definition sources: a small hand-rolled fixture set for the narrowing /
 * never-fatal behaviour, and the REAL .component.yml files on disk for the
 * parity test that pins the compiled landing catalog byte-compatible with the
 * legacy ComponentCatalog statics (zero behaviour change).
 *
 * @group aincient
 * @coversDefaultClass \Drupal\aincient_pages\Catalog\CatalogCompiler
 */
#[RunTestsInSeparateProcesses]
final class CatalogCompilerTest extends UnitTestCase {

  /**
   * Hand-rolled SDC-definition stand-ins across the tiers.
   *
   * `omega` (order 10) sorts before `alpha` (order 20); `alpha` carries a
   * variant hint; `naked` has no atelier key and must be ignored.
   */
  private function fixtureDefinitions(): array {
    return [
      'aincient_pages:alpha' => [
        'provider' => 'aincient_pages',
        'machineName' => 'alpha',
        'thirdPartySettings' => [
          'atelier' => [
            'api' => 1,
            'tier' => 'section',
            'order' => 20,
            'icon' => '◆',
            'use' => 'Alpha section.',
            'props' => [
              'variant' => 'centered|split',
              'tone' => '',
              'heading' => '',
            ],
          ],
        ],
      ],
      'aincient_pages:omega' => [
        'provider' => 'aincient_pages',
        'machineName' => 'omega',
        'thirdPartySettings' => [
          'atelier' => [
            'api' => 1,
            'tier' => 'section',
            'order' => 10,
            'icon' => '●',
            'use' => 'Omega section.',
            'props' => [
              'tone' => '',
              'heading' => '',
            ],
          ],
        ],
      ],
      'aincient_pages:frame' => [
        'provider' => 'aincient_pages',
        'machineName' => 'frame',
        'thirdPartySettings' => [
          'atelier' => [
            'api' => 1,
            'tier' => 'layout',
            'order' => 10,
            'icon' => '⊡',
            'use' => 'Frame container.',
            'props' => [
              'tone' => '',
              'columns' => '2|3',
            ],
          ],
        ],
      ],
      'aincient_pages:mirror' => [
        'provider' => 'aincient_pages',
        'machineName' => 'mirror',
        'thirdPartySettings' => [
          'atelier' => [
            'api' => 1,
            'tier' => 'reference',
            'order' => 10,
            'icon' => '⧉',
            'use' => 'Mirror reference.',
            'props' => [
              'entity' => '',
            ],
          ],
        ],
      ],
      // No atelier contract — must never reach the palette.
      'aincient_pages:naked' => [
        'provider' => 'aincient_pages',
        'machineName' => 'naked',
      ],
    ];
  }

  /**
   * A stubbed kind entity — the compiler only reads the interface surface.
   */
  private function kind(
    string $id = 'promo',
    array $components = [],
    ?string $opener = NULL,
    array $limits = [],
    string $mode = PageKindInterface::MODE_COMPOSITION,
    bool $collectionSource = FALSE,
    string $hint = '',
  ): PageKindInterface {
    $kind = $this->createMock(PageKindInterface::class);
    $kind->method('id')->willReturn($id);
    $kind->method('mode')->willReturn($mode);
    $kind->method('isComposition')->willReturn($mode === PageKindInterface::MODE_COMPOSITION);
    $kind->method('components')->willReturn($components);
    $kind->method('opener')->willReturn($opener);
    $kind->method('limits')->willReturn($limits);
    $kind->method('isCollectionSource')->willReturn($collectionSource);
    $kind->method('hint')->willReturn($hint);
    return $kind;
  }

  /**
   * Tier discovery, order sorting, and the atelier-key gate.
   */
  public function testTierDiscoveryAndOrderSorting(): void {
    $catalog = CatalogCompiler::compile($this->fixtureDefinitions(), NULL);
    // Sections sort by their declared order: omega (10) before alpha (20).
    $this->assertSame(['omega', 'alpha'], $catalog->sectionNames());
    $this->assertSame(['frame'], $catalog->layoutNames());
    // mirror (order 10) before the virtual block (order 20).
    $this->assertSame(['mirror', 'block'], $catalog->referenceNames());
    // A definition without the atelier key never reaches the palette.
    $this->assertNotContains('naked', $catalog->placeableNames());
    $this->assertSame([], $catalog->warnings());
    // The bare compile stamps landing semantics.
    $this->assertSame('landing', $catalog->kind());
    $this->assertTrue($catalog->isComposition());
  }

  /**
   * The virtual `block` def has no SDC: NULL plugin id; a discovered
   * component's plugin id is 'provider:name'.
   */
  public function testVirtualBlockAndPluginIds(): void {
    $catalog = CatalogCompiler::compile($this->fixtureDefinitions(), NULL);
    $this->assertArrayHasKey('block', $catalog->reference());
    $this->assertNull($catalog->pluginId('block'));
    $this->assertSame('aincient_pages:alpha', $catalog->pluginId('alpha'));
    $this->assertSame('aincient_pages:frame', $catalog->pluginId('frame'));
    $this->assertNull($catalog->pluginId('ghost'));
  }

  /**
   * Site constraint removes components; an unknown removal warns, never fatal.
   */
  public function testConstraintComponentRemoval(): void {
    $catalog = CatalogCompiler::compile($this->fixtureDefinitions(), NULL, [
      'components' => ['alpha', 'ghost'],
    ]);
    $this->assertNotContains('alpha', $catalog->placeableNames());
    $this->assertContains('omega', $catalog->placeableNames());
    $this->assertCount(1, $catalog->warnings());
    $this->assertStringContainsString('ghost', $catalog->warnings()[0]);
  }

  /**
   * Site constraint narrows tones; removing every tone is ignored + warned.
   */
  public function testConstraintToneRemoval(): void {
    $catalog = CatalogCompiler::compile($this->fixtureDefinitions(), NULL, [
      'tones' => ['muted', 'inverted'],
    ]);
    $this->assertSame(['default', 'brand'], $catalog->tones());
    $this->assertSame([], $catalog->warnings());
    // The tone enum in a signature reflects the narrowed set.
    $this->assertStringContainsString('tone(default|brand)', $catalog->signature('omega'));

    $degraded = CatalogCompiler::compile($this->fixtureDefinitions(), NULL, [
      'tones' => ComponentCatalog::TONES,
    ]);
    $this->assertSame(ComponentCatalog::TONES, $degraded->tones());
    $this->assertCount(1, $degraded->warnings());
    $this->assertStringContainsString('every tone', $degraded->warnings()[0]);
  }

  /**
   * Constraint variant removal narrows variantsFor() AND the signature's
   * variant hint; removing every variant is ignored + warned.
   */
  public function testConstraintVariantRemoval(): void {
    $catalog = CatalogCompiler::compile($this->fixtureDefinitions(), NULL, [
      'variants' => ['alpha' => ['split']],
    ]);
    $this->assertSame(['centered'], $catalog->variantsFor('alpha'));
    $this->assertStringContainsString('variant(centered)', $catalog->signature('alpha'));
    $this->assertStringNotContainsString('split', $catalog->signature('alpha'));
    $this->assertSame([], $catalog->warnings());

    $degraded = CatalogCompiler::compile($this->fixtureDefinitions(), NULL, [
      'variants' => ['alpha' => ['centered', 'split']],
    ]);
    $this->assertSame(['centered', 'split'], $degraded->variantsFor('alpha'));
    $this->assertStringContainsString('variant(centered|split)', $degraded->signature('alpha'));
    $this->assertCount(1, $degraded->warnings());
    $this->assertStringContainsString('every variant', $degraded->warnings()[0]);
  }

  /**
   * A kind with a non-empty components map is an allow-list; an unknown
   * allowed name warns and is skipped.
   */
  public function testKindAllowListNarrowing(): void {
    $kind = $this->kind(components: ['alpha' => [], 'ghost' => []]);
    $catalog = CatalogCompiler::compile($this->fixtureDefinitions(), $kind);
    $this->assertSame(['alpha'], $catalog->placeableNames());
    // The allow-list removes the virtual block too.
    $this->assertSame([], $catalog->referenceNames());
    $this->assertCount(1, $catalog->warnings());
    $this->assertStringContainsString('ghost', $catalog->warnings()[0]);
    $this->assertSame('promo', $catalog->kind());
  }

  /**
   * Kind variant/tone subsets intersect the declared enums; a subset matching
   * nothing warns and keeps the full set.
   */
  public function testKindVariantAndToneSubsets(): void {
    $kind = $this->kind(components: [
      'alpha' => ['variants' => ['split'], 'tones' => ['brand']],
      'omega' => [],
    ]);
    $catalog = CatalogCompiler::compile($this->fixtureDefinitions(), $kind);
    $this->assertSame(['split'], $catalog->variantsFor('alpha'));
    $this->assertSame(['brand'], $catalog->tonesFor('alpha'));
    // omega declares no subset — it sees the effective site-wide enum.
    $this->assertSame(ComponentCatalog::TONES, $catalog->tonesFor('omega'));
    $this->assertSame([], $catalog->warnings());

    $mismatched = $this->kind(components: [
      'alpha' => ['variants' => ['nope'], 'tones' => ['neon']],
    ]);
    $degraded = CatalogCompiler::compile($this->fixtureDefinitions(), $mismatched);
    $this->assertSame(['centered', 'split'], $degraded->variantsFor('alpha'));
    $this->assertSame(ComponentCatalog::TONES, $degraded->tonesFor('alpha'));
    $this->assertCount(2, $degraded->warnings());
  }

  /**
   * Opener + limits: unavailable opener → NULL + warning; a valid opener is
   * kept; an unknown limit is dropped with a warning, a valid one coerced to
   * an int ≥ 1.
   */
  public function testOpenerAndLimits(): void {
    $valid = $this->kind(opener: 'omega', limits: ['alpha' => '3', 'omega' => 0]);
    $catalog = CatalogCompiler::compile($this->fixtureDefinitions(), $valid);
    $this->assertSame('omega', $catalog->opener());
    $this->assertSame(['alpha' => 3, 'omega' => 1], $catalog->limits());
    $this->assertSame([], $catalog->warnings());

    $broken = $this->kind(opener: 'ghost', limits: ['ghost' => 2]);
    $degraded = CatalogCompiler::compile($this->fixtureDefinitions(), $broken);
    $this->assertNull($degraded->opener());
    $this->assertSame([], $degraded->limits());
    $this->assertCount(2, $degraded->warnings());
    $this->assertStringContainsString('opener "ghost"', $degraded->warnings()[0]);
    $this->assertStringContainsString('limit for unknown component "ghost"', $degraded->warnings()[1]);
  }

  /**
   * A recipe-mode kind: mode 'recipe', not composition — palette still
   * compiled (the studio/manifest never blanks).
   */
  public function testRecipeModeKind(): void {
    $kind = $this->kind(id: 'blog', mode: PageKindInterface::MODE_RECIPE, collectionSource: TRUE, hint: 'A written article.');
    $catalog = CatalogCompiler::compile($this->fixtureDefinitions(), $kind);
    $this->assertSame('recipe', $catalog->mode());
    $this->assertFalse($catalog->isComposition());
    $this->assertTrue($catalog->isCollectionSource());
    $this->assertSame('A written article.', $catalog->hint());
    $this->assertSame(['omega', 'alpha'], $catalog->sectionNames());
    $this->assertNotEmpty($catalog->placeableNames());
  }

  /**
   * REAL DATA SANITY: compiling the REAL .component.yml files on disk (kind
   * NULL, landing semantics, no constraint) yields the shipped palette — the
   * stable facts pinned directly now that the legacy statics are gone.
   */
  public function testRealComponentSanity(): void {
    $catalog = CatalogCompiler::compile($this->realDefinitions(), NULL);

    $names = $catalog->placeableNames();
    $this->assertCount(21, $names);
    $expected = [
      'hero', 'features', 'stats', 'cta', 'banner', 'newsletter', 'content',
      'markdown', 'image', 'gallery', 'logos', 'testimonials', 'team',
      'pricing', 'faq', 'accordion', 'divider', 'grid', 'embed', 'collection',
      'block',
    ];
    foreach ($expected as $name) {
      $this->assertContains($name, $names);
    }

    $manifest = $catalog->manifest();
    $this->assertStringStartsWith('LANDING sections (compose 3–6, in this rough order):', $manifest);
    $this->assertStringEndsWith(ComponentCatalog::linkTargetNote(), $manifest);

    $this->assertEquals([
      'hero' => ['centered', 'split'],
      'content' => ['image-right', 'image-left', 'text-only'],
      'divider' => ['line', 'space', 'label'],
    ], $catalog->variants());

    $this->assertSame(
      'variant(centered|split), tone(default|muted|brand|inverted), eyebrow, heading, subheading, cta_label, cta_url, secondary_label, secondary_url, image',
      $catalog->signature('hero'),
    );
    $this->assertSame(['default', 'muted', 'brand', 'inverted'], $catalog->tones());
    $this->assertSame(ComponentCatalog::TONES, $catalog->tones());
    $this->assertSame([], $catalog->warnings());
  }

  /**
   * Parse every shipped .component.yml into an SDC-definition stand-in,
   * synthesizing the provider + machineName keys discovery would add.
   */
  private function realDefinitions(): array {
    $definitions = [];
    $files = glob(dirname(__DIR__, 3) . '/components/*/*.component.yml');
    $this->assertNotEmpty($files, 'No .component.yml files found on disk.');
    foreach ($files as $file) {
      $name = basename(dirname($file));
      $definitions['aincient_pages:' . $name] = Yaml::parseFile($file) + [
        'provider' => 'aincient_pages',
        'machineName' => $name,
      ];
    }
    return $definitions;
  }

}
