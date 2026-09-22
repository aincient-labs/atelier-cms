<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Kernel;

use Drupal\aincient_pages\PageStore;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\NodeType;
use Drupal\pathauto\Entity\PathautoPattern;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * A published TRANSLATION gets its own URL alias, from its own title.
 *
 * Pathauto's entity hooks alias only the entity object they are handed, and
 * every PageStore write saves the SOURCE-language node object (which persists
 * all its translations). So a German translation used to keep only the English
 * alias row: /de/<alias> 404'd and the page lived at /de/node/N alone. The
 * store now syncs the written translation's alias itself.
 *
 * @group aincient
 * @covers \Drupal\aincient_pages\PageStore
 */
#[RunTestsInSeparateProcesses]
final class PageTranslationAliasTest extends KernelTestBase {

  use EditorialWorkflowTestTrait;
  use UserCreationTrait;

  protected static $modules = [
    'system', 'user', 'field', 'text', 'node', 'language', 'content_translation',
    'workflows', 'content_moderation',
    // The alias stack under test.
    'token', 'path', 'path_alias', 'pathauto',
    // aincient_core hard-depends on file (ContextLedgerEntry references File
    // entities) and pathauto/token walk every entity type's fields on save.
    'file',
    'aincient_core', 'aincient_pages',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('configurable_language');
    $this->installEntitySchema('content_moderation_state');
    $this->installEntitySchema('path_alias');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'language', 'content_translation', 'pathauto']);

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

    // The flat alias pattern the distribution ships (see PageAliasTest).
    PathautoPattern::create([
      'id' => 'aincient_page',
      'label' => 'AIncient page',
      'type' => 'canonical_entities:node',
      'pattern' => '/[node:title]',
      'selection_criteria' => [],
    ])->save();

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
   * The stored alias for a node id in a language, or NULL if there is none.
   */
  private function aliasFor(string $id, string $langcode): ?string {
    $matches = \Drupal::entityTypeManager()->getStorage('path_alias')->loadByProperties([
      'path' => '/node/' . $id,
      'langcode' => $langcode,
    ]);
    $alias = reset($matches);
    return $alias ? $alias->getAlias() : NULL;
  }

  /**
   * Publishing a `de` translation writes a `de` alias from the German title,
   * and leaves the English alias untouched.
   */
  public function testPublishedTranslationGetsItsOwnAlias(): void {
    $id = $this->store()->store($this->schema('Our Story'));
    $this->store()->publish($id, $this->schema('Our Story'));
    $this->assertSame('/our-story', $this->aliasFor($id, 'en'));

    $this->store()->publish($id, $this->schema('Unsere Geschichte'), 'de');

    $this->assertSame('/unsere-geschichte', $this->aliasFor($id, 'de'));
    // The source alias is never regenerated by a translation write.
    $this->assertSame('/our-story', $this->aliasFor($id, 'en'));
  }

  /**
   * The same holds for the non-moderated write path (PageStore::update).
   */
  public function testUpdateOnATranslationAliasesIt(): void {
    $id = $this->store()->store($this->schema('Contact'));
    $this->store()->publish($id, $this->schema('Contact'));

    $this->assertTrue($this->store()->update($id, $this->schema('Kontakt'), 'de'));
    $this->assertSame('/kontakt', $this->aliasFor($id, 'de'));
    $this->assertSame('/contact', $this->aliasFor($id, 'en'));
  }

  /**
   * A German draft written while the source is published is a FORWARD draft:
   * pathauto skips non-default revisions, so it correctly has no alias yet.
   * Publishing it through the editorial workflow (submit → approve) names no
   * langcode — the store must still alias every translation on that save.
   */
  public function testTranslationPublishedByEditorialTransitionGetsItsAlias(): void {
    $id = $this->store()->store($this->schema('Pricing'));
    $this->store()->publish($id, $this->schema('Pricing'));
    $this->assertSame('/pricing', $this->aliasFor($id, 'en'));

    $this->assertNotNull($this->store()->saveDraft($this->schema('Preise'), $id, 'de'));
    $this->assertNull($this->aliasFor($id, 'de'), 'A forward draft has no alias.');

    $this->assertNotNull($this->store()->transition($id, 'submit_for_review'));
    $this->assertNull($this->aliasFor($id, 'de'), 'Still a non-default revision.');

    $this->assertNotNull($this->store()->transition($id, 'approve'));
    $this->assertSame('/preise', $this->aliasFor($id, 'de'));
    $this->assertSame('/pricing', $this->aliasFor($id, 'en'));
  }

  /**
   * aincient_pages_update_10016() backfills a translation whose alias is
   * missing — the state every site published before the store synced aliases.
   */
  public function testUpdateHookBackfillsMissingTranslationAliases(): void {
    $id = $this->store()->store($this->schema('Imprint'));
    $this->store()->publish($id, $this->schema('Imprint'));
    $this->store()->publish($id, $this->schema('Impressum'), 'de');

    // Simulate the pre-fix state: the de alias row never existed.
    $storage = \Drupal::entityTypeManager()->getStorage('path_alias');
    $storage->delete($storage->loadByProperties(['path' => '/node/' . $id, 'langcode' => 'de']));
    $this->assertNull($this->aliasFor($id, 'de'));

    \Drupal::moduleHandler()->loadInclude('aincient_pages', 'install');
    $sandbox = [];
    aincient_pages_update_10016($sandbox);

    $this->assertSame('/impressum', $this->aliasFor($id, 'de'));
    $this->assertSame('/imprint', $this->aliasFor($id, 'en'));
  }

}
