<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Page kinds and the site constraint hold full ids (DECISIONS 0536, D4/D5).
 *
 * The stored config is `provider:name`; the compiler, the studio and KindCheck
 * still see short names, so a catalog compiled from full ids is the one a
 * catalog compiled from bare names was (failure modes S3: the agent may place
 * exactly what it could before). A pack's config/install written before
 * 0.30.0 names components bare; installing it stores full ids — its own
 * components included — and warns.
 *
 * @group aincient
 */
#[RunTestsInSeparateProcesses]
final class StoredConfigComponentIdsTest extends KernelTestBase {

  protected static $modules = [
    'system', 'user', 'field', 'text', 'node', 'workflows', 'content_moderation', 'dblog',
    'aincient_core', 'atelier', 'aincient_pages',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('dblog', ['watchdog']);
  }

  private function snapshot(string $kind): array {
    $catalog = $this->container->get('aincient_pages.catalog');
    $catalog->reset();
    $compiled = $catalog->for($kind);
    $names = $compiled->placeableNames();
    sort($names);
    return [
      'placeable' => $names,
      'variants' => $compiled->variants(),
      'tones' => array_map(fn(string $n) => $compiled->tonesFor($n), array_combine($names, $names)),
      'opener' => $compiled->opener(),
      'limits' => $compiled->limits(),
      'warnings' => $compiled->warnings(),
    ];
  }

  public function testFullIdConfigCompilesLikeBareConfig(): void {
    $kind = [
      'id' => 'landing', 'label' => 'Landing', 'mode' => 'composition', 'include_new' => FALSE,
      'components' => ['hero' => ['variants' => ['split']], 'cta' => [], 'newsletter' => []],
      'opener' => 'hero', 'limits' => ['cta' => 1], 'removed' => ['newsletter'],
    ];
    $constraint = ['components' => ['pricing'], 'variants' => ['hero' => ['centered']], 'component_tones' => ['cta' => ['inverted']], 'tones' => []];

    // Bare, as stored before 0.30.0 — written past preSave, straight to config.
    $this->config('aincient_pages.page_kind.landing')->setData($kind)->save();
    $this->config('aincient_pages.site_constraint')->setData($constraint)->save();
    $this->container->get('entity_type.manager')->getStorage('page_kind')->resetCache();
    $bare = $this->snapshot('landing');

    $full = $kind;
    $full['components'] = ['atelier:hero' => ['variants' => ['split']], 'atelier:cta' => [], 'atelier:newsletter' => []];
    $full['opener'] = 'atelier:hero';
    $full['limits'] = ['atelier:cta' => 1];
    $full['removed'] = ['atelier:newsletter'];
    $this->config('aincient_pages.page_kind.landing')->setData($full)->save();
    $this->config('aincient_pages.site_constraint')->setData([
      'components' => ['atelier:pricing'], 'variants' => ['atelier:hero' => ['centered']], 'component_tones' => ['atelier:cta' => ['inverted']], 'tones' => [],
    ])->save();
    $this->container->get('entity_type.manager')->getStorage('page_kind')->resetCache();

    $this->assertSame($bare, $this->snapshot('landing'));
    $this->assertSame(['cta', 'hero'], $bare['placeable'], 'The kind narrowed as written (newsletter denied, pricing off site-wide).');
  }

  public function testAPackKindWithBareNamesIsStoredWithFullIdsAndWarns(): void {
    $this->enableModules(['atelier_test_kind_pack']);
    $this->container->get('config.installer')->installDefaultConfig('module', 'atelier_test_kind_pack');

    $raw = $this->config('aincient_pages.page_kind.atelier_test_kind_pack_promo');
    $this->assertSame(['atelier:hero', 'atelier_test_kind_pack:promo'], array_keys($raw->get('components')));
    $this->assertSame('atelier_test_kind_pack:promo', $raw->get('opener'), "The pack's own component resolves at install.");
    $this->assertSame(['atelier:hero' => 1], $raw->get('limits'));
    $this->assertSame(['atelier:newsletter'], $raw->get('removed'));

    $promo = $this->snapshot('atelier_test_kind_pack_promo');
    $this->assertSame(['hero', 'promo'], $promo['placeable']);
    $this->assertSame('promo', $promo['opener']);
    $this->assertSame(['left'], $promo['variants']['promo']);

    $logged = $this->container->get('database')->select('watchdog', 'w')->fields('w', ['message', 'variables'])->condition('type', 'aincient_pages')->execute()->fetchAll();
    $messages = array_map(fn($row) => strtr($row->message, unserialize($row->variables)), $logged);
    $this->assertNotEmpty(array_filter($messages, fn(string $m) => str_contains($m, 'atelier_test_kind_pack_promo') && str_contains($m, 'pack-validate refuses bare names since Atelier 0.32.0')));
  }

  /**
   * pack-validate rejects the bare kind before anyone installs the pack
   * (since 0.32.0; a warning in 0.30 and 0.31).
   */
  public function testPackValidateRejectsABareKind(): void {
    $this->enableModules(['atelier_test_kind_pack']);
    $report = $this->container->get('aincient_pages.pack_validator')->validate('atelier_test_kind_pack');
    $errors = implode("\n", $report['pack']['errors']);
    $this->assertStringContainsString('config/install/aincient_pages.page_kind.atelier_test_kind_pack_promo.yml names components without their provider (hero, promo, newsletter)', $errors);
    $this->assertSame(1, $report['rejected']);
  }

}
