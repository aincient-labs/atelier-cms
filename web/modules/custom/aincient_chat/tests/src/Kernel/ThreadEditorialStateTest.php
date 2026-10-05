<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_chat\Kernel;

use Drupal\aincient_chat\Chat\SessionThreadStore;
use Drupal\aincient_pages\PageStore;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\aincient_pages\Kernel\EditorialWorkflowTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The sidebar's thread list carries each thread's page state per language.
 *
 * Phase 4 of the content workflow (milestone A6): a thread homed to a page
 * reports that page's moderation state in the THREAD's language, read fresh at
 * list time — a German thread over a published English page reads the German
 * translation's state, not the English one.
 *
 * @group aincient_chat
 * @covers \Drupal\aincient_chat\Chat\SessionThreadStore::listThreads
 */
#[RunTestsInSeparateProcesses]
final class ThreadEditorialStateTest extends KernelTestBase {

  use EditorialWorkflowTestTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'text', 'options', 'filter', 'key',
    'node', 'language', 'content_translation', 'workflows', 'content_moderation',
    'flowdrop_ui_components', 'flowdrop', 'flowdrop_node_category',
    'flowdrop_node_type', 'flowdrop_node_processor', 'flowdrop_workflow',
    'flowdrop_orchestration', 'flowdrop_runtime', 'flowdrop_pipeline',
    'flowdrop_job', 'flowdrop_session', 'flowdrop_interrupt', 'flowdrop_memory',
    'aincient_core', 'aincient_pages', 'aincient_chat',
  ];

  /**
   * The acting (and thread-owning) user id.
   */
  private int $uid = 2;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('configurable_language');
    $this->installEntitySchema('content_moderation_state');
    $this->installEntitySchema('flowdrop_workflow');
    $this->installEntitySchema('flowdrop_session');
    $this->installEntitySchema('flowdrop_session_message');
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

    $this->setUpCurrentUser(['uid' => $this->uid], [
      'access content',
      'administer aincient pages',
      'view any unpublished content',
      'view latest version',
      'edit any aincient_page content',
      'create aincient_page content',
      'use aincient_editorial transition create_new_draft',
      'use aincient_editorial transition submit_for_review',
      'use aincient_editorial transition publish',
    ]);
  }

  private function pages(): PageStore {
    return $this->container->get('aincient_pages.store');
  }

  private function threads(): SessionThreadStore {
    return $this->container->get('aincient_chat.thread_store');
  }

  private function schema(string $marker): array {
    return [
      'type' => 'landing',
      'title' => $marker,
      'sections' => [['component' => 'hero', 'props' => ['heading' => $marker]]],
    ];
  }

  /**
   * A console thread (FlowDrop session) owned by the acting user, optionally
   * homed to a page translation through the store's own write path.
   */
  private function thread(string $threadId, ?string $nid = NULL, string $langcode = ''): void {
    $this->container->get('entity_type.manager')->getStorage('flowdrop_session')->create([
      'name' => 'console:' . $threadId,
      'uid' => $this->uid,
    ])->save();
    if ($nid !== NULL) {
      $this->assertSame(1, $this->threads()->setWorkingNode($threadId, $this->uid, (int) $nid, $langcode));
    }
  }

  /**
   * The listed editorialState of one thread.
   */
  private function stateOf(string $threadId): ?array {
    foreach ($this->threads()->listThreads($this->uid) as $row) {
      if ($row['remoteId'] === $threadId) {
        $this->assertArrayHasKey('editorialState', $row);
        return $row['editorialState'];
      }
    }
    $this->fail("Thread $threadId not listed.");
  }

  public function testDraftPageThreadReadsDraft(): void {
    $id = $this->pages()->store($this->schema('New'));
    $this->thread('thr_draft', $id, 'en');

    $this->assertSame(['state' => 'draft', 'label' => 'Draft', 'live' => FALSE], $this->stateOf('thr_draft'));
  }

  public function testPublishedPageThreadReadsPublished(): void {
    $id = $this->pages()->store($this->schema('Live'));
    $this->pages()->publish($id, $this->schema('Live'));
    $this->thread('thr_live', $id, 'en');

    $this->assertSame(['state' => 'published', 'label' => 'Published', 'live' => TRUE], $this->stateOf('thr_live'));
  }

  /**
   * A forward draft over a published page reads as the draft, with the
   * published copy still live — the marker shows Draft, the detail says live.
   */
  public function testForwardDraftKeepsLiveFact(): void {
    $id = $this->pages()->store($this->schema('Live'));
    $this->pages()->publish($id, $this->schema('Live'));
    $this->pages()->saveDraft($this->schema('WIP'), $id);
    $this->thread('thr_wip', $id, 'en');

    $this->assertSame(
      ['state' => 'draft', 'label' => 'Draft · published copy live', 'live' => TRUE],
      $this->stateOf('thr_wip'),
    );
  }

  /**
   * Two threads on ONE page in two languages read their own translation.
   */
  public function testGermanThreadOverPublishedEnglishReadsPerLanguage(): void {
    $id = $this->pages()->store($this->schema('Hello'));
    $this->pages()->publish($id, $this->schema('Hello'));
    $this->pages()->saveDraft($this->schema('Hallo'), $id, 'de');
    $this->thread('thr_en', $id, 'en');
    $this->thread('thr_de', $id, 'de');

    $this->assertSame('published', $this->stateOf('thr_en')['state']);
    $this->assertSame(['state' => 'draft', 'label' => 'Draft', 'live' => FALSE], $this->stateOf('thr_de'));
  }

  /**
   * Unhomed threads and threads on a deleted page carry no state.
   */
  public function testUnhomedAndDeletedCarryNoState(): void {
    $this->thread('thr_general');
    $id = $this->pages()->store($this->schema('Gone'));
    $this->thread('thr_gone', $id, 'en');
    $this->container->get('entity_type.manager')->getStorage('node')->load((int) $id)->delete();

    $this->assertNull($this->stateOf('thr_general'));
    $this->assertNull($this->stateOf('thr_gone'));
  }

}
