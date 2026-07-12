<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Kernel;

use Drupal\aincient_pages\PageStore;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the language-aware resolver (PageStore::resolve) — symmetric mode.
 *
 * The structure/content split exists so a translation can SHARE the source
 * layout while only the words diverge. This proves the symmetric inheritance
 * the split unlocks: a translation that leaves field_page_structure empty
 * inherits the source layout, and its content overlay overrides only the slots
 * it localised (untranslated copy falls back to the source).
 *
 * @group aincient
 * @covers \Drupal\aincient_pages\PageStore::resolve
 */
#[RunTestsInSeparateProcesses]
final class PageResolverTest extends KernelTestBase {

  use EditorialWorkflowTestTrait;

  protected static $modules = [
    'system', 'user', 'field', 'text', 'node', 'language', 'content_translation',
    'workflows', 'content_moderation', 'aincient_pages',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('configurable_language');
    $this->installEntitySchema('content_moderation_state');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['language', 'content_translation']);

    ConfigurableLanguage::createFromLangcode('de')->save();

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
    // The per-translation layout-mode flag (Phase 3b).
    FieldStorageConfig::create([
      'field_name' => 'field_layout_mode',
      'entity_type' => 'node',
      'type' => 'string',
      'settings' => ['max_length' => 16, 'is_ascii' => TRUE, 'case_sensitive' => FALSE],
      'translatable' => TRUE,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_layout_mode',
      'entity_type' => 'node',
      'bundle' => 'aincient_page',
      'label' => 'Layout mode',
      'translatable' => TRUE,
    ])->save();

    \Drupal::service('content_translation.manager')->setEnabled('node', 'aincient_page', TRUE);

    // The editorial workflow now governs aincient_page (the store needs it).
    $this->setUpEditorialWorkflow(['aincient_page']);

    // Governance defaults (Phase 3b): divergence permitted, symmetric by default.
    $this->config('aincient_pages.settings')
      ->set('translation', ['default_mode' => 'symmetric', 'allow_divergence' => TRUE])
      ->save();
  }

  private function store(): PageStore {
    return $this->container->get('aincient_pages.store');
  }

  /**
   * The resolved schema of the EDITABLE HEAD (latest revision) — what the studio
   * reads. Under the editorial workflow a save is a draft (forward) revision, so
   * the studio's read is loadLatest, not load() (the published-default/public
   * read). These resolver tests author drafts, so they assert against the head.
   */
  private function resolved(string $id, ?string $langcode = NULL): array {
    return $this->store()->loadLatest($id, $langcode)['schema'];
  }

  /**
   * PageStore::update with a langcode writes a SYMMETRIC translation: the
   * translation's structure stays empty (inherits the source layout) while its
   * content overlay carries the localised copy. The studio's translate path.
   */
  public function testUpdateWritesSymmetricTranslation(): void {
    $id = $this->store()->store([
      'type' => 'landing',
      'title' => 'Hello',
      'sections' => [
        ['component' => 'hero', 'props' => ['variant' => 'split', 'heading' => 'Hello', 'subheading' => 'EN sub']],
      ],
    ]);

    // The studio seeds the de draft from the resolved source, edits the copy,
    // and saves it for 'de' — exactly what PageController::save does.
    $draft = $this->resolved($id);
    $draft['title'] = 'Hallo';
    $draft['sections'][0]['props']['heading'] = 'Hallo Welt';
    $this->assertTrue($this->store()->update($id, $draft, 'de'));

    // The translation persisted NO structure of its own (symmetric → inherit).
    // Reload from storage so we assert the persisted state (Drupal normalises an
    // empty string_long to NULL on save), not the in-memory value.
    \Drupal::entityTypeManager()->getStorage('node')->resetCache([$id]);
    $node = \Drupal::entityTypeManager()->getStorage('node')->load($id);
    $this->assertTrue($node->hasTranslation('de'));
    $this->assertTrue($node->getTranslation('de')->get('field_page_structure')->isEmpty());

    // Resolved 'de': inherited layout + localised heading; un-edited copy and
    // the structural variant fall back to / come from the source.
    $de = $this->resolved($id, 'de');
    $this->assertSame('Hallo', $de['title']);
    $this->assertSame('split', $de['sections'][0]['props']['variant']);
    $this->assertSame('Hallo Welt', $de['sections'][0]['props']['heading']);
    $this->assertSame('EN sub', $de['sections'][0]['props']['subheading']);

    // A later source LAYOUT edit propagates to the symmetric translation.
    $src = $this->resolved($id);
    $src['sections'][0]['props']['variant'] = 'centered';
    $this->store()->update($id, $src);
    $this->assertSame('centered', $this->resolved($id, 'de')['sections'][0]['props']['variant']);
  }

  /**
   * Saving an unconfigured language is refused (no bogus translation spawned).
   */
  public function testUpdateRejectsUnconfiguredLanguage(): void {
    $id = $this->store()->store(['type' => 'landing', 'title' => 'Hi', 'sections' => []]);
    $this->assertFalse($this->store()->update($id, ['type' => 'landing', 'title' => 'Salut'], 'fr'));
    $node = \Drupal::entityTypeManager()->getStorage('node')->load($id);
    $this->assertFalse($node->hasTranslation('fr'));
  }

  /**
   * A German translation inherits the source layout and overlays only its copy.
   */
  public function testTranslationInheritsStructureAndOverlaysContent(): void {
    // Source (English) page: one hero with a structural variant + two copy props.
    $id = $this->store()->store([
      'type' => 'landing',
      'title' => 'Hello',
      'sections' => [
        ['component' => 'hero', 'props' => ['variant' => 'split', 'heading' => 'Hello', 'subheading' => 'EN sub']],
      ],
    ]);
    $slotId = $this->resolved($id)['sections'][0]['id'];

    // German translation: EMPTY structure (→ inherit source layout) + a content
    // overlay that localises only the heading (subheading left to fall back).
    $node = \Drupal::entityTypeManager()->getStorage('node')->load($id);
    $node->addTranslation('de', [
      'title' => 'Hallo',
      'field_page_structure' => '',
      'field_page_content' => json_encode([
        'title' => 'Hallo',
        'slots' => [$slotId => ['heading' => 'Hallo Welt']],
      ]),
    ]);
    $node->save();

    $de = $this->resolved($id, 'de');

    // Title localised; layout inherited from the source structure.
    $this->assertSame('Hallo', $de['title']);
    $this->assertCount(1, $de['sections']);
    $this->assertSame('hero', $de['sections'][0]['component']);
    $this->assertSame($slotId, $de['sections'][0]['id']);
    // Structural prop comes from the (inherited) source structure.
    $this->assertSame('split', $de['sections'][0]['props']['variant']);
    // Localised copy overrides; un-localised copy falls back to the source.
    $this->assertSame('Hallo Welt', $de['sections'][0]['props']['heading']);
    $this->assertSame('EN sub', $de['sections'][0]['props']['subheading']);

    // The source language is untouched by the German overlay.
    $en = $this->resolved($id, 'en');
    $this->assertSame('Hello', $en['title']);
    $this->assertSame('Hello', $en['sections'][0]['props']['heading']);
  }

  /**
   * An ASYMMETRIC translation owns its layout: its own structure field wins over
   * the source (it is not merged with it). The layout-mode flag is what makes it
   * own the structure — a symmetric translation would inherit the source instead.
   */
  public function testTranslationCanOverrideStructure(): void {
    $id = $this->store()->store([
      'type' => 'landing',
      'title' => 'Hello',
      'sections' => [
        ['component' => 'hero', 'props' => ['variant' => 'split', 'heading' => 'Hello']],
        ['component' => 'features', 'props' => ['columns' => 3, 'heading' => 'Why']],
      ],
    ]);
    $en = $this->resolved($id);
    $heroId = $en['sections'][0]['id'];

    // German diverges (asymmetric): keeps only the hero, with its own variant.
    $node = \Drupal::entityTypeManager()->getStorage('node')->load($id);
    $node->addTranslation('de', [
      'title' => 'Hallo',
      'field_layout_mode' => PageStore::MODE_ASYMMETRIC,
      'field_page_structure' => json_encode([
        'type' => 'landing',
        'slots' => [['id' => $heroId, 'component' => 'hero', 'variant' => 'centered']],
      ]),
      'field_page_content' => json_encode([
        'title' => 'Hallo',
        'slots' => [$heroId => ['heading' => 'Hallo Welt']],
      ]),
    ])->save();

    $de = $this->resolved($id, 'de');
    // Layout is the translation's own — one section, its own variant.
    $this->assertCount(1, $de['sections']);
    $this->assertSame('hero', $de['sections'][0]['component']);
    $this->assertSame('centered', $de['sections'][0]['props']['variant']);
    $this->assertSame('Hallo Welt', $de['sections'][0]['props']['heading']);

    // The source still has both sections at its own variant.
    $this->assertCount(2, $this->resolved($id, 'en')['sections']);
    $this->assertSame('split', $this->resolved($id, 'en')['sections'][0]['props']['variant']);
  }

  /**
   * Blog is a flat (slot-less) content shape: a translation overlays the body
   * fields it localised and falls back to the source for the rest.
   */
  public function testBlogTranslationOverlaysBodyFields(): void {
    $id = $this->store()->store([
      'type' => 'blog',
      'title' => 'A post',
      'category' => 'News',
      'lead' => 'An English lead.',
      'author' => 'Ada',
      'body_html' => '<p>English body</p>',
    ]);

    $node = \Drupal::entityTypeManager()->getStorage('node')->load($id);
    $node->addTranslation('de', [
      'title' => 'Ein Beitrag',
      'field_page_structure' => '',
      'field_page_content' => json_encode([
        'title' => 'Ein Beitrag',
        'lead' => 'Ein deutscher Vorspann.',
        'body_html' => '<p>Deutscher Text</p>',
      ]),
    ])->save();

    $de = $this->resolved($id, 'de');
    $this->assertSame('blog', $de['type']);
    // Localised fields win; untranslated fields fall back to the source copy.
    $this->assertSame('Ein Beitrag', $de['title']);
    $this->assertSame('Ein deutscher Vorspann.', $de['lead']);
    $this->assertSame('<p>Deutscher Text</p>', $de['body_html']);
    $this->assertSame('News', $de['category']);
    $this->assertSame('Ada', $de['author']);
  }

  /**
   * diverge() is copy-on-write: it snapshots the source skeleton into the
   * translation and detaches it, so a later source LAYOUT edit no longer reaches
   * it — while a symmetric sibling keeps following the source.
   */
  public function testDivergeSnapshotsSourceAndDetaches(): void {
    $id = $this->store()->store([
      'type' => 'landing',
      'title' => 'Hello',
      'sections' => [['component' => 'hero', 'props' => ['variant' => 'split', 'heading' => 'Hello']]],
    ]);
    // A symmetric de translation that localises the heading.
    $draft = $this->resolved($id);
    $draft['title'] = 'Hallo';
    $draft['sections'][0]['props']['heading'] = 'Hallo Welt';
    $this->store()->update($id, $draft, 'de');
    $this->assertSame(PageStore::MODE_SYMMETRIC, $this->store()->layoutMode($id, 'de'));

    // Diverge de → asymmetric, snapshotting the (currently 'split') source layout.
    $this->assertTrue($this->store()->diverge($id, 'de'));
    $this->assertSame(PageStore::MODE_ASYMMETRIC, $this->store()->layoutMode($id, 'de'));
    $de = $this->resolved($id, 'de');
    $this->assertSame('split', $de['sections'][0]['props']['variant']);
    // Localised content survives the divergence.
    $this->assertSame('Hallo Welt', $de['sections'][0]['props']['heading']);

    // A later source LAYOUT edit no longer propagates to the diverged de.
    $src = $this->resolved($id);
    $src['sections'][0]['props']['variant'] = 'centered';
    $this->store()->update($id, $src);
    $this->assertSame('centered', $this->resolved($id)['sections'][0]['props']['variant']);
    $this->assertSame('split', $this->resolved($id, 'de')['sections'][0]['props']['variant']);
  }

  /**
   * converge() reverts a diverged translation to inheriting the source layout
   * again, keeping its localised content.
   */
  public function testConvergeReInheritsSourceLayout(): void {
    $id = $this->store()->store([
      'type' => 'landing',
      'title' => 'Hello',
      'sections' => [['component' => 'hero', 'props' => ['variant' => 'split', 'heading' => 'Hello']]],
    ]);
    $draft = $this->resolved($id);
    $draft['sections'][0]['props']['heading'] = 'Hallo Welt';
    $this->store()->update($id, $draft, 'de');
    $this->store()->diverge($id, 'de');

    // Source moves to 'centered' while de is diverged (so de still shows 'split').
    $src = $this->resolved($id);
    $src['sections'][0]['props']['variant'] = 'centered';
    $this->store()->update($id, $src);
    $this->assertSame('split', $this->resolved($id, 'de')['sections'][0]['props']['variant']);

    // Converge → de re-inherits the (now 'centered') source layout, keeps copy.
    $this->assertTrue($this->store()->converge($id, 'de'));
    $this->assertSame(PageStore::MODE_SYMMETRIC, $this->store()->layoutMode($id, 'de'));
    $de = $this->resolved($id, 'de');
    $this->assertSame('centered', $de['sections'][0]['props']['variant']);
    $this->assertSame('Hallo Welt', $de['sections'][0]['props']['heading']);
    // The translation's own structure field was cleared.
    \Drupal::entityTypeManager()->getStorage('node')->resetCache([$id]);
    $node = \Drupal::entityTypeManager()->getStorage('node')->load($id);
    $this->assertTrue($node->getTranslation('de')->get('field_page_structure')->isEmpty());
  }

  /**
   * Governance gate: with translation.allow_divergence off, diverge() is refused
   * (the translation stays symmetric). Converge is always allowed.
   */
  public function testDivergeRespectsGovernanceSetting(): void {
    $this->config('aincient_pages.settings')->set('translation.allow_divergence', FALSE)->save();
    $id = $this->store()->store([
      'type' => 'landing',
      'title' => 'Hello',
      'sections' => [['component' => 'hero', 'props' => ['heading' => 'Hello']]],
    ]);
    $this->store()->update($id, $this->resolved($id), 'de');

    $this->assertFalse($this->store()->diverge($id, 'de'));
    $this->assertSame(PageStore::MODE_SYMMETRIC, $this->store()->layoutMode($id, 'de'));
  }

  /**
   * diverge()/converge() refuse the source language and unknown translations.
   */
  public function testDivergeRejectsSourceAndUnknownLanguage(): void {
    $id = $this->store()->store([
      'type' => 'landing',
      'title' => 'Hello',
      'sections' => [['component' => 'hero', 'props' => ['heading' => 'Hello']]],
    ]);
    $this->assertFalse($this->store()->diverge($id, 'en'));
    $this->assertFalse($this->store()->diverge($id, 'de'));
    $this->assertFalse($this->store()->converge($id, 'de'));
  }

}
