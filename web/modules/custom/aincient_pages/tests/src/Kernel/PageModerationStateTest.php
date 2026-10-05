<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Kernel;

use Drupal\aincient_pages\PageStore;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The operator's `list_pages` table and `find_reference` lines report the
 * moderation state of the CURRENT content-language translation's latest revision.
 *
 * @group aincient
 * @covers \Drupal\aincient_pages\PageModerationState
 * @covers \Drupal\aincient_pages\Plugin\AiCapability\ListPages
 * @covers \Drupal\aincient_pages\Plugin\AiCapability\FindReference
 */
#[RunTestsInSeparateProcesses]
final class PageModerationStateTest extends KernelTestBase {

  use EditorialWorkflowTestTrait;
  use UserCreationTrait;

  protected static $modules = [
    'system', 'user', 'field', 'text', 'node', 'language', 'content_translation',
    'workflows', 'content_moderation', 'aincient_core', 'aincient_pages',
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
      'administer aincient pages',
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

  private function schema(string $marker): array {
    return [
      'type' => 'landing',
      'title' => $marker,
      'sections' => [['component' => 'hero', 'props' => ['heading' => $marker]]],
    ];
  }

  /**
   * Make $langcode the current content language (the site default, with no
   * negotiation configured) and drop the cached language state.
   */
  private function useLanguage(string $langcode): void {
    $this->config('system.site')->set('default_langcode', $langcode)->save();
    $this->container->get('language_manager')->reset();
  }

  /**
   * The list_pages state cell for $id, in the current content language.
   */
  private function listedState(string $id): string {
    $tool = $this->container->get('plugin.manager.aincient.capabilities')->createInstance('aincient_pages:list_pages');
    $tool->execute();
    $envelope = json_decode($tool->getReadableOutput(), TRUE);
    $this->assertSame('data_table', $envelope['__widget__']);
    $this->assertContains('state', array_column($envelope['payload']['columns'], 'key'));
    foreach ($envelope['payload']['rows'] as $row) {
      if ($row['id'] === $id) {
        return $row['cells']['state'];
      }
    }
    $this->fail('Page not listed.');
  }

  private function findReferenceText(): string {
    $tool = $this->container->get('plugin.manager.aincient.capabilities')->createInstance('aincient_pages:find_reference');
    // Pages only: the test site has no media entity type.
    $tool->setContextValue('types', 'node');
    $tool->execute();
    return $tool->getReadableOutput();
  }

  /**
   * The envelope carries the workflow facts the lifecycle bar sorts by
   * (DECISIONS 0454): the current state's weight, and per transition its
   * weight plus the target state's weight / published / default-revision flags.
   */
  public function testEnvelopeCarriesWorkflowFactsForTheLifecycleBar(): void {
    $moderation = $this->container->get('aincient_pages.moderation');
    $id = $this->store()->store($this->schema('Facts'));

    $draft = $moderation->legibility($moderation->loadHead($id, 'aincient_page'), TRUE);
    $this->assertSame(0, $draft['state_weight']);
    $this->assertFalse($draft['state_published']);
    $byId = array_column($draft['transitions'], NULL, 'id');
    foreach (['create_new_draft', 'publish', 'submit_for_review'] as $tid) {
      $this->assertArrayHasKey($tid, $byId, "$tid is offered from Draft.");
    }
    $this->assertSame(1, $byId['submit_for_review']['to_weight']);
    $this->assertFalse($byId['submit_for_review']['to_published']);
    $this->assertFalse($byId['submit_for_review']['to_default_revision']);
    $this->assertTrue($byId['publish']['to_published']);
    $this->assertTrue($byId['publish']['to_default_revision']);
    $this->assertIsInt($byId['publish']['weight']);

    $this->store()->publish($id);
    $live = $moderation->legibility($moderation->loadHead($id, 'aincient_page'), TRUE);
    $this->assertSame(2, $live['state_weight']);
    $this->assertTrue($live['state_published']);
    $archive = array_column($live['transitions'], NULL, 'id')['archive'];
    // Archive takes the page off the site: a default revision that isn't published.
    $this->assertFalse($archive['to_published']);
    $this->assertTrue($archive['to_default_revision']);
    $this->assertSame(3, $archive['to_weight']);
  }

  public function testDraftPageReadsDraft(): void {
    $id = $this->store()->store($this->schema('New'));
    $this->assertSame('Draft', $this->listedState($id));
  }

  public function testPublishedPageReadsPublished(): void {
    $id = $this->store()->store($this->schema('Live'));
    $this->store()->publish($id, $this->schema('Live'));
    $this->assertSame('Published', $this->listedState($id));
    $this->assertStringContainsString('state: Published', $this->findReferenceText());
  }

  /**
   * A published page with a forward draft reports the latest state AND that a
   * published copy is still live; find_reference says the same.
   */
  public function testForwardDraftOverPublishedReportsBoth(): void {
    $id = $this->store()->store($this->schema('Live'));
    $this->store()->publish($id, $this->schema('Live'));
    $this->store()->saveDraft($this->schema('WIP'), $id);

    $this->assertSame('Draft · published copy live', $this->listedState($id));
    $this->assertStringContainsString('state: Draft · published copy live', $this->findReferenceText());

    $this->store()->publish($id, $this->schema('WIP'));
    $this->assertSame('Published', $this->listedState($id));
  }

  /**
   * A DE-only draft over a published EN page reports per language.
   */
  public function testGermanDraftOverPublishedEnglishReportsPerLanguage(): void {
    $id = $this->store()->store($this->schema('Hello'));
    $this->store()->publish($id, $this->schema('Hello'));
    $this->store()->saveDraft($this->schema('Hallo'), $id, 'de');

    $this->useLanguage('en');
    $this->assertSame('Published', $this->listedState($id));

    $this->useLanguage('de');
    $this->assertSame('Draft', $this->listedState($id));
  }

}
