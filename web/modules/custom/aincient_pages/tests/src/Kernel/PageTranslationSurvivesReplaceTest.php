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
 * CONVERTING a section must not wipe that slot's translations.
 *
 * The per-language content overlay is keyed by the section's stable slot id
 * ({@see \Drupal\aincient_pages\PageSchemaCodec}). So "turn this features
 * band into a grid" done as remove_section + add_section mints a FRESH id, the
 * German overlay row for the old id is orphaned, and the German page silently
 * falls back to the English copy. The op grammar now carries the conversion
 * itself — update_section {component} and add_section {replaces} — which keeps
 * the id, and with it the translation.
 *
 * @group aincient
 * @covers \Drupal\aincient_pages\PageStore
 */
#[RunTestsInSeparateProcesses]
final class PageTranslationSurvivesReplaceTest extends KernelTestBase {

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
   * A two-section EN landing page: a hero plus a $second band.
   */
  private function sourceSchema(string $second = 'features'): array {
    return [
      'type' => 'landing',
      'title' => 'Our Craft',
      'sections' => [
        ['component' => 'hero', 'props' => ['heading' => 'Our Craft']],
        ['component' => $second, 'props' => ['heading' => 'What we do']],
      ],
    ];
  }

  /**
   * Publish EN, then publish a `de` translation whose second section carries a
   * German heading. Returns the stored id and the published EN schema.
   *
   * @return array{0: string, 1: array}
   */
  private function publishBilingual(): array {
    $id = $this->store()->store($this->sourceSchema());
    $this->assertNotNull($this->store()->publish($id, $this->sourceSchema()));
    $loaded = $this->store()->load($id);
    $this->assertSame('What we do', $loaded['sections'][1]['props']['heading']);

    $de = $loaded;
    $de['title'] = 'Unser Handwerk';
    $de['sections'][0]['props']['heading'] = 'Unser Handwerk';
    $de['sections'][1]['props']['heading'] = 'Was wir tun';
    $this->store()->publish($id, $de, 'de');

    $this->assertSame('Was wir tun', $this->store()->load($id, 'de')['sections'][1]['props']['heading']);
    return [$id, $loaded];
  }

  /**
   * Converting the second section with update_section {component} keeps its id,
   * so the German heading is still there afterwards.
   */
  public function testConvertingASectionKeepsItsTranslation(): void {
    [$id, $source] = $this->publishBilingual();
    $slot = $source['sections'][1]['id'];

    $converted = $this->store()->applyOps($source, [
      ['op' => 'update_section', 'id' => $slot, 'component' => 'grid'],
    ]);
    $this->assertSame([], $converted['rejected']);
    $this->store()->publish($id, $converted['schema']);

    $de = $this->store()->load($id, 'de');
    $this->assertSame($slot, $de['sections'][1]['id']);
    $this->assertSame('grid', $de['sections'][1]['component']);
    $this->assertSame('Was wir tun', $de['sections'][1]['props']['heading']);
    // The untouched slot is unaffected.
    $this->assertSame('Unser Handwerk', $de['sections'][0]['props']['heading']);
  }

  /**
   * add_section {replaces} is the same guarantee through the other spelling.
   */
  public function testReplacingASectionInPlaceKeepsItsTranslation(): void {
    [$id, $source] = $this->publishBilingual();
    $slot = $source['sections'][1]['id'];

    $converted = $this->store()->applyOps($source, [
      ['op' => 'add_section', 'component' => 'grid', 'replaces' => $slot, 'props' => ['heading' => 'What we make']],
    ]);
    $this->assertSame([], $converted['rejected']);
    $this->store()->publish($id, $converted['schema']);

    $de = $this->store()->load($id, 'de');
    $this->assertCount(2, $de['sections']);
    $this->assertSame($slot, $de['sections'][1]['id']);
    $this->assertSame('grid', $de['sections'][1]['component']);
    $this->assertSame('Was wir tun', $de['sections'][1]['props']['heading']);
  }

  /**
   * The bug this fixes, kept as a documenting test: the OLD spelling of a
   * conversion (remove_section + add_section) mints a new slot id, so the
   * German copy is orphaned and the translation falls back to English.
   */
  public function testRemoveThenAddStillLosesTheTranslation(): void {
    [$id, $source] = $this->publishBilingual();
    $slot = $source['sections'][1]['id'];

    $rebuilt = $this->store()->applyOps($source, [
      ['op' => 'remove_section', 'id' => $slot],
      ['op' => 'add_section', 'component' => 'grid', 'props' => ['heading' => 'What we do']],
    ]);
    $this->assertSame([], $rebuilt['rejected']);
    $this->store()->publish($id, $rebuilt['schema']);

    $de = $this->store()->load($id, 'de');
    $this->assertNotSame($slot, $de['sections'][1]['id']);
    $this->assertSame('What we do', $de['sections'][1]['props']['heading']);
  }

}
