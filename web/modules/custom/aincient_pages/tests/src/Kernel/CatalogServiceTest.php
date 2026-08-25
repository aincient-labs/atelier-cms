<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Kernel;

use Drupal\aincient_pages\Catalog\AtelierComponentCatalog;
use Drupal\aincient_pages\Catalog\ComponentCatalogInterface;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the catalog service: live SDC discovery, the shipped kinds, the
 * unknown-kind degrade, and config cache-tag invalidation.
 *
 * @group aincient
 */
#[RunTestsInSeparateProcesses]
final class CatalogServiceTest extends KernelTestBase {

  protected static $modules = ['system', 'workflows', 'content_moderation', 'aincient_core', 'aincient_pages'];

  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['workflows', 'content_moderation', 'aincient_pages']);
  }

  private function catalog(): ComponentCatalogInterface {
    return $this->container->get('aincient_pages.catalog');
  }

  /**
   * A FRESH service instance (empty per-request memo) so a second lookup goes
   * back through cache.discovery — the seam the invalidation test needs.
   */
  private function freshCatalog(): ComponentCatalogInterface {
    return new AtelierComponentCatalog(
      $this->container->get('plugin.manager.sdc'),
      $this->container->get('entity_type.manager'),
      $this->container->get('config.factory'),
      $this->container->get('cache.discovery'),
    );
  }

  /**
   * The landing kind compiles a composition catalog over the real discovered
   * palette, with no warnings.
   */
  public function testLandingCatalog(): void {
    $catalog = $this->catalog()->for('landing');
    $this->assertSame('landing', $catalog->kind());
    $this->assertTrue($catalog->isComposition());
    foreach (['hero', 'grid', 'embed', 'block', 'collection'] as $name) {
      $this->assertContains($name, $catalog->placeableNames());
    }
    $this->assertSame([], $catalog->warnings());
  }

  /**
   * The blog kind is a locked recipe and a collection source.
   */
  public function testBlogCatalog(): void {
    $catalog = $this->catalog()->for('blog');
    $this->assertSame('blog', $catalog->kind());
    $this->assertSame('recipe', $catalog->mode());
    $this->assertFalse($catalog->isComposition());
    $this->assertTrue($catalog->isCollectionSource());
  }

  /**
   * An unknown kind id degrades to landing semantics — never fatal.
   */
  public function testUnknownKindDegradesToLanding(): void {
    $catalog = $this->catalog()->for('nonsense');
    $this->assertSame('landing', $catalog->kind());
    $this->assertTrue($catalog->isComposition());
    $this->assertContains('hero', $catalog->placeableNames());
  }

  /**
   * kinds() lists the two shipped kinds with labels, hints and modes.
   */
  public function testKindsListing(): void {
    $kinds = $this->catalog()->kinds();
    $this->assertSame(['blog', 'landing'], array_keys($kinds));
    $this->assertSame('Landing page', $kinds['landing']['label']);
    $this->assertSame('composition', $kinds['landing']['mode']);
    $this->assertSame('Blog post', $kinds['blog']['label']);
    $this->assertSame('recipe', $kinds['blog']['mode']);
    $this->assertNotSame('', $kinds['landing']['hint']);
    $this->assertNotSame('', $kinds['blog']['hint']);
  }

  /**
   * Saving a kind entity invalidates its cached catalog (config cache tag):
   * a fresh service instance recompiles rather than serving the stale hit.
   */
  public function testKindSaveInvalidatesCachedCatalog(): void {
    $first = $this->freshCatalog()->for('landing');
    $this->assertNull($first->opener());

    $kind = $this->container->get('entity_type.manager')
      ->getStorage('page_kind')
      ->load('landing');
    $kind->set('opener', 'hero');
    $kind->save();

    // A fresh instance has no per-request memo, so this proves the
    // cache.discovery entry was invalidated by the config cache tag.
    $second = $this->freshCatalog()->for('landing');
    $this->assertSame('hero', $second->opener());
  }

  /**
   * Phase 5: `page_kinds()` renders the PAGE KINDS prompt block from the kind
   * registry — a third kind appears the moment its entity exists, with regime
   * prose derived from its mode, so the prompt never hardcodes "landing|blog".
   */
  public function testPageKindsPromptBlockIsGenerated(): void {
    $extension = new \Drupal\aincient_pages\Twig\ComponentCatalogExtension($this->catalog());
    $block = $extension->kinds();
    $this->assertStringContainsString('PAGE KINDS', $block);
    $this->assertStringContainsString('- landing (Landing page) — open composition', $block);
    $this->assertStringContainsString('- blog (Blog post) — a LOCKED recipe', $block);

    $this->container->get('entity_type.manager')
      ->getStorage('page_kind')
      ->create([
        'id' => 'campaign',
        'label' => 'Campaign page',
        'mode' => 'composition',
        'hint' => 'A narrow promo page.',
      ])
      ->save();
    $block = (new \Drupal\aincient_pages\Twig\ComponentCatalogExtension($this->freshCatalog()))->kinds();
    $this->assertStringContainsString('- campaign (Campaign page) — open composition', $block);
    $this->assertStringContainsString('A narrow promo page.', $block);
  }

}
