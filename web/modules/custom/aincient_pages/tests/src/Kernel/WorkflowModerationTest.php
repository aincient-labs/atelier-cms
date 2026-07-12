<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Kernel;

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

}
