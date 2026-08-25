<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Kernel;

use Drupal\aincient_pages\Controller\PackDevController;
use Drupal\aincient_pages\Controller\PageSpikeController;
use Drupal\KernelTests\KernelTestBase;
use Symfony\Component\HttpFoundation\Request;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The Phase 2 exit gate (plans/byo-components.md §9): a component PACK — an
 * ordinary module whose SDC carries `thirdPartySettings.atelier` — travels the
 * WHOLE path with zero bespoke wiring: admission gate → discovered catalog →
 * agent manifest → validator → renderer, from its own provider namespace.
 *
 * @group aincient
 */
#[RunTestsInSeparateProcesses]
final class PackEndToEndTest extends KernelTestBase {

  protected static $modules = [
    'system',
    'user',
    'link',
    'menu_link_content',
    'workflows', 'content_moderation', 'aincient_core', 'aincient_pages',
    'atelier_test_pack',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['workflows', 'content_moderation', 'aincient_pages']);
  }

  /**
   * Discovery admits the pack component from its own module.
   */
  public function testPackComponentIsDiscoveredWithItsProvider(): void {
    $catalog = $this->container->get('aincient_pages.catalog')->for('landing');
    $this->assertSame([], $catalog->warnings(), 'The fixture pack passes the admission gate clean.');
    $def = $catalog->placeable('spotlight');
    $this->assertNotNull($def, 'spotlight is placeable.');
    $this->assertSame('atelier_test_pack', $def['provider']);
    $this->assertSame('atelier_test_pack:spotlight', $catalog->pluginId('spotlight'));
    $this->assertSame(['left', 'right'], $catalog->variantsFor('spotlight'));
  }

  /**
   * The agent manifest carries the pack component the moment it is enabled —
   * `use` hint included (its only prior; §5).
   */
  public function testPackComponentReachesTheAgentManifest(): void {
    $manifest = $this->container->get('aincient_pages.catalog')->for('landing')->manifest();
    $this->assertStringContainsString('- spotlight — One product shot beside a single claim.', $manifest);
    $this->assertStringContainsString('variant(left|right)', $manifest);
  }

  /**
   * Phase 5: a PACK component's first declared example rides the manifest as
   * a few-shot fragment (a client's name has no model prior); built-ins stay
   * bare so the prompt budget holds.
   */
  public function testPackExampleIsInlinedAsFewShot(): void {
    $manifest = $this->container->get('aincient_pages.catalog')->for('landing')->manifest();
    $this->assertStringContainsString('e.g. {"component":"spotlight","props":{"variant":"left"', $manifest);
    // Built-ins never carry a few-shot line, whatever fixtures they declare.
    $this->assertStringNotContainsString('e.g. {"component":"hero"', $manifest);
  }

  /**
   * The validator accepts, clamps and stores the pack component like any
   * built-in: unknown variant clamps to the default, undeclared props drop,
   * the pack-local `claim` prop survives.
   */
  public function testValidatorAcceptsAndClampsThePackComponent(): void {
    $store = $this->container->get('aincient_pages.store');
    $result = $store->applyOps(
      ['type' => 'landing', 'title' => 'Fixture', 'sections' => []],
      [['op' => 'add_section', 'component' => 'spotlight', 'props' => [
        'variant' => 'hallucinated',
        'tone' => 'brand',
        'claim' => 'It just works.',
        'bogus' => 'dropped',
      ]]],
    );
    $this->assertSame([], $result['rejected']);
    $section = $result['schema']['sections'][0];
    $this->assertSame('spotlight', $section['component']);
    $this->assertSame('left', $section['props']['variant'], 'Unknown variant clamps to the declared default.');
    $this->assertSame('brand', $section['props']['tone']);
    $this->assertSame('It just works.', $section['props']['claim']);
    $this->assertArrayNotHasKey('bogus', $section['props']);
  }

  /**
   * The renderer renders the pack component from ITS OWN provider namespace.
   */
  public function testPackComponentRendersFromItsOwnModule(): void {
    $catalog = $this->container->get('aincient_pages.catalog')->for('landing');
    $build = [
      '#type' => 'component',
      '#component' => $catalog->pluginId('spotlight'),
      '#props' => [
        'variant' => 'right',
        'tone' => 'muted',
        'heading' => 'Fixture heading',
        'claim' => 'It just works.',
      ],
    ];
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    $this->assertStringContainsString('spotlight--right', $html);
    $this->assertStringContainsString('data-tone="muted"', $html);
    $this->assertStringContainsString('It just works.', $html);
  }

  /**
   * The pack's pre-compiled stylesheet is catalog-declared (W5) — the shell,
   * the studio preview and the static export link it from this one fact.
   */
  public function testPackStylesheetIsCatalogDeclared(): void {
    $sheets = $this->container->get('aincient_pages.catalog')->discovered()->stylesheets();
    $this->assertSame([['provider' => 'atelier_test_pack', 'path' => 'assets/atelier-test-pack.css']], $sheets);
  }

  /**
   * The SHELL links the pack stylesheet after ours — which is also how the
   * studio preview and the static exporter (it follows markup hrefs) get it.
   */
  public function testShellLinksThePackStylesheet(): void {
    $controller = PageSpikeController::create($this->container);
    $html = (string) $controller->renderChrome([], [])->getContent();
    $this->assertMatchesRegularExpression(
      '#<link rel="stylesheet" href="[^"]*aincient-pages\.css[^"]*">\s*<link rel="stylesheet" href="[^"]*atelier_test_pack/assets/atelier-test-pack\.css[^"]*">#',
      $html,
      'The pack stylesheet is linked immediately after the module bundle.',
    );
  }

  /**
   * Governance still narrows a pack component like any other (site constraint
   * removes it; the kind floor never fatals).
   */
  public function testSiteConstraintGovernsThePackComponent(): void {
    $this->config('aincient_pages.site_constraint')->set('components', ['spotlight'])->save();
    $this->container->get('aincient_pages.catalog')->reset();
    $catalog = $this->container->get('aincient_pages.catalog')->for('landing');
    $this->assertNotContains('spotlight', $catalog->placeableNames());
    $this->assertStringNotContainsString('spotlight', $catalog->manifest());
  }


  /**
   * Phase 4: the fixture pack's atelier.pack.yml is found and valid, and the
   * shared PackValidator (drush apv + the dev endpoint, one source) admits
   * the whole pack.
   */
  public function testPackValidatorAcceptsThePackAndItsManifest(): void {
    $report = $this->container->get('aincient_pages.pack_validator')->validate('atelier_test_pack');
    $this->assertSame(0, $report['rejected']);
    $this->assertTrue($report['pack']['found'], 'atelier.pack.yml is read.');
    $this->assertSame([], $report['pack']['errors']);
    $statuses = array_column($report['rows'], 'status', 'component');
    $this->assertSame('OK', $statuses['spotlight']);
  }

  /**
   * Phase 4 dev surface: with dev mode ON, the gallery renders the declared
   * example at three widths and the render endpoint produces the component
   * markup inside the real page CSS; with dev mode OFF the access check
   * refuses. The flag is a Setting (env-only) — never config or state.
   */
  public function testDevGalleryAndRenderEndpoint(): void {
    $this->setSetting('atelier_dev', TRUE);
    $this->assertTrue($this->container->get('aincient_pages.atelier_dev_access')->access()->isAllowed());

    $controller = PackDevController::create($this->container);
    $gallery = (string) $controller->gallery('atelier_test_pack')->getContent();
    $this->assertStringContainsString('spotlight', $gallery);
    $this->assertSame(3, substr_count($gallery, 'atelier/dev/render?component=spotlight&amp;example=0"'), 'The declared example renders at three widths.');
    $this->assertStringContainsString('tone=inverted', $gallery, 'The inverted tone renders too — spotlight declares it.');

    $html = (string) $controller->renderExample(new Request(['component' => 'spotlight']))->getContent();
    $this->assertStringContainsString('The claim, made visible', $html);
    $this->assertStringContainsString('assets/aincient-pages.css', $html, 'The real page bundle is linked.');
    $this->assertStringContainsString('atelier-test-pack.css', $html, 'The pack stylesheet is linked.');

    $this->setSetting('atelier_dev', FALSE);
    $this->assertFalse($this->container->get('aincient_pages.atelier_dev_access')->access()->isAllowed());
  }

  /**
   * The dev catalog + prompt-manifest endpoints project the SAME compiled
   * catalog every consumer reads — the agent's ground truth.
   */
  public function testDevCatalogAndPromptManifestEndpoints(): void {
    $this->setSetting('atelier_dev', TRUE);
    $controller = PackDevController::create($this->container);

    $catalog = json_decode((string) $controller->catalog(new Request(['kind' => 'landing']))->getContent(), TRUE);
    $this->assertSame('landing', $catalog['kind']);
    $this->assertArrayHasKey('spotlight', $catalog['sections']);
    $this->assertSame([['provider' => 'atelier_test_pack', 'path' => 'assets/atelier-test-pack.css']], $catalog['stylesheets']);

    $prompt = json_decode((string) $controller->promptManifest(new Request(['kind' => 'landing']))->getContent(), TRUE);
    $this->assertStringContainsString('spotlight', $prompt['text']);
    $this->assertSame(mb_strlen($prompt['text']), $prompt['chars']);
  }

}
