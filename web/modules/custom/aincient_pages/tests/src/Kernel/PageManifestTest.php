<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Kernel;

use Drupal\aincient_pages\Controller\PageController;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * The per-kind studio manifest (plans/byo-components.md W6): the single source
 * the TS studio editors render from is kind-parameterized, carries the kind
 * list for the create-page picker, and stamps each entry with its icon, tier
 * and PROVENANCE (which module contributed it).
 *
 * @group aincient
 */
#[RunTestsInSeparateProcesses]
final class PageManifestTest extends KernelTestBase {

  protected static $modules = [
    'system',
    'user',
    'workflows', 'content_moderation', 'aincient_core', 'aincient_pages',
    'atelier_test_pack',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['workflows', 'content_moderation', 'aincient_pages']);
  }

  private function manifest(array $query = []): array {
    $response = PageController::create($this->container)->manifest(new Request($query));
    return json_decode((string) $response->getContent(), TRUE);
  }

  /**
   * The default (no kind) manifest is the landing palette with kind metadata.
   */
  public function testDefaultManifestIsLandingWithKindList(): void {
    $manifest = $this->manifest();
    $this->assertSame('landing', $manifest['kind']);
    $this->assertSame(['blog', 'landing'], array_keys($manifest['kinds']));
    $this->assertSame('Landing page', $manifest['kinds']['landing']['label']);
    $this->assertSame('recipe', $manifest['kinds']['blog']['mode']);
  }

  /**
   * Entries carry icon + tier + provider — the studio's glyphs and the
   * picker's provenance grouping come from here, not a hardcoded map.
   */
  public function testEntriesCarryIconTierAndProvenance(): void {
    $manifest = $this->manifest();
    $byName = array_column($manifest['sections'], NULL, 'component');
    $this->assertSame('◆', $byName['hero']['icon']);
    $this->assertSame('section', $byName['hero']['tier']);
    $this->assertSame('aincient_pages', $byName['hero']['provider']);
    $this->assertSame('atelier_test_pack', $byName['spotlight']['provider'], 'A pack component declares its provenance.');
  }

  /**
   * An unknown kind degrades to landing — never a 500, never an empty editor.
   */
  public function testUnknownKindDegradesToLanding(): void {
    $manifest = $this->manifest(['kind' => 'nonsense']);
    $this->assertSame('landing', $manifest['kind']);
    $this->assertNotEmpty($manifest['sections']);
  }

  /**
   * A kind's narrowing reaches the manifest: the studio only offers what the
   * kind's effective palette holds.
   */
  public function testKindNarrowingReachesTheManifest(): void {
    $this->container->get('entity_type.manager')->getStorage('page_kind')
      ->load('landing')
      ->set('components', ['hero' => [], 'cta' => []])
      ->save();
    $this->container->get('aincient_pages.catalog')->reset();
    $manifest = $this->manifest(['kind' => 'landing']);
    $this->assertSame(['hero', 'cta'], array_column($manifest['sections'], 'component'));
    $this->assertSame([], $manifest['layout']);
  }

}
