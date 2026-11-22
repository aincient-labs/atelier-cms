<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Unit;

use Aincient\PageModel\Catalog\AdmissionGate;
use Aincient\PageModel\ComponentCatalog;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests the admission gate — the hard floor a component must pass to enter
 * the catalog (plans/byo-components.md §3.3). These fixtures are the CONTRACT
 * a client pack is validated against (`drush atelier:pack-validate` runs this
 * exact code), so every rule here is a promise to a pack author.
 *
 * @group aincient
 * @coversDefaultClass \Aincient\PageModel\Catalog\AdmissionGate
 */
#[RunTestsInSeparateProcesses]
final class AdmissionGateTest extends UnitTestCase {

  /**
   * A hand-rolled definition, shaped like an SDC plugin definition (the same
   * stand-in shape CatalogCompiler documents: provider + machineName +
   * thirdPartySettings).
   */
  private static function def(string $name, array $atelier, array $extra = [], string $provider = 'x_pack'): array {
    return $extra + [
      'provider' => $provider,
      'machineName' => $name,
      'thirdPartySettings' => ['atelier' => $atelier],
    ];
  }

  /**
   * A minimal VALID placeable section contract, overridable per case — every
   * rejection test flips exactly one thing off this baseline, proving the
   * failure is the rule under test and not an unrelated one.
   */
  private static function section(array $overrides = []): array {
    return $overrides + [
      'api' => 1,
      'tier' => 'section',
      'use' => 'One product shot beside a single claim.',
      'props' => ['heading' => ''],
      'examples' => [['props' => ['heading' => 'A claim']]],
    ];
  }

  /**
   * The errors for one named verdict.
   */
  private static function errors(array $definitions, string $name): array {
    return self::verdict($definitions, $name)['errors'];
  }

  /**
   * The verdict for one short name (each fixture holds one def per name
   * unless it says otherwise).
   */
  private static function verdict(array $definitions, string $name): array {
    foreach (AdmissionGate::check($definitions) as $id => $verdict) {
      if (str_ends_with($id, ':' . $name)) {
        return $verdict;
      }
    }
    throw new \LogicException("No verdict for $name.");
  }

  /**
   * An unknown api major is refused outright — guessing at a future contract
   * is worse than skipping the component.
   */
  public function testUnknownApiMajorIsRejected(): void {
    $errors = self::errors([self::def('promo', self::section(['api' => 99]))], 'promo');
    $this->assertCount(1, $errors);
    $this->assertStringContainsString('unknown or missing atelier api version', $errors[0]);
  }

  /**
   * `use` is mandatory on a placeable: a pack component has no pretraining
   * prior, so the selection hint is the only reason the agent places it.
   */
  public function testMissingUseOnASectionIsRejected(): void {
    $errors = self::errors([self::def('promo', self::section(['use' => '  ']))], 'promo');
    $this->assertCount(1, $errors);
    $this->assertStringContainsString('missing "use"', $errors[0]);
  }

  /**
   * A prop word outside the locked vocabulary is a prop the agent will
   * misuse — rejected without a pack-local meaning, admitted with one.
   */
  public function testPropOutsideVocabNeedsAPackLocalMeaning(): void {
    $bare = self::def('promo', self::section(['props' => ['claim' => '']]));
    $errors = self::errors([$bare], 'promo');
    $this->assertCount(1, $errors);
    $this->assertStringContainsString('prop "claim" is not in the locked vocabulary', $errors[0]);

    $withMeaning = self::def('promo', self::section([
      'props' => ['claim' => ''],
      'prop_vocab' => ['claim' => 'the single bold claim sentence.'],
    ]));
    $this->assertSame([], self::errors([$withMeaning], 'promo'));
  }

  /**
   * A variant hinted to the agent but absent from the SDC schema enum would
   * make the clamp write a value the SDC 500s on — rejected.
   */
  public function testVariantHintOutsideTheSdcEnumIsRejected(): void {
    $def = self::def('promo', self::section(['props' => ['variant' => 'left|right']]), [
      'props' => ['properties' => ['variant' => ['enum' => ['left']]]],
    ]);
    $errors = self::errors([$def], 'promo');
    $this->assertCount(1, $errors);
    $this->assertStringContainsString('variant "right" is hinted to the agent but absent from the SDC schema enum (left)', $errors[0]);
  }

  /**
   * Names are unique per provider, not globally (DECISIONS 0539): a pack
   * component and a built-in that share a short name are both admitted, in
   * either discovery order — each page kind offers one of them.
   */
  public function testASameNamePairIsAdmitted(): void {
    $pack = ['acme_pack:promo' => self::def('promo', self::section(), [], 'acme_pack')];
    $builtin = ['atelier:promo' => self::def('promo', self::section(), [], 'atelier')];
    foreach ([$pack + $builtin, $builtin + $pack] as $definitions) {
      $verdicts = AdmissionGate::check($definitions);
      $this->assertEqualsCanonicalizing(['acme_pack:promo', 'atelier:promo'], array_keys($verdicts));
      $this->assertSame([], $verdicts['acme_pack:promo']['errors']);
      $this->assertSame([], $verdicts['atelier:promo']['errors']);
    }
  }

  /**
   * Everything else that shares a name still collides (0539 allows only a
   * pack + built-in pair of the same placeable tier): two packs (the second
   * refused), a second pack beside a pair, a pack sharing a built-in CONTENT
   * or CHROME name (the renderer asks for those by short name), and a pack
   * sharing a built-in section name from another tier.
   */
  public function testOnlyAPackAndABuiltinOfTheSamePlaceableTierMayShareAName(): void {
    $verdicts = AdmissionGate::check([
      'x_pack:promo' => self::def('promo', self::section()),
      'y_pack:promo' => self::def('promo', self::section(), [], 'y_pack'),
    ]);
    $this->assertSame([], $verdicts['x_pack:promo']['errors']);
    $this->assertStringContainsString('name collides with "x_pack:promo"', $verdicts['y_pack:promo']['errors'][0]);

    $verdicts = AdmissionGate::check([
      'atelier:promo' => self::def('promo', self::section(), [], 'atelier'),
      'a_pack:promo' => self::def('promo', self::section(), [], 'a_pack'),
      'b_pack:promo' => self::def('promo', self::section(), [], 'b_pack'),
    ]);
    $this->assertSame([], $verdicts['a_pack:promo']['errors'], 'The first pack pairs with the built-in.');
    $this->assertStringContainsString('name collides with "atelier:promo"', $verdicts['b_pack:promo']['errors'][0]);

    foreach (['content', 'chrome'] as $tier) {
      $verdicts = AdmissionGate::check([
        'atelier:byline' => self::def('byline', ['api' => 1, 'tier' => $tier, 'props' => []], [], 'atelier'),
        'acme_pack:byline' => self::def('byline', ['api' => 1, 'tier' => $tier, 'props' => []], [], 'acme_pack'),
      ]);
      $this->assertStringContainsString('name collides with "atelier:byline"', $verdicts['acme_pack:byline']['errors'][0], $tier);
    }

    $verdicts = AdmissionGate::check([
      'atelier:promo' => self::def('promo', self::section(), [], 'atelier'),
      'acme_pack:promo' => self::def('promo', ['api' => 1, 'tier' => 'content', 'props' => []], [], 'acme_pack'),
    ]);
    $this->assertStringContainsString('name collides with "atelier:promo"', $verdicts['acme_pack:promo']['errors'][0]);
  }

  /**
   * The virtual `block` name stays reserved for every provider.
   */
  public function testVirtualNameIsReservedForEveryProvider(): void {
    $errors = self::errors([self::def('block', self::section(), [], 'acme_pack')], 'block');
    $this->assertCount(1, $errors);
    $this->assertStringContainsString('reserved for Atelier\'s virtual block section', $errors[0]);
  }

  /**
   * Names the renderer treats specially stay Atelier's (0539): a pack's
   * `markdown` is refused; the built-in itself is not.
   */
  public function testRendererReservedNamesAreRefusedForPacks(): void {
    foreach (ComponentCatalog::RENDERER_RESERVED as $name) {
      $errors = self::errors([self::def($name, self::section(), [], 'acme_pack')], $name);
      $this->assertCount(1, $errors, $name);
      $this->assertStringContainsString('reserved for Atelier\'s built-in', $errors[0]);
      $this->assertSame([], self::errors([self::def($name, self::section(), [], 'atelier')], $name), "atelier:$name");
    }
  }

  /**
   * Reserved layout words cannot name a section — but the layout word IS the
   * layout tier's own name, so 'grid' as a layout def is admitted.
   */
  public function testReservedLayoutWordAsASectionNameIsRejected(): void {
    $errors = self::errors([self::def('grid', self::section())], 'grid');
    $this->assertCount(1, $errors);
    $this->assertStringContainsString('"grid" is a reserved layout word', $errors[0]);

    $asLayout = self::def('grid', self::section(['tier' => 'layout', 'props' => ['columns' => '']]));
    $this->assertSame([], self::errors([$asLayout], 'grid'), 'The layout word naming its own layout tier is exempt.');
  }

  /**
   * The tone enum is shared and injected by the catalog — a bespoke per-pack
   * tone hint would drift from the clamp, so a non-empty hint is rejected.
   */
  public function testNonEmptyToneHintIsRejected(): void {
    $errors = self::errors([self::def('promo', self::section(['props' => ['tone' => 'default|brand']]))], 'promo');
    $this->assertCount(1, $errors);
    $this->assertStringContainsString('the "tone" prop hint must be empty', $errors[0]);
  }

  /**
   * An image SDC SLOT the renderer can never fill (no image_props view_mode
   * mapping) is a slot that silently renders empty — rejected up front.
   */
  public function testImageSlotWithoutViewModeIsRejected(): void {
    $slots = ['slots' => ['image' => ['title' => 'Image']]];
    $errors = self::errors([self::def('promo', self::section(), $slots)], 'promo');
    $this->assertCount(1, $errors);
    $this->assertStringContainsString('SDC slot "image" is an image slot but declares no image_props.image.view_mode', $errors[0]);

    $mapped = self::def('promo', self::section(['image_props' => ['image' => ['view_mode' => 'card']]]), $slots);
    $verdict = self::verdict([$mapped], 'promo');
    $this->assertSame([], $verdict['errors']);
    $this->assertSame([], $verdict['warnings']);
  }

  /**
   * The minimal clean section admits with no errors AND no warnings — the
   * baseline every rejection test above is one flip away from.
   */
  public function testCleanMinimalSectionPasses(): void {
    $verdict = self::verdict([self::def('promo', self::section())], 'promo');
    $this->assertSame('section', $verdict['tier']);
    $this->assertSame([], $verdict['errors']);
    $this->assertSame([], $verdict['warnings']);
  }

  /**
   * `examples` entries must be story-shaped (a list of { props: {...} }) —
   * a malformed example would 500 the gallery, so the gate rejects it.
   */
  public function testMalformedExamplesAreRejected(): void {
    $this->assertNotSame([], self::errors([self::def('promo', self::section(['examples' => ['props' => []]]))], 'promo'), 'A mapping (not a list) is rejected.');
    $this->assertNotSame([], self::errors([self::def('promo', self::section(['examples' => [['name' => 'x']]]))], 'promo'), 'An entry without a props map is rejected.');
  }

  /**
   * A PACK placeable without examples warns (the gallery and few-shots need
   * them); our own built-ins predate the key, so aincient_pages is exempt.
   */
  public function testPackPlaceableWithoutExamplesWarns(): void {
    $atelier = self::section();
    unset($atelier['examples']);
    $verdict = self::verdict([self::def('promo', $atelier)], 'promo');
    $this->assertSame([], $verdict['errors']);
    $this->assertStringContainsString('no "examples" declared', implode(' ', $verdict['warnings']));

    $builtin = self::verdict([self::def('promo', $atelier, [], 'atelier')], 'promo');
    $this->assertSame([], $builtin['warnings'], 'Built-ins are exempt from the examples warning.');
  }

  /**
   * The 28 built-ins are the gate's permanent fixtures (§3.3): the REAL
   * shipped .component.yml files pass with zero rejections and zero warnings.
   * If this fails, we broke our own contract before any client could.
   */
  public function testRealShippedComponentsPassTheGate(): void {
    $definitions = [];
    foreach (glob(dirname(__DIR__, 4) . '/atelier/components/*/*.component.yml') as $file) {
      $name = basename(dirname($file));
      $definitions['atelier:' . $name] = Yaml::parseFile($file) + [
        'provider' => 'atelier',
        'machineName' => $name,
      ];
    }
    $this->assertNotEmpty($definitions, 'No .component.yml files found on disk.');
    foreach (AdmissionGate::check($definitions) as $name => $verdict) {
      $this->assertSame([], $verdict['errors'], sprintf('"%s" is rejected by the gate.', $name));
      $this->assertSame([], $verdict['warnings'], sprintf('"%s" draws gate warnings.', $name));
    }
  }

}
