<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Kernel;

use Drupal\aincient_pages\Exception\RevisionConflictException;
use Drupal\aincient_pages\NodeModeration;
use Drupal\aincient_pages\PageStore;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The editorial-workflow gate: decoupled draft/publish + the multilingual
 * forward-revision sharp edge flagged as the riskiest integration point.
 *
 * Proves the behaviours Phases 1–2 depend on before any frontend builds on them:
 * new content is a draft; Save draft on a published page forks a forward revision
 * without touching the live page; Publish flips the default; the public read and
 * the editor read diverge while a draft is pending; a forward draft in one
 * language does NOT clobber the published copy in another; and an author cannot
 * self-approve.
 *
 * @group aincient
 * @covers \Drupal\aincient_pages\PageStore
 * @covers \Drupal\aincient_pages\NodeModeration
 */
#[RunTestsInSeparateProcesses]
final class WorkflowModerationTest extends KernelTestBase {

  use EditorialWorkflowTestTrait;
  use UserCreationTrait;

  protected static $modules = [
    'system', 'user', 'field', 'text', 'node', 'language', 'content_translation',
    'workflows', 'content_moderation', 'aincient_core', 'aincient_pages',
    // Test-only: its provenance field (revision_graph_parent) records which
    // revision each save was built on — the structural witness for per-language
    // heads. Product code never depends on it.
    'revision_graph',
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
    $this->setUpEditorialWorkflow(['aincient_page']);

    $this->config('aincient_pages.settings')
      ->set('translation', ['default_mode' => 'symmetric', 'allow_divergence' => TRUE])
      ->save();

    // A privileged operator so access('update') and transitions resolve TRUE
    // (uid 1 would bypass node access and mask the workflow). All transitions +
    // unpublished view, matching the shipped operator-console grant.
    $this->setUpCurrentUser(['uid' => 2], [
      'access content',
      'view any unpublished content',
      'view latest version',
      'edit any aincient_page content',
      'create aincient_page content',
      'use aincient_editorial transition create_new_draft',
      'use aincient_editorial transition submit_for_review',
      'use aincient_editorial transition approve',
      'use aincient_editorial transition reject',
      'use aincient_editorial transition publish',
      'use aincient_editorial transition archive',
      'use aincient_editorial transition restore',
    ]);
  }

  private function store(): PageStore {
    return $this->container->get('aincient_pages.store');
  }

  private function moderation(): NodeModeration {
    return $this->container->get('aincient_pages.moderation');
  }

  /**
   * A landing schema with a single hero whose heading carries $marker.
   */
  private function schema(string $marker): array {
    return [
      'type' => 'landing',
      'title' => $marker,
      'sections' => [
        ['component' => 'hero', 'props' => ['heading' => $marker]],
      ],
    ];
  }

  /**
   * New content starts as a draft — not published, not live.
   */
  public function testNewPageIsDraft(): void {
    $id = $this->store()->store($this->schema('First'));
    $node = $this->container->get('entity_type.manager')->getStorage('node')->load($id);
    $this->assertSame('draft', $node->get('moderation_state')->value);
    $this->assertFalse($node->isPublished());
  }

  /**
   * Save draft on a PUBLISHED page forks a forward revision: the live (default)
   * revision keeps the published content; the editable head holds the new draft.
   */
  public function testSaveDraftOnPublishedForksForwardRevision(): void {
    $id = $this->store()->store($this->schema('Live'));
    $this->store()->publish($id, $this->schema('Live'));

    // Now a draft edit on top of the published page.
    $env = $this->store()->saveDraft($this->schema('WIP'), $id);
    $this->assertSame('draft', $env['moderation_state']);
    $this->assertTrue($env['has_pending_draft'], 'A forward draft is pending.');

    // The public read (default revision) still shows the published copy…
    $this->assertSame('Live', $this->store()->load($id)['title']);
    // …while the editor read (latest revision) shows the work-in-progress draft.
    $this->assertSame('WIP', $this->store()->loadLatest($id)['schema']['title']);
  }

  /**
   * Publishing the pending draft flips the default revision + status to it.
   */
  public function testPublishFlipsTheDefault(): void {
    $id = $this->store()->store($this->schema('V1'));
    $this->store()->publish($id, $this->schema('V1'));
    $this->store()->saveDraft($this->schema('V2'), $id);
    $env = $this->store()->publish($id, $this->schema('V2'));

    $this->assertSame('published', $env['moderation_state']);
    $this->assertFalse($env['has_pending_draft']);
    $this->assertSame('V2', $this->store()->load($id)['title'], 'The live page is now V2.');
  }

  /**
   * THE GATE: a forward draft in language A must not clobber the PUBLISHED copy
   * in language B. content_moderation keeps one pending revision per entity, so
   * editing the en draft has to leave the published de translation intact on the
   * default revision.
   */
  public function testForwardDraftDoesNotClobberOtherLanguage(): void {
    // Publish en, then publish a de translation — both live.
    $id = $this->store()->store($this->schema('Hello'));
    $this->store()->publish($id, $this->schema('Hello'));
    $this->store()->saveDraft($this->schema('Hallo'), $id, 'de');
    $this->store()->publish($id, $this->schema('Hallo'), 'de');

    // A forward DRAFT on en only.
    $this->store()->saveDraft($this->schema('Hello v2 WIP'), $id, 'en');

    // The published de copy is untouched on the live (default) revision…
    $this->assertSame('Hallo', $this->store()->load($id, 'de')['title']);
    // …the published en copy is still the old one (draft isn't live yet)…
    $this->assertSame('Hello', $this->store()->load($id, 'en')['title']);
    // …and the en editable head carries the WIP draft.
    $this->assertSame('Hello v2 WIP', $this->store()->loadLatest($id, 'en')['schema']['title']);

    // Publishing the en draft must STILL leave de's published copy intact.
    $this->store()->publish($id, NULL, 'en');
    $this->assertSame('Hello v2 WIP', $this->store()->load($id, 'en')['title']);
    $this->assertSame('Hallo', $this->store()->load($id, 'de')['title']);
  }

  /**
   * A blog post schema; $date NULL omits the authored date.
   */
  private function post(string $marker, ?string $date): array {
    $schema = ['type' => 'blog', 'title' => $marker, 'body_md' => $marker];
    if ($date !== NULL) {
      $schema['date'] = $date;
    }
    return $schema;
  }

  /**
   * The `created` (post date) of one translation of the live default revision,
   * or of that translation's editable head when $head, as Y-m-d.
   */
  private function postDate(string $id, string $langcode, bool $head = FALSE): string {
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache([(int) $id]);
    $node = $head
      ? $this->moderation()->loadHead($id, 'aincient_page', $langcode)
      : $storage->load((int) $id)->getTranslation($langcode);
    $this->assertSame($langcode, $node->language()->getId());
    return date('Y-m-d', (int) $node->get('created')->value);
  }

  /**
   * Independent translation ⇒ independent post date (M10): a new DE translation
   * starts with EN's date; a DE date edit moves only DE's `created` — not EN's,
   * neither while drafted nor after DE is published.
   */
  public function testPostDateIsPerTranslation(): void {
    $id = $this->store()->store($this->post('EN', '2024-01-10'));
    $this->store()->publish($id, $this->post('EN', '2024-01-10'));
    $this->assertSame('2024-01-10', $this->postDate($id, 'en'));

    // A new DE translation without a date of its own inherits the source date.
    $this->store()->saveDraft($this->post('DE', NULL), $id, 'de');
    $this->assertSame('2024-01-10', $this->postDate($id, 'de', TRUE), 'A new translation starts with the source post date, not "now".');

    // DE sets its own date: EN's date is untouched, live and head.
    $this->store()->saveDraft($this->post('DE', '2024-02-20'), $id, 'de');
    $this->assertSame('2024-02-20', $this->postDate($id, 'de', TRUE));
    $this->assertSame('2024-01-10', $this->postDate($id, 'en'), 'A DE date draft leaves EN live alone.');
    $this->assertSame('2024-01-10', $this->postDate($id, 'en', TRUE), 'A DE date draft leaves EN\'s head alone.');

    // Publishing DE makes DE's date live; EN keeps its own.
    $this->store()->publish($id, NULL, 'de');
    $this->assertSame('2024-02-20', $this->postDate($id, 'de'));
    $this->assertSame('2024-01-10', $this->postDate($id, 'en'), 'Publishing DE never changes EN\'s post date.');

    // And an EN date change leaves DE's alone.
    $this->store()->publish($id, $this->post('EN', '2024-03-30'));
    $this->assertSame('2024-03-30', $this->postDate($id, 'en'));
    $this->assertSame('2024-02-20', $this->postDate($id, 'de'));
  }

  /**
   * Phase 6 multilingual forward revisions, end to end through the real store:
   * a DE-only draft, then an EN draft on top of it, then publishing DE alone.
   */
  public function testInterleavedForwardDraftsAcrossTranslations(): void {
    $id = $this->store()->store($this->schema('EN old'));
    $this->store()->publish($id, $this->schema('EN old'));
    $this->store()->saveDraft($this->schema('DE old'), $id, 'de');
    $this->store()->publish($id, $this->schema('DE old'), 'de');
    $this->assertSame('EN old', $this->store()->load($id, 'en')['title']);
    $this->assertSame('DE old', $this->store()->load($id, 'de')['title']);

    // (2) A forward draft in DE only.
    $this->store()->saveDraft($this->schema('DE draft'), $id, 'de');
    $this->assertSame('EN old', $this->store()->load($id, 'en')['title']);
    $this->assertSame('DE old', $this->store()->load($id, 'de')['title'], 'Public DE stays on the published default.');
    $this->assertSame('DE draft', $this->store()->loadLatest($id, 'de')['schema']['title']);
    $this->assertSame('EN old', $this->store()->loadLatest($id, 'en')['schema']['title']);
    $this->assertDefaultPublished($id, ['en', 'de']);

    // (3) Then a forward draft in EN as well: DE's draft must survive.
    $this->store()->saveDraft($this->schema('EN draft'), $id, 'en');
    $this->assertSame('EN draft', $this->store()->loadLatest($id, 'en')['schema']['title']);
    $this->assertSame('DE draft', $this->store()->loadLatest($id, 'de')['schema']['title'], 'The DE draft is not clobbered by an EN draft.');
    $this->assertSame('EN old', $this->store()->load($id, 'en')['title']);
    $this->assertSame('DE old', $this->store()->load($id, 'de')['title']);
    $this->assertDefaultPublished($id, ['en', 'de']);

    // (4) Publish DE. Guards the M9 fix: a DE publish must not carry EN's
    // pending draft live (translations are moderated independently).
    $this->store()->publish($id, NULL, 'de');
    $this->assertSame('DE draft', $this->store()->load($id, 'de')['title'], 'DE live shows the DE edits.');
    $this->assertSame('EN old', $this->store()->load($id, 'en')['title'], 'Publishing DE leaves EN live unchanged.');
    // EN's draft is still pending, untouched, on EN's own head.
    $en = $this->store()->loadLatest($id, 'en');
    $this->assertSame('EN draft', $en['schema']['title']);
    $this->assertSame('draft', $en['moderation_state']);
    $this->assertTrue($en['has_pending_draft'], 'EN still has a pending draft.');
    $this->assertFalse($this->store()->loadLatest($id, 'de')['has_pending_draft'], 'DE has nothing pending.');

    // (5) Publishing EN makes EN live without touching DE.
    $this->store()->publish($id, NULL, 'en', $en['base_vid']);
    $this->assertSame('EN draft', $this->store()->load($id, 'en')['title']);
    $this->assertSame('DE draft', $this->store()->load($id, 'de')['title'], 'DE live is unchanged by the EN publish.');
    $this->assertDefaultPublished($id, ['en', 'de']);
    $this->assertFalse($this->store()->loadLatest($id, 'en')['has_pending_draft']);
  }

  /**
   * The mirror image: publishing EN while a DE draft is pending leaves the DE
   * draft pending and DE's public copy unchanged.
   */
  public function testPublishEnLeavesPendingDeDraft(): void {
    $id = $this->publishedBilingual();
    $this->store()->saveDraft($this->schema('DE draft'), $id, 'de');
    $this->store()->saveDraft($this->schema('EN new'), $id, 'en');

    $this->store()->publish($id, NULL, 'en');
    $this->assertSame('EN new', $this->store()->load($id, 'en')['title']);
    $this->assertSame('DE old', $this->store()->load($id, 'de')['title'], 'DE public copy is unchanged.');
    $de = $this->store()->loadLatest($id, 'de');
    $this->assertSame('DE draft', $de['schema']['title'], 'The DE draft survives the EN publish.');
    $this->assertSame('draft', $de['moderation_state']);
    $this->assertTrue($de['has_pending_draft'], 'DE draft is still pending.');
    $this->assertDefaultPublished($id, ['en', 'de']);

    // And it publishes on its own later, on top of the new EN.
    $this->store()->publish($id, NULL, 'de', $de['base_vid']);
    $this->assertSame('DE draft', $this->store()->load($id, 'de')['title']);
    $this->assertSame('EN new', $this->store()->load($id, 'en')['title']);
  }

  /**
   * The base_vid pin is per language: a DE save based on DE's head is not stale
   * because EN saved meanwhile (and vice versa) — but a genuinely stale base for
   * the SAME language still 409s.
   */
  public function testBaseVidPinIsPerLanguage(): void {
    $id = $this->publishedBilingual();
    $enBase = $this->store()->loadLatest($id, 'en')['base_vid'];
    $deBase = $this->store()->loadLatest($id, 'de')['base_vid'];

    // EN saves first; DE's pin is unaffected.
    $enEnv = $this->store()->saveDraft($this->schema('EN draft'), $id, 'en', $enBase);
    $this->assertNotSame($enBase, $enEnv['base_vid'], 'The EN envelope pins EN\'s new head.');
    $deEnv = $this->store()->saveDraft($this->schema('DE draft'), $id, 'de', $deBase);
    $this->assertNotNull($deEnv, 'A DE save on DE\'s head is not stale after an EN save.');
    // …and EN's next save, pinned to the envelope it got, is not stale after DE's.
    $this->assertNotNull($this->store()->saveDraft($this->schema('EN draft 2'), $id, 'en', $enEnv['base_vid']));
    $this->assertSame('DE draft', $this->store()->loadLatest($id, 'de')['schema']['title']);
    $this->assertSame('EN draft 2', $this->store()->loadLatest($id, 'en')['schema']['title']);

    // A pure transition from the DE studio (base_vid, no langcode — what the
    // console sends) moves DE only, and isn't stale.
    $deHead = $this->store()->loadLatest($id, 'de')['base_vid'];
    $env = $this->store()->transition($id, 'submit_for_review', $deHead);
    $this->assertSame('needs_review', $env['moderation_state']);
    $this->assertSame('draft', $this->store()->loadLatest($id, 'en')['moderation_state'], 'EN state is untouched.');

    // A stale base for the same language still conflicts.
    $this->expectException(RevisionConflictException::class);
    $this->store()->saveDraft($this->schema('EN stale'), $id, 'en', $enBase);
  }

  /**
   * Structural witness via revision_graph's provenance field: an EN draft saved
   * while a DE draft is pending is built on EN's own previous head (the
   * published EN revision), NOT on the DE draft — the parent the pre-fix code
   * recorded. The DE content it carries comes from the default revision.
   */
  public function testEnDraftIsBuiltOnEnHeadNotDeDraft(): void {
    $id = $this->publishedBilingual();
    $enHead = $this->store()->loadLatest($id, 'en')['base_vid'];

    $deVid = $this->store()->saveDraft($this->schema('DE draft'), $id, 'de')['base_vid'];
    $enVid = $this->store()->saveDraft($this->schema('EN draft'), $id, 'en')['base_vid'];
    $this->assertNotSame($deVid, $enVid);

    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache([$id]);
    $enRevision = $storage->loadRevision($enVid);
    $parent = (int) $enRevision->get('revision_graph_parent')->value;
    $this->assertSame($enHead, $parent, 'EN draft derives from EN\'s own previous head.');
    $this->assertNotSame($deVid, $parent, 'EN draft does not derive from the DE draft.');
    $this->assertSame('DE old', $enRevision->getTranslation('de')->label(), 'The EN draft carries the LIVE DE copy, not the DE draft.');
  }

  /**
   * A page with EN + DE both published ('EN old' / 'DE old').
   */
  private function publishedBilingual(): string {
    $id = $this->store()->store($this->schema('EN old'));
    $this->store()->publish($id, $this->schema('EN old'));
    $this->store()->saveDraft($this->schema('DE old'), $id, 'de');
    $this->store()->publish($id, $this->schema('DE old'), 'de');
    return $id;
  }

  /**
   * Asserts the default revision is published in every given language.
   */
  private function assertDefaultPublished(string $id, array $langs): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache([$id]);
    $node = $storage->load($id);
    foreach ($langs as $lang) {
      $this->assertTrue($node->getTranslation($lang)->isPublished(), "$lang default is published.");
      $this->assertTrue($node->getTranslation($lang)->isDefaultRevision(), "$lang is the default revision.");
    }
  }

  /**
   * An author who lacks the `approve` transition cannot push Needs review →
   * Published — the store refuses it (NULL), the gate behind reviewer-only
   * approval.
   */
  public function testAuthorCannotApprove(): void {
    $id = $this->store()->store($this->schema('Pending'));
    $this->store()->transition($id, 'submit_for_review');

    // Re-bind the current user to an AUTHOR who lacks `approve`.
    $author = $this->createUser([
      'access content',
      'view any unpublished content',
      'view latest version',
      'edit any aincient_page content',
      'use aincient_editorial transition create_new_draft',
      'use aincient_editorial transition submit_for_review',
      'use aincient_editorial transition reject',
    ]);
    $this->setCurrentUser($author);

    $node = $this->moderation()->loadLatestRevision($id, 'aincient_page');
    $this->assertSame('needs_review', $this->moderation()->state($node));
    $this->assertFalse(
      $this->moderation()->canReachState($node, 'published'),
      'An author without the approve transition cannot publish from needs_review.',
    );
    // The store-level transition refuses it too.
    $this->assertNull($this->store()->transition($id, 'approve'));
  }

  /**
   * A draft revision's log says what changed (PageSchemaSummariser), names the
   * language for a translation, and keeps the constant message on a no-op.
   */
  public function testDraftRevisionLogSaysWhatChanged(): void {
    $id = $this->store()->store($this->schema('Hello'));
    $schema = $this->store()->loadLatest($id)['schema'];

    $schema['sections'][] = ['component' => 'faq', 'props' => ['heading' => 'Questions']];
    $schema['sections'][0]['props']['heading'] = 'Hello again';
    $this->store()->saveDraft($schema, $id);
    $this->assertSame(
      "2 sections changed\n"
      . "- Section 2 (FAQ, \"Questions\") added\n"
      . "- Section 1 (Hero, \"Hello again\") edited",
      $this->latestLog($id),
    );

    // An identical re-save itemises nothing — the constant stays.
    $this->store()->saveDraft($this->store()->loadLatest($id)['schema'], $id);
    $this->assertSame('Saved draft via the page studio.', $this->latestLog($id));

    // A brand-new translation is labelled, then its edits name the language.
    $this->store()->saveDraft($this->schema('Hallo'), $id, 'de');
    $this->assertSame('German translation created', $this->latestLog($id));
    $de = $this->store()->loadLatest($id, 'de')['schema'];
    $de['title'] = 'Hallo Welt';
    $this->store()->saveDraft($de, $id, 'de');
    $this->assertSame('German: Page title → "Hallo Welt"', $this->latestLog($id));
  }

  /**
   * The revision log message on the page's latest revision.
   */
  private function latestLog(string $id): string {
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache([$id]);
    $node = $storage->loadRevision($storage->getLatestRevisionId($id));
    return (string) $node->getRevisionLogMessage();
  }

}
