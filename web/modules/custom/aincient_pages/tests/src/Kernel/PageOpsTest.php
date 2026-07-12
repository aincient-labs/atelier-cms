<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Kernel;

use Drupal\aincient_pages\PageStore;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the page-DSL ops (PageStore::applyOps) and the studio update path.
 *
 * applyOps is the page agent's edit grammar — the parallel of the brand agent's
 * per-token diff. These cover the happy paths and every reject path (so the
 * agent gets actionable feedback rather than a silently broken page).
 *
 * @group aincient
 */
#[RunTestsInSeparateProcesses]
final class PageOpsTest extends KernelTestBase {

  use EditorialWorkflowTestTrait;

  protected static $modules = ['system', 'user', 'field', 'text', 'node', 'workflows', 'content_moderation', 'aincient_pages'];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('content_moderation_state');
    // update() re-saves a node, which re-acquires node access grants
    // (delete-then-insert) — that needs the node_access table.
    $this->installSchema('node', ['node_access']);

    NodeType::create(['type' => 'aincient_page', 'name' => 'AIncient page', 'new_revision' => TRUE])->save();
    foreach (['field_page_structure', 'field_page_content'] as $name) {
      FieldStorageConfig::create([
        'field_name' => $name,
        'entity_type' => 'node',
        'type' => 'string_long',
        'translatable' => TRUE,
      ])->save();
      FieldConfig::create([
        'field_name' => $name,
        'entity_type' => 'node',
        'bundle' => 'aincient_page',
        'label' => $name,
        'translatable' => TRUE,
      ])->save();
    }
    $this->setUpEditorialWorkflow(['aincient_page']);
  }

  private function store(): PageStore {
    return $this->container->get('aincient_pages.store');
  }

  public function testFreshPageBuiltFromSetMetaAndAddSections(): void {
    // The "make me a page" first turn: one batch composes a whole landing page.
    $result = $this->store()->applyOps([], [
      ['op' => 'set_meta', 'type' => 'landing', 'title' => 'Lumen'],
      ['op' => 'add_section', 'component' => 'hero', 'props' => ['heading' => 'Hi', 'variant' => 'split']],
      ['op' => 'add_section', 'component' => 'features', 'props' => ['columns' => '2']],
      ['op' => 'add_section', 'component' => 'cta', 'props' => ['tone' => 'brand']],
    ]);

    $this->assertSame([], $result['rejected']);
    $schema = $result['schema'];
    // Pages carry no preset/colour scheme — only type/title/sections.
    $this->assertArrayNotHasKey('preset', $schema);
    $this->assertSame('Lumen', $schema['title']);
    $this->assertSame(['hero', 'features', 'cta'], array_column($schema['sections'], 'component'));
    // validate() ran: features columns coerced to int.
    $this->assertSame(2, $schema['sections'][1]['props']['columns']);
    $this->assertSame('split', $schema['sections'][0]['props']['variant']);
  }

  public function testAddSectionAfterIndexInserts(): void {
    $base = [
      'type' => 'landing',
      'sections' => [
        ['component' => 'hero', 'props' => ['variant' => 'centered']],
        ['component' => 'cta', 'props' => []],
      ],
    ];
    $result = $this->store()->applyOps($base, [
      ['op' => 'add_section', 'after' => 0, 'component' => 'stats', 'props' => []],
    ]);
    $this->assertSame([], $result['rejected']);
    $this->assertSame(['hero', 'stats', 'cta'], array_column($result['schema']['sections'], 'component'));
  }

  public function testUpdateSectionMergesPropsAndNullUnsets(): void {
    $base = [
      'type' => 'landing',
      'sections' => [['component' => 'hero', 'props' => ['variant' => 'centered', 'heading' => 'Old', 'eyebrow' => 'kicker']]],
    ];
    $result = $this->store()->applyOps($base, [
      ['op' => 'update_section', 'index' => 0, 'props' => ['heading' => 'New', 'eyebrow' => NULL]],
    ]);
    $this->assertSame([], $result['rejected']);
    $props = $result['schema']['sections'][0]['props'];
    $this->assertSame('New', $props['heading']);
    $this->assertSame('centered', $props['variant']);
    $this->assertArrayNotHasKey('eyebrow', $props);
  }

  public function testRemoveAndReorderSections(): void {
    $base = [
      'type' => 'landing',
      'sections' => [
        ['component' => 'hero', 'props' => ['variant' => 'centered']],
        ['component' => 'stats', 'props' => []],
        ['component' => 'cta', 'props' => []],
      ],
    ];
    $removed = $this->store()->applyOps($base, [['op' => 'remove_section', 'index' => 1]]);
    $this->assertSame(['hero', 'cta'], array_column($removed['schema']['sections'], 'component'));

    $reordered = $this->store()->applyOps($base, [['op' => 'reorder', 'order' => [2, 0, 1]]]);
    $this->assertSame([], $reordered['rejected']);
    $this->assertSame(['cta', 'hero', 'stats'], array_column($reordered['schema']['sections'], 'component'));
  }

  public function testBadOpsAreRejectedNotFatal(): void {
    $base = ['type' => 'landing', 'sections' => [['component' => 'hero', 'props' => ['variant' => 'centered']]]];
    $result = $this->store()->applyOps($base, [
      ['op' => 'add_section', 'component' => 'malware'],
      ['op' => 'update_section', 'index' => 9, 'props' => ['heading' => 'x']],
      ['op' => 'remove_section', 'index' => 5],
      ['op' => 'reorder', 'order' => [0, 0]],
      ['op' => 'frobnicate'],
      // A valid op still applies despite the bad ones around it.
      ['op' => 'update_section', 'index' => 0, 'props' => ['heading' => 'Kept']],
    ]);

    $this->assertCount(5, $result['rejected']);
    $this->assertSame(['add_section', 'update_section', 'remove_section', 'reorder', 'frobnicate'], array_column($result['rejected'], 'op'));
    // The lone good op landed; the page is intact.
    $this->assertCount(1, $result['schema']['sections']);
    $this->assertSame('Kept', $result['schema']['sections'][0]['props']['heading']);
  }

  public function testEverySectionGetsAStableId(): void {
    // validate() (via applyOps) stamps a unique slot id on every section, and
    // re-running ops PRESERVES it — slot identity must survive a round-trip so
    // the content overlay + id-addressed ops keep pointing at the same slot.
    $built = $this->store()->applyOps([], [
      ['op' => 'add_section', 'component' => 'hero', 'props' => ['variant' => 'centered']],
      ['op' => 'add_section', 'component' => 'cta', 'props' => []],
    ])['schema'];

    $ids = array_column($built['sections'], 'id');
    $this->assertCount(2, array_filter($ids, 'is_string'));
    $this->assertNotSame($ids[0], $ids[1], 'slot ids are unique within a page');

    // Feed the validated schema straight back through a no-op batch: ids hold.
    $again = $this->store()->applyOps($built, [])['schema'];
    $this->assertSame($ids, array_column($again['sections'], 'id'));
  }

  public function testOpsTargetSectionsById(): void {
    $built = $this->store()->applyOps([], [
      ['op' => 'add_section', 'component' => 'hero', 'props' => ['variant' => 'centered']],
      ['op' => 'add_section', 'component' => 'stats', 'props' => []],
      ['op' => 'add_section', 'component' => 'cta', 'props' => []],
    ])['schema'];
    [$hero, $stats, $cta] = array_column($built['sections'], 'id');

    // update + remove + reorder, all addressed by id — and reorder by ids first
    // so update/remove resolve against the moved positions (proving id beats
    // index: the hero is no longer at index 0 here).
    $result = $this->store()->applyOps($built, [
      ['op' => 'reorder', 'order' => [$cta, $hero, $stats]],
      ['op' => 'update_section', 'id' => $hero, 'props' => ['heading' => 'By id']],
      ['op' => 'remove_section', 'id' => $stats],
    ]);
    $this->assertSame([], $result['rejected']);

    $sections = $result['schema']['sections'];
    $this->assertSame(['cta', 'hero'], array_column($sections, 'component'));
    // The hero kept its id and got the new heading despite moving position.
    $heroNow = $sections[1];
    $this->assertSame($hero, $heroNow['id']);
    $this->assertSame('By id', $heroNow['props']['heading']);
  }

  public function testAddSectionAfterById(): void {
    $built = $this->store()->applyOps([], [
      ['op' => 'add_section', 'component' => 'hero', 'props' => ['variant' => 'centered']],
      ['op' => 'add_section', 'component' => 'cta', 'props' => []],
    ])['schema'];
    $heroId = $built['sections'][0]['id'];

    $result = $this->store()->applyOps($built, [
      ['op' => 'add_section', 'after' => $heroId, 'component' => 'stats', 'props' => []],
    ]);
    $this->assertSame([], $result['rejected']);
    $this->assertSame(['hero', 'stats', 'cta'], array_column($result['schema']['sections'], 'component'));
  }

  public function testUnknownIdIsRejectedNotFatal(): void {
    $built = $this->store()->applyOps([], [
      ['op' => 'add_section', 'component' => 'hero', 'props' => ['variant' => 'centered']],
    ])['schema'];
    $result = $this->store()->applyOps($built, [
      ['op' => 'update_section', 'id' => 'nope1234', 'props' => ['heading' => 'x']],
      ['op' => 'update_section', 'id' => $built['sections'][0]['id'], 'props' => ['heading' => 'Kept']],
    ]);
    $this->assertCount(1, $result['rejected']);
    $this->assertSame('update_section', $result['rejected'][0]['op']);
    $this->assertSame('Kept', $result['schema']['sections'][0]['props']['heading']);
  }

  public function testSetMetaSetsMergesAndClearsSeoOverrides(): void {
    // set_meta carries SEO overrides flat alongside title. A first op sets the
    // page title + a description + canonical; a second merges an OG tag on top
    // (leaving the earlier ones intact); a third clears one back to the default.
    $built = $this->store()->applyOps([], [
      [
        'op' => 'set_meta',
        'title' => 'Lumen',
        'description' => 'A calm, modern landing page for the Lumen product line and its makers.',
        'canonical_url' => 'https://example.com/lumen',
      ],
    ])['schema'];
    $this->assertSame('Lumen', $built['title']);
    $this->assertSame('https://example.com/lumen', $built['meta']['canonical_url']);
    $this->assertArrayHasKey('description', $built['meta']);
    // Unknown / unset meta keys never appear.
    $this->assertArrayNotHasKey('og_image', $built['meta']);

    $merged = $this->store()->applyOps($built, [
      ['op' => 'set_meta', 'og_title' => 'Lumen — modern light'],
    ])['schema'];
    // The new OG title joined the block; the earlier overrides survived.
    $this->assertSame('Lumen — modern light', $merged['meta']['og_title']);
    $this->assertSame('https://example.com/lumen', $merged['meta']['canonical_url']);

    // A blank value clears that override (back to the site default) — and when
    // the last override goes, the whole meta block drops off the schema.
    $cleared = $this->store()->applyOps($merged, [
      ['op' => 'set_meta', 'og_title' => '', 'canonical_url' => '', 'description' => ''],
    ])['schema'];
    $this->assertArrayNotHasKey('meta', $cleared);
  }

  public function testOgImageAcceptsATokenOrUrlAndDropsJunk(): void {
    // og_image is an image: like the teaser it takes a media:<id> reference token
    // (resolved to an absolute URL at render), and — because crawlers read the tag
    // directly — it also accepts a raw URL. Anything else is dropped.
    $token = $this->store()->applyOps([], [
      ['op' => 'set_meta', 'og_image' => 'media:42'],
    ])['schema'];
    $this->assertSame('media:42', $token['meta']['og_image']);

    $url = $this->store()->applyOps([], [
      ['op' => 'set_meta', 'og_image' => 'https://example.com/share.jpg'],
    ])['schema'];
    $this->assertSame('https://example.com/share.jpg', $url['meta']['og_image']);

    // A bare word is neither a token nor a URL → dropped (no meta block at all).
    $junk = $this->store()->applyOps([], [
      ['op' => 'set_meta', 'og_image' => 'not-an-image'],
    ])['schema'];
    $this->assertArrayNotHasKey('meta', $junk);
  }

  public function testSetTeaserSetsMergesAndClearsTeaserBlock(): void {
    // set_teaser stages the teaser card fields flat on the op, parallel to
    // set_meta and independent of it. A first op sets title + description; a
    // second merges an image token on top (leaving the earlier fields intact);
    // a third clears one; clearing the last drops the whole block.
    $built = $this->store()->applyOps([], [
      [
        'op' => 'set_teaser',
        'title' => 'Meet Lumen',
        'description' => 'A short card summary for where the page is referenced.',
      ],
    ])['schema'];
    $this->assertSame('Meet Lumen', $built['teaser']['title']);
    $this->assertArrayHasKey('description', $built['teaser']);
    // The teaser is not conflated with the SEO/meta block.
    $this->assertArrayNotHasKey('meta', $built);

    $merged = $this->store()->applyOps($built, [
      ['op' => 'set_teaser', 'image' => 'media:12'],
    ])['schema'];
    $this->assertSame('media:12', $merged['teaser']['image']);
    $this->assertSame('Meet Lumen', $merged['teaser']['title']);

    // A non-media-token image is rejected by validate()'s clamp (never reaches
    // the block), while the other fields survive.
    $badImage = $this->store()->applyOps($merged, [
      ['op' => 'set_teaser', 'image' => 'https://evil.example/x.png'],
    ])['schema'];
    $this->assertArrayNotHasKey('image', $badImage['teaser']);
    $this->assertSame('Meet Lumen', $badImage['teaser']['title']);

    // Blanking every field drops the whole teaser block off the schema.
    $cleared = $this->store()->applyOps($merged, [
      ['op' => 'set_teaser', 'title' => '', 'description' => '', 'image' => ''],
    ])['schema'];
    $this->assertArrayNotHasKey('teaser', $cleared);
  }

  public function testUpdateRevisesExistingNode(): void {
    $id = $this->store()->store(['type' => 'landing', 'title' => 'V1', 'sections' => []]);
    $ok = $this->store()->update($id, ['type' => 'landing', 'title' => 'V2', 'sections' => [['component' => 'hero', 'props' => ['variant' => 'centered']]]]);
    $this->assertTrue($ok);
    $loaded = $this->store()->load($id);
    $this->assertSame('V2', $loaded['title']);
    $this->assertCount(1, $loaded['sections']);
    // A non-existent id is a no-op failure, not a fatal.
    $this->assertFalse($this->store()->update('999999', ['type' => 'landing']));
  }

}
