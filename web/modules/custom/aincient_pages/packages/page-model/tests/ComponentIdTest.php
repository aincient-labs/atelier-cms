<?php

declare(strict_types=1);

namespace Aincient\PageModel\Tests;

use Aincient\PageModel\ComponentId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The 0.30.0 id bridge (DECISIONS 0534): only old built-in ids move.
 */
#[CoversClass(ComponentId::class)]
#[Group('aincient')]
final class ComponentIdTest extends TestCase {

  public static function ids(): array {
    return [
      'old built-in' => ['aincient_pages:hero', 'atelier:hero'],
      'hyphenated' => ['aincient_pages:site-header', 'atelier:site-header'],
      'already new' => ['atelier:hero', NULL],
      'pack id' => ['acme_pack:hero', NULL],
      'bare name' => ['hero', NULL],
      'empty name' => ['aincient_pages:', NULL],
      'prefix lookalike' => ['aincient_pages_extra:hero', NULL],
    ];
  }

  #[DataProvider('ids')]
  public function testBridge(string $id, ?string $expected): void {
    $this->assertSame($expected, ComponentId::bridge($id));
  }

  private const PROVIDERS = ['hero' => 'atelier', 'markdown' => 'atelier', 'deal' => 'acme_pack'];

  public static function qualifyCases(): array {
    return [
      'built-in' => ['hero', 'atelier:hero'],
      'pack' => ['deal', 'acme_pack:deal'],
      'unknown' => ['ghost', 'unresolved:ghost'],
      'already full' => ['acme_pack:deal', 'acme_pack:deal'],
      'old built-in id' => ['aincient_pages:hero', 'atelier:hero'],
      'empty' => ['', ''],
    ];
  }

  #[DataProvider('qualifyCases')]
  public function testQualify(string $name, string $expected): void {
    $this->assertSame($expected, ComponentId::qualify($name, self::PROVIDERS));
  }

  public function testShort(): void {
    $this->assertSame('hero', ComponentId::short('atelier:hero'));
    $this->assertSame('hero', ComponentId::short('hero'));
    $this->assertSame('ghost', ComponentId::short('unresolved:ghost'));
  }

  /**
   * Known paths only: a prop that equals a component name stays as it is,
   * and qualify then shorten gives back the original layers.
   */
  public function testLayersRoundTrip(): void {
    $structure = ['type' => 'landing', 'slots' => [
      ['id' => 'a', 'component' => 'hero', 'variant' => 'hero'],
      ['id' => 'b', 'component' => 'accordion'],
      'junk',
    ]];
    $content = ['title' => 'hero', 'slots' => [
      'a' => ['heading' => 'hero'],
      'b' => ['panels' => [['label' => 'x', 'blocks' => [['component' => 'markdown', 'props' => ['markdown' => 'deal']], 'junk']]]],
    ]];
    $qs = ComponentId::qualifyStructure($structure, self::PROVIDERS);
    $qc = ComponentId::qualifyContent($content, self::PROVIDERS);
    $this->assertSame('atelier:hero', $qs['slots'][0]['component']);
    $this->assertSame('hero', $qs['slots'][0]['variant']);
    $this->assertSame('unresolved:accordion', $qs['slots'][1]['component']);
    $this->assertSame('atelier:markdown', $qc['slots']['b']['panels'][0]['blocks'][0]['component']);
    $this->assertSame('deal', $qc['slots']['b']['panels'][0]['blocks'][0]['props']['markdown']);
    $this->assertSame('hero', $qc['title']);
    $this->assertSame($structure, ComponentId::shortenStructure($qs));
    $this->assertSame($content, ComponentId::shortenContent($qc));
  }

  public function testRecipeLayersAreUntouched(): void {
    $this->assertSame(['type' => 'blog'], ComponentId::qualifyStructure(['type' => 'blog'], self::PROVIDERS));
    $blog = ['title' => 'hero', 'body_md' => 'hero'];
    $this->assertSame($blog, ComponentId::qualifyContent($blog, self::PROVIDERS));
  }

  public function testConstraintRoundTrip(): void {
    $constraint = [
      'components' => ['deal', 'ghost'],
      'tones' => ['inverted'],
      'variants' => ['hero' => ['split']],
      'component_tones' => ['markdown' => ['brand']],
      'overrides_off' => ['atelier:hero'],
    ];
    $stored = ComponentId::qualifyConstraint($constraint, self::PROVIDERS);
    $this->assertSame(['acme_pack:deal', 'unresolved:ghost'], $stored['components']);
    $this->assertSame(['inverted'], $stored['tones'], 'Tones are not component names.');
    $this->assertSame(['atelier:hero' => ['split']], $stored['variants']);
    $this->assertSame(['atelier:markdown' => ['brand']], $stored['component_tones']);
    $this->assertSame(['atelier:hero'], $stored['overrides_off']);
    $this->assertSame($constraint, ComponentId::shortenConstraint($stored));
  }

  public function testKindRoundTripAndBareNames(): void {
    $kind = [
      'id' => 'promo',
      'components' => ['hero' => ['variants' => ['split']], 'deal' => []],
      'opener' => 'deal',
      'limits' => ['hero' => 1],
      'removed' => ['markdown'],
    ];
    $this->assertSame(['hero', 'deal', 'markdown'], ComponentId::bareNamesInKind($kind));
    $stored = ComponentId::qualifyKind($kind, self::PROVIDERS);
    $this->assertSame(['atelier:hero', 'acme_pack:deal'], array_keys($stored['components']));
    $this->assertSame('acme_pack:deal', $stored['opener']);
    $this->assertSame(['atelier:hero' => 1], $stored['limits']);
    $this->assertSame(['atelier:markdown'], $stored['removed']);
    $this->assertSame('promo', $stored['id']);
    $this->assertSame([], ComponentId::bareNamesInKind($stored));
    $this->assertSame($kind, ComponentId::shortenKind($stored));
    $this->assertSame(['opener' => ''], ComponentId::qualifyKind(['opener' => ''], self::PROVIDERS), 'No opener stays no opener.');
  }

}
