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
 * Tests the page-schema guardrails — the part that makes agent output safe.
 *
 * @group aincient
 */
#[RunTestsInSeparateProcesses]
final class PageStoreTest extends KernelTestBase {

  use EditorialWorkflowTestTrait;

  protected static $modules = ['system', 'user', 'field', 'text', 'node', 'workflows', 'content_moderation', 'aincient_pages'];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('content_moderation_state');
    // Node grant writes on an update-save (saveDraft's forward revision) hit the
    // node_access table — insert-only tests never do, so install it here.
    $this->installSchema('node', ['node_access']);

    // The aincient_page content type + the split page-schema fields ship as
    // distribution config (config/sync), not module config — so build them here.
    NodeType::create(['type' => 'aincient_page', 'name' => 'AIncient page'])->save();
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
    // Revision co-authors: revisionable, multi-value, non-translatable — the
    // provenance field PageStore stamps with the Studio + Agent per save.
    FieldStorageConfig::create([
      'field_name' => 'field_revision_coauthors',
      'entity_type' => 'node',
      'type' => 'string_long',
      'cardinality' => -1,
      'translatable' => FALSE,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_revision_coauthors',
      'entity_type' => 'node',
      'bundle' => 'aincient_page',
      'label' => 'Revision co-authors',
      'translatable' => FALSE,
    ])->save();

    // Teaser fieldset: the page's presence as a referenced card. title/image are
    // plain strings; description is string_long. All translatable.
    foreach (['field_teaser_title' => 'string', 'field_teaser_description' => 'string_long', 'field_teaser_image' => 'string'] as $name => $type) {
      FieldStorageConfig::create([
        'field_name' => $name,
        'entity_type' => 'node',
        'type' => $type,
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

  public function testLandingDropsUnknownComponentsAndCoercesEnums(): void {
    $clean = $this->store()->validate([
      'type' => 'landing',
      'title' => 'Hi',
      'sections' => [
        ['component' => 'hero', 'props' => ['variant' => 'diagonal', 'tone' => 'glow', 'heading' => 'A']],
        ['component' => 'malware', 'props' => ['heading' => 'evil']],
        ['component' => 'cta', 'props' => ['tone' => 'brand', 'heading' => 'Go']],
      ],
    ]);

    // Unknown component dropped; pages carry no preset/colour scheme.
    $this->assertArrayNotHasKey('preset', $clean);
    $this->assertCount(2, $clean['sections']);
    $this->assertSame('hero', $clean['sections'][0]['component']);
    $this->assertSame('cta', $clean['sections'][1]['component']);
    // Bad hero variant → default 'centered'; bad tone → unset.
    $this->assertSame('centered', $clean['sections'][0]['props']['variant']);
    $this->assertArrayNotHasKey('tone', $clean['sections'][0]['props']);
    // Valid enum kept.
    $this->assertSame('brand', $clean['sections'][1]['props']['tone']);
  }

  public function testHeroVariantDefaultsWhenMissing(): void {
    // hero/variant is a required SDC enum with no default — a missing value
    // would render a 500, so the guardrail must clamp it to a valid variant.
    $clean = $this->store()->validate([
      'type' => 'landing',
      'sections' => [
        ['component' => 'hero', 'props' => ['heading' => 'No variant given']],
        ['component' => 'hero', 'props' => ['variant' => 'split', 'heading' => 'Valid kept']],
      ],
    ]);
    $this->assertSame('centered', $clean['sections'][0]['props']['variant']);
    $this->assertSame('split', $clean['sections'][1]['props']['variant']);
  }

  public function testFeatureGridColumnsCoercedToInteger(): void {
    // SDC types columns as integer; a form select / model emits a string.
    $clean = $this->store()->validate([
      'type' => 'landing',
      'sections' => [
        ['component' => 'features', 'props' => ['columns' => '2']],
        ['component' => 'features', 'props' => ['columns' => '3']],
        ['component' => 'features', 'props' => ['columns' => '9']],
      ],
    ]);
    $this->assertSame(2, $clean['sections'][0]['props']['columns']);
    $this->assertSame(3, $clean['sections'][1]['props']['columns']);
    $this->assertSame(3, $clean['sections'][2]['props']['columns']);
  }

  public function testUnknownTypeFallsBackToLanding(): void {
    $clean = $this->store()->validate(['type' => 'spaceship', 'sections' => []]);
    $this->assertSame('landing', $clean['type']);
  }

  public function testBlogKeepsContentFieldsOnly(): void {
    $clean = $this->store()->validate([
      'type' => 'blog',
      'title' => 'T',
      'lead' => 'L',
      'author' => 'A',
      'body_html' => '<p>hi</p>',
      'sections' => [['component' => 'hero']],
    ]);
    $this->assertSame('blog', $clean['type']);
    $this->assertSame('<p>hi</p>', $clean['body_html']);
    // Blogs are a locked recipe — no sections array.
    $this->assertArrayNotHasKey('sections', $clean);
  }

  public function testBlogBodyHtmlIsSanitised(): void {
    $clean = $this->store()->validate([
      'type' => 'blog',
      'title' => 'T',
      'body_html' => '<p>Safe</p><script>alert(1)</script><h2>Heading</h2>',
    ]);
    $this->assertStringNotContainsString('<script>', $clean['body_html']);
    $this->assertStringContainsString('<p>Safe</p>', $clean['body_html']);
    $this->assertStringContainsString('<h2>Heading</h2>', $clean['body_html']);
  }

  /**
   * Over-encoded HTML entities in plain-text props (and the title) are decoded
   * to raw text on validate, so Twig's output escaping can't double-encode them
   * into a literal "&amp;". Covers a top-level prop AND a nested row field.
   */
  public function testEntitiesDecodedInPlainTextProps(): void {
    $clean = $this->store()->validate([
      'type' => 'landing',
      'title' => 'Ethics &amp; Policy',
      'sections' => [
        [
          'component' => 'content',
          'props' => ['heading' => 'R&amp;D', 'body' => 'A &amp; B &lt; C'],
        ],
        [
          'component' => 'team',
          'props' => ['members' => [['name' => 'X', 'role' => 'AI Ethics &amp; Policy']]],
        ],
      ],
    ]);
    $this->assertSame('Ethics & Policy', $clean['title']);
    $this->assertSame('R&D', $clean['sections'][0]['props']['heading']);
    $this->assertSame('A & B < C', $clean['sections'][0]['props']['body']);
    $this->assertSame('AI Ethics & Policy', $clean['sections'][1]['props']['members'][0]['role']);
  }

  /**
   * Blog body_html is real HTML — its entities are intentional and must survive
   * validate (only the plain-text blog fields are decoded).
   */
  public function testBlogBodyHtmlEntitiesPreserved(): void {
    $clean = $this->store()->validate([
      'type' => 'blog',
      'title' => 'T',
      'author' => 'Jane &amp; Co',
      'body_html' => '<p>Tom &amp; Jerry</p>',
    ]);
    // Plain-text blog field decoded…
    $this->assertSame('Jane & Co', $clean['author']);
    // …but the HTML field keeps its entity (it is rendered raw).
    $this->assertStringContainsString('Tom &amp; Jerry', $clean['body_html']);
  }

  public function testAccordionPanelsValidateBoundedChildBlocks(): void {
    $clean = $this->store()->validate([
      'type' => 'landing',
      'sections' => [
        [
          'component' => 'accordion',
          'props' => [
            'heading' => 'Details',
            'exclusive' => 'yes',
            'panels' => [
              [
                'label' => 'Shipping',
                'open' => 1,
                'blocks' => [
                  // Allowed leaf child — kept, props clamped to its declared set.
                  ['component' => 'markdown', 'props' => ['markdown' => '# Hi', 'tone' => 'bogus', 'rogue' => 'x']],
                  // A container child — dropped (panels are ONE level deep).
                  ['component' => 'grid', 'props' => ['cards' => []]],
                  // Another accordion — dropped (never nest an accordion).
                  ['component' => 'accordion', 'props' => ['panels' => []]],
                  // Unknown component — dropped.
                  ['component' => 'malware', 'props' => []],
                ],
              ],
            ],
          ],
        ],
      ],
    ]);

    $this->assertCount(1, $clean['sections']);
    $panel = $clean['sections'][0]['props']['panels'][0];
    $this->assertSame('Shipping', $panel['label']);
    $this->assertTrue($panel['open']);
    // exclusive coerced to a real boolean.
    $this->assertTrue($clean['sections'][0]['props']['exclusive']);
    // Only the allowed leaf survived — container + accordion + unknown dropped.
    $this->assertCount(1, $panel['blocks']);
    $this->assertSame('markdown', $panel['blocks'][0]['component']);
    // Child props clamped: bad tone dropped, undeclared prop dropped, markdown kept.
    $childProps = $panel['blocks'][0]['props'];
    $this->assertSame('# Hi', $childProps['markdown']);
    $this->assertArrayNotHasKey('tone', $childProps);
    $this->assertArrayNotHasKey('rogue', $childProps);
  }

  public function testAccordionExclusiveCoercedToBoolEvenWithoutPanels(): void {
    // Regression: the studio renders `exclusive` as a control whose raw value can
    // be a string; if it isn't coerced, the SDC's `type: boolean` prop 500s the
    // render. Coercion must NOT depend on `panels` being present.
    $clean = $this->store()->validate([
      'type' => 'landing',
      'sections' => [
        ['component' => 'accordion', 'props' => ['heading' => 'X', 'exclusive' => 'some typed text']],
        ['component' => 'accordion', 'props' => ['exclusive' => 'true']],
        ['component' => 'accordion', 'props' => ['exclusive' => '']],
        ['component' => 'accordion', 'props' => ['exclusive' => true]],
      ],
    ]);
    foreach ($clean['sections'] as $section) {
      $this->assertIsBool($section['props']['exclusive']);
    }
    // Free text / empty → false; "true"/real bool → true.
    $this->assertFalse($clean['sections'][0]['props']['exclusive']);
    $this->assertTrue($clean['sections'][1]['props']['exclusive']);
    $this->assertFalse($clean['sections'][2]['props']['exclusive']);
    $this->assertTrue($clean['sections'][3]['props']['exclusive']);
  }

  public function testStoreLoadRoundTripAndUrl(): void {
    $id = $this->store()->store([
      'type' => 'landing',
      'title' => 'Round',
      'sections' => [
        ['component' => 'hero', 'props' => ['variant' => 'split', 'heading' => 'Hi']],
      ],
    ]);
    $this->assertMatchesRegularExpression('/^[A-Za-z0-9]+$/', $id);

    // load() resolves the split fields back into the merged schema.
    $loaded = $this->store()->load($id);
    $this->assertSame('Round', $loaded['title']);
    $this->assertSame('hero', $loaded['sections'][0]['component']);
    $this->assertSame('split', $loaded['sections'][0]['props']['variant']);
    $this->assertSame('Hi', $loaded['sections'][0]['props']['heading']);

    // The page is stored SPLIT: layout in structure, copy in content.
    $node = \Drupal::entityTypeManager()->getStorage('node')->load($id);
    $structure = json_decode((string) $node->get('field_page_structure')->value, TRUE);
    $content = json_decode((string) $node->get('field_page_content')->value, TRUE);
    $slotId = $structure['slots'][0]['id'];
    $this->assertSame('split', $structure['slots'][0]['variant']);
    $this->assertArrayNotHasKey('heading', $structure['slots'][0]);
    $this->assertSame('Hi', $content['slots'][$slotId]['heading']);

    // url() resolves the node canonical (pathauto-aliased in prod; /node/N here).
    $this->assertStringContainsString("/node/$id", $this->store()->url($id));
    $this->assertNull($this->store()->load('does-not-exist'));
  }

  public function testTeaserRoundTripToDedicatedFields(): void {
    $id = $this->store()->store([
      'type' => 'landing',
      'title' => 'Presence',
      'sections' => [['component' => 'hero', 'props' => ['heading' => 'H']]],
      'teaser' => [
        'title' => 'A sharp teaser headline',
        'description' => 'A short card summary that shows where this page is referenced.',
        'image' => 'media:42',
      ],
    ]);

    // The teaser round-trips through load() → resolve().
    $loaded = $this->store()->load($id);
    $this->assertSame('A sharp teaser headline', $loaded['teaser']['title']);
    $this->assertSame('A short card summary that shows where this page is referenced.', $loaded['teaser']['description']);
    $this->assertSame('media:42', $loaded['teaser']['image']);

    // Each key persisted to its OWN dedicated field (not a JSON blob).
    $node = \Drupal::entityTypeManager()->getStorage('node')->load($id);
    $this->assertSame('A sharp teaser headline', $node->get('field_teaser_title')->value);
    $this->assertSame('media:42', $node->get('field_teaser_image')->value);
    // The teaser is not conflated with the SEO/meta block.
    $this->assertArrayNotHasKey('meta', $loaded);
  }

  public function testTeaserClampDropsBlanksAndMalformedImage(): void {
    // Blank values are dropped; a non-media-token image is rejected so a bad
    // value can never reach the renderer.
    $clean = $this->store()->validate([
      'type' => 'landing',
      'title' => 'Clamp',
      'teaser' => [
        'title' => '  Trimmed  ',
        'description' => '',
        'image' => 'https://evil.example/x.png',
      ],
    ]);
    $this->assertSame('Trimmed', $clean['teaser']['title']);
    $this->assertArrayNotHasKey('description', $clean['teaser']);
    $this->assertArrayNotHasKey('image', $clean['teaser']);

    // A page with no usable teaser value carries no teaser block at all.
    $empty = $this->store()->validate([
      'type' => 'landing',
      'title' => 'None',
      'teaser' => ['title' => '   ', 'image' => 'not-a-token'],
    ]);
    $this->assertArrayNotHasKey('teaser', $empty);

    // A well-formed media token survives.
    $ok = $this->store()->validate([
      'type' => 'landing',
      'title' => 'Img',
      'teaser' => ['image' => 'media:7'],
    ]);
    $this->assertSame('media:7', $ok['teaser']['image']);
  }

  public function testTeaserClearsFieldsWhenRemoved(): void {
    $id = $this->store()->store([
      'type' => 'landing',
      'title' => 'Clearable',
      'teaser' => ['title' => 'Set once', 'image' => 'media:1'],
    ]);
    // Re-store without a teaser → the dedicated fields clear (no lingering value).
    $node = \Drupal::entityTypeManager()->getStorage('node')->load($id);
    $this->store()->writeSchema($node, ['type' => 'landing', 'title' => 'Clearable']);
    $node->save();

    $reloaded = $this->store()->load($id);
    $this->assertArrayNotHasKey('teaser', $reloaded);
    $fresh = \Drupal::entityTypeManager()->getStorage('node')->load($id);
    $this->assertTrue($fresh->get('field_teaser_title')->isEmpty());
    $this->assertTrue($fresh->get('field_teaser_image')->isEmpty());
  }

  public function testDirectoryPaginatesFiltersAndBadges(): void {
    for ($i = 0; $i < 15; $i++) {
      $this->store()->store([
        'type' => 'landing',
        'title' => sprintf('Page %02d', $i),
        'sections' => [['component' => 'hero', 'props' => ['heading' => 'H']]],
      ]);
    }
    $this->store()->store([
      'type' => 'landing',
      'title' => 'Findable widget',
      'sections' => [['component' => 'hero', 'props' => ['heading' => 'H']]],
    ]);

    // First window: 12 of 16, with the editorial badge resolved per item.
    $first = $this->store()->directory(0, 12);
    $this->assertSame(16, $first['total']);
    $this->assertCount(12, $first['items']);
    $this->assertSame(0, $first['offset']);
    $this->assertSame(12, $first['limit']);
    $row = $first['items'][0];
    $this->assertArrayHasKey('id', $row);
    $this->assertArrayHasKey('title', $row);
    $this->assertArrayHasKey('url', $row);
    // A moderated bundle → a non-empty state + label (new pages land in draft).
    $this->assertSame('draft', $row['state']);
    $this->assertSame('Draft', $row['state_label']);
    $this->assertFalse($row['has_pending_draft']);

    // Second window: the remaining 4 — total is window-independent.
    $second = $this->store()->directory(12, 12);
    $this->assertSame(16, $second['total']);
    $this->assertCount(4, $second['items']);

    // Case-insensitive title filter narrows total + items together.
    $found = $this->store()->directory(0, 12, 'findable');
    $this->assertSame(1, $found['total']);
    $this->assertCount(1, $found['items']);
    $this->assertSame('Findable widget', $found['items'][0]['title']);

    // Defensive clamps: limit floored to 1, offset floored to 0.
    $clamped = $this->store()->directory(-5, 0);
    $this->assertSame(0, $clamped['offset']);
    $this->assertSame(1, $clamped['limit']);
    $this->assertCount(1, $clamped['items']);
  }

  public function testSaveDraftStampsRevisionCoauthors(): void {
    $id = $this->store()->store(['type' => 'landing', 'title' => 'Provenance']);

    // A save carrying Studio + Agent context records them as revision co-authors
    // (the human is on revision_user, unchanged); only the known keys survive.
    $this->store()->saveDraft(['type' => 'landing', 'title' => 'Provenance'], $id, NULL, NULL, [
      ['actor' => 'studio', 'id' => 'checks'],
      ['actor' => 'agent', 'id' => 'page-repair', 'thread' => 'thr_x', 'junk' => 'dropped'],
      ['no' => 'actor'],
    ]);

    $node = $this->container->get('aincient_pages.moderation')->loadLatestRevision($id, 'aincient_page');
    $records = [];
    foreach ($node->getUntranslated()->get('field_revision_coauthors') as $item) {
      $records[] = json_decode($item->value, TRUE);
    }
    $this->assertSame([
      ['actor' => 'studio', 'id' => 'checks'],
      ['actor' => 'agent', 'id' => 'page-repair', 'thread' => 'thr_x'],
    ], $records, 'Malformed entries and unknown keys are dropped; thread metadata is kept.');

    // A plain edit with no studio/agent context carries no co-authors — the set
    // describes THIS revision, so it clears rather than accumulating.
    $this->store()->saveDraft(['type' => 'landing', 'title' => 'Provenance'], $id, NULL, NULL, NULL);
    $node = $this->container->get('aincient_pages.moderation')->loadLatestRevision($id, 'aincient_page');
    $this->assertCount(0, $node->getUntranslated()->get('field_revision_coauthors'));
  }

}
