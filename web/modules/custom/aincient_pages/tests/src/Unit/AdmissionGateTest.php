<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Unit;

use Drupal\aincient_pages\Catalog\AdmissionGate;
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
 * @coversDefaultClass \Drupal\aincient_pages\Catalog\AdmissionGate
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
    return AdmissionGate::check($definitions)[$name]['errors'];
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
   * Names are globally unique (one word, one concept): the SECOND def to
   * claim a name is the one rejected, naming the def it collided with.
   */
  public function testNameCollisionRejectsTheSecondDef(): void {
    $verdicts = AdmissionGate::check([
      'x_pack:promo' => self::def('promo', self::section()),
      'y_pack:promo' => self::def('promo', self::section(), [], 'y_pack'),
    ]);
    $this->assertSame('y_pack', $verdicts['promo']['provider'], 'The colliding (second) def is the surviving verdict.');
    $this->assertCount(1, $verdicts['promo']['errors']);
    $this->assertStringContainsString('name collides with "x_pack:promo"', $verdicts['promo']['errors'][0]);
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
    $verdict = AdmissionGate::check([$mapped])['promo'];
    $this->assertSame([], $verdict['errors']);
    $this->assertSame([], $verdict['warnings']);
  }

  /**
   * The minimal clean section admits with no errors AND no warnings — the
   * baseline every rejection test above is one flip away from.
   */
  public function testCleanMinimalSectionPasses(): void {
    $verdict = AdmissionGate::check([self::def('promo', self::section())])['promo'];
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
    $verdict = AdmissionGate::check([self::def('promo', $atelier)])['promo'];
    $this->assertSame([], $verdict['errors']);
    $this->assertStringContainsString('no "examples" declared', implode(' ', $verdict['warnings']));

    $builtin = AdmissionGate::check([self::def('promo', $atelier, [], 'aincient_pages')])['promo'];
    $this->assertSame([], $builtin['warnings'], 'Built-ins are exempt from the examples warning.');
  }

  /**
   * The 28 built-ins are the gate's permanent fixtures (§3.3): the REAL
   * shipped .component.yml files pass with zero rejections and zero warnings.
   * If this fails, we broke our own contract before any client could.
   */
  public function testRealShippedComponentsPassTheGate(): void {
    $definitions = [];
    foreach (glob(dirname(__DIR__, 3) . '/components/*/*.component.yml') as $file) {
      $name = basename(dirname($file));
      $definitions['aincient_pages:' . $name] = Yaml::parseFile($file) + [
        'provider' => 'aincient_pages',
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
