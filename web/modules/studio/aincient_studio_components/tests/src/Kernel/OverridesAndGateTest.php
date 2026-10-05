<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_studio_components\Kernel;

use Drupal\aincient_studio_components\Controller\ComponentsPreviewController;
use Drupal\aincient_studio_components\Controller\ConstraintController;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Pack overrides in the Components rail, and the gate's "Can't be used" group
 * (DECISIONS 0455, P3/P4a/P4b).
 *
 * `atelier_test_override_pack` replaces aincient_pages:hero. The manifest must
 * say so per entry, the owner's "Use original instead" (`overrides_off`) must
 * store only real overrides and re-render the site (the `rendered` tag) only
 * when it changes, and the studio preview's `original: true` must reach the
 * original. `blocked()` / `plainReason()` are pure: the owner reads a plain
 * sentence, the pack developer the raw gate text.
 *
 * @group aincient
 */
#[RunTestsInSeparateProcesses]
final class OverridesAndGateTest extends KernelTestBase {

  protected static $modules = [
    'system', 'user', 'file', 'image', 'media', 'link', 'menu_link_content',
    'field', 'filter', 'text', 'node', 'key',
    'workflows', 'content_moderation', 'aincient_core', 'aincient_pages',
    'aincient_chat', 'aincient_studio_components', 'atelier_test_override_pack',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installConfig(['system', 'workflows', 'content_moderation', 'aincient_pages']);
    // The page pipeline's collection lookup needs the page bundle + its type
    // field (BuiltinExamplesTest builds the same by hand).
    if (!NodeType::load('aincient_page')) {
      NodeType::create(['type' => 'aincient_page', 'name' => 'Page'])->save();
    }
    FieldStorageConfig::create(['field_name' => 'field_page_type', 'entity_type' => 'node', 'type' => 'string'])->save();
    FieldConfig::create(['field_name' => 'field_page_type', 'entity_type' => 'node', 'bundle' => 'aincient_page', 'label' => 'Type'])->save();
  }

  private function controller(): ConstraintController {
    return ConstraintController::create($this->container);
  }

  private function save(array $payload): array {
    $request = new Request(content: json_encode($payload, JSON_THROW_ON_ERROR));
    $response = $this->controller()->save($request);
    return json_decode((string) $response->getContent(), TRUE) + ['status' => $response->getStatusCode()];
  }

  private function byName(array $state): array {
    return array_column($state['components'], NULL, 'name');
  }

  private function renderPreview(array $payload): string {
    $request = new Request(content: json_encode($payload, JSON_THROW_ON_ERROR));
    $response = ComponentsPreviewController::create($this->container)->render($request);
    $this->assertSame(200, $response->getStatusCode());
    return (string) $response->getContent();
  }

  /**
   * The manifest names the pack replacing the hero; nothing else is replaced.
   */
  public function testManifestReportsTheOverride(): void {
    $state = json_decode((string) $this->controller()->manifest()->getContent(), TRUE);
    $byName = $this->byName($state);

    $hero = $byName['hero'];
    $this->assertIsArray($hero['replaced_by']);
    $this->assertSame('atelier_test_override_pack', $hero['replaced_by']['provider']);
    $this->assertSame('atelier_test_override_pack:hero', $hero['replaced_by']['id']);
    $this->assertSame('Hero (override pack)', $hero['replaced_by']['label']);
    $this->assertFalse($hero['override_off']);
    $this->assertSame('aincient_pages', $hero['provider'], 'The catalog keeps the original\'s metadata.');

    foreach ($byName as $name => $entry) {
      if ($name === 'hero') {
        continue;
      }
      $this->assertNull($entry['replaced_by'], "$name is not overridden.");
      $this->assertFalse($entry['override_off'], $name);
    }
    $this->assertSame([], $state['constraint']['overrides_off']);
    $this->assertSame([], $state['blocked'], 'The fixture pack carries no atelier metadata — nothing is blocked.');
  }

  /**
   * Only a real override can be switched off; the switch reaches negotiation.
   */
  public function testOverridesOffStoresOnlyRealOverrides(): void {
    $result = $this->save(['overrides_off' => ['aincient_pages:hero', 'aincient_pages:cta']]);
    $this->assertSame(200, $result['status']);
    $this->assertSame(['overrides_off'], $result['applied']);
    $this->assertSame(['aincient_pages:hero'], $result['constraint']['overrides_off']);
    $this->assertSame(['aincient_pages:hero'], $this->config('aincient_pages.site_constraint')->get('overrides_off'));

    $byName = $this->byName($result);
    $this->assertTrue($byName['hero']['override_off']);
    $this->assertSame('atelier_test_override_pack', $byName['hero']['replaced_by']['provider'], 'Still reported as overridden, just switched off.');
    $this->assertFalse($byName['cta']['override_off']);

    $this->container->get('Drupal\Core\Theme\ComponentNegotiator')->clearCache();
    $provider = $this->container->get('plugin.manager.sdc')->find('aincient_pages:hero')->getPluginDefinition()['provider'];
    $this->assertSame('aincient_pages', $provider);

    // And switching it back on empties the list.
    $this->assertSame([], $this->save(['overrides_off' => []])['constraint']['overrides_off']);
  }

  /**
   * `rendered` is invalidated only when overrides_off actually changes.
   */
  public function testRenderedTagInvalidatedOnlyOnOverrideChange(): void {
    $cache = $this->container->get('cache.render');

    $cache->set('ain_probe', 'x', -1, ['rendered']);
    $this->save(['tones' => ['inverted']]);
    $this->assertNotFalse($cache->get('ain_probe'), 'A tones-only publish must not flush every rendered page.');

    $this->save(['overrides_off' => ['aincient_pages:hero']]);
    $this->assertFalse($cache->get('ain_probe'), 'Switching an override changes every page\'s rendering.');

    // An unchanged overrides_off (re-publishing the same slice) keeps renders.
    $cache->set('ain_probe', 'x', -1, ['rendered']);
    $this->save(['overrides_off' => ['aincient_pages:hero']]);
    $this->assertNotFalse($cache->get('ain_probe'), 'An unchanged overrides_off must not flush renders.');
  }

  /**
   * The studio preview renders the pack's override by default.
   *
   * One render per test (process): Drupal's TwigEnvironment memoises the
   * template class per component NAME for the life of the process, so a
   * second render of `aincient_pages:hero` in the same process reuses the
   * first one's negotiated template. Compare issues two HTTP requests, so the
   * studio is unaffected; the scoping of withOriginals() itself is pinned at
   * the negotiation level by OverrideNegotiationTest::testWithOriginalsIsScoped.
   */
  public function testPreviewRendersTheOverride(): void {
    $html = $this->renderPreview(['items' => [['component' => 'hero']]]);
    $this->assertStringContainsString('data-atelier-override', $html);
  }

  /**
   * `original: true` renders the built-in, bypassing the override.
   */
  public function testPreviewOriginalBypassesTheOverride(): void {
    $original = $this->renderPreview(['items' => [['component' => 'hero']], 'original' => TRUE]);
    $this->assertStringNotContainsString('data-atelier-override', $original);
    $this->assertStringContainsString('data-ain-sec="hero"', $original);
  }

  /**
   * A refused atelier def becomes one plain-language "Can't be used" row.
   */
  public function testBlockedListsGateRejections(): void {
    $definitions = [
      'acme_pack:spotlight' => [
        'machineName' => 'spotlight',
        'provider' => 'acme_pack',
        'name' => 'Spotlight',
        // No `api`: the gate refuses an unknown/missing contract version.
        'thirdPartySettings' => ['atelier' => [
          'tier' => 'section',
          'use' => 'Highlight one thing.',
          'props' => ['heading' => ''],
          'examples' => [['props' => ['heading' => 'Hi']]],
        ]],
      ],
      'acme_pack:ribbon' => [
        'machineName' => 'ribbon',
        'provider' => 'acme_pack',
        'name' => 'Ribbon',
        'thirdPartySettings' => ['atelier' => [
          'api' => 1,
          'tier' => 'section',
          'use' => 'A thin announcement strip.',
          'props' => ['heading' => ''],
          'examples' => [['props' => ['heading' => 'Hi']]],
        ]],
      ],
      // Not atelier-carrying: never listed, whatever it looks like.
      'acme_pack:raw' => ['machineName' => 'raw', 'provider' => 'acme_pack', 'name' => 'Raw'],
    ];

    $blocked = ConstraintController::blocked($definitions);
    $this->assertCount(1, $blocked);
    $row = $blocked[0];
    $this->assertSame('spotlight', $row['name']);
    $this->assertSame('acme_pack', $row['provider']);
    $this->assertSame('Spotlight', $row['label']);
    $this->assertSame('Made for a different version of Atelier.', $row['reason']);
    $this->assertNotSame('', $row['detail']);
    $this->assertStringContainsString('atelier api version', $row['detail']);
  }

  /**
   * A pack component reusing a built-in's name is blocked — the built-in it
   * collides with is NOT.
   */
  public function testBlockedCollisionDoesNotBlameTheBuiltIn(): void {
    $atelier = static fn(): array => [
      'api' => 1,
      'tier' => 'section',
      'use' => 'Top-of-page hero.',
      'props' => ['heading' => ''],
      'examples' => [['props' => ['heading' => 'Hi']]],
    ];
    $definitions = [
      'aincient_pages:hero' => ['machineName' => 'hero', 'provider' => 'aincient_pages', 'name' => 'Hero', 'thirdPartySettings' => ['atelier' => $atelier()]],
      'acme_pack:hero' => ['machineName' => 'hero', 'provider' => 'acme_pack', 'name' => 'Acme hero', 'thirdPartySettings' => ['atelier' => $atelier()]],
    ];
    $blocked = ConstraintController::blocked($definitions);
    $this->assertSame(['acme_pack'], array_column($blocked, 'provider'));
    $this->assertSame('Another component already uses this name.', $blocked[0]['reason'] ?? NULL);
  }

  /**
   * Each AdmissionGate error prefix maps to its owner-facing sentence.
   */
  public function testPlainReasonMapsEveryGateError(): void {
    $cases = [
      'unknown or missing atelier api version (NULL) — this build understands api: 1.' => 'Made for a different version of Atelier.',
      'unknown tier "widget" (section|layout|reference|content|chrome).' => 'Its pack declares a kind of component Atelier does not know.',
      'name collides with "aincient_pages:hero" — component names are globally unique (one word, one concept).' => 'Another component already uses this name.',
      '"grid" is a reserved layout word — it cannot name a section component.' => 'Its name is reserved by Atelier.',
      'prop "price" is not in the locked vocabulary and declares no pack-local meaning (thirdPartySettings.atelier.prop_vocab.price).' => 'It has a field Atelier cannot fill.',
      'variant "wide" is hinted to the agent but absent from the SDC schema enum (centered|split) — the clamp would 500 the render.' => 'One of its variants is declared incorrectly.',
      'SDC slot "photo" is an image slot but declares no image_props.photo.view_mode — the renderer could never fill it.' => 'It has an image area Atelier cannot fill.',
      'examples[0] must be a mapping with a "props" map (story-shaped: name?, props, slots?).' => 'Its examples are malformed.',
      'stylesheet "/etc/x.css" must be a module-relative .css path (no leading /, no ..).' => 'Its stylesheet path is not allowed.',
      'missing "use" — the one-line selection hint the agent picks by is mandatory for a placeable component.' => 'It does not meet the rules for Atelier components.',
      'something the gate never says' => 'It does not meet the rules for Atelier components.',
    ];
    foreach ($cases as $error => $sentence) {
      $this->assertSame($sentence, ConstraintController::plainReason($error), $error);
    }
  }

}
