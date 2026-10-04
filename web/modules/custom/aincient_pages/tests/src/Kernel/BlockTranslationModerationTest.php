<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Kernel;

use Drupal\aincient_pages\BlockStore;
use Drupal\Component\Serialization\Yaml;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\language\Entity\ContentLanguageSettings;
use Drupal\media\Entity\MediaType;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Global blocks moderate their translations independently (M9): publishing a
 * block's DE translation never publishes its pending EN draft, and the
 * base_vid pin is per language — the block mirror of the page cases in
 * {@see WorkflowModerationTest}.
 *
 * Needs content translation ENABLED on the `block` media bundle: isolation
 * rides on core's translation-aware createRevision(), which only merges
 * translations of a translatable bundle. The bundle's language settings and
 * its two layering fields are created from the SHIPPED config/sync YAML (not
 * enabled in-test), so a regression there fails this build.
 *
 * @group aincient
 * @covers \Drupal\aincient_pages\BlockStore
 * @covers \Drupal\aincient_pages\NodeModeration
 */
#[RunTestsInSeparateProcesses]
final class BlockTranslationModerationTest extends KernelTestBase {

  use EditorialWorkflowTestTrait;
  use UserCreationTrait;

  protected static $modules = [
    'system', 'user', 'field', 'text', 'file', 'image', 'media', 'node',
    'language', 'content_translation',
    'workflows', 'content_moderation', 'aincient_core', 'aincient_pages',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installEntitySchema('node');
    $this->installEntitySchema('configurable_language');
    $this->installEntitySchema('content_moderation_state');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('file', ['file_usage']);
    $this->installConfig(['system', 'field', 'media', 'language', 'content_translation']);

    ConfigurableLanguage::createFromLangcode('de')->save();

    // The page bundle exists for the shared PageStore; the block media type
    // shares the layering field names.
    if (!NodeType::load('aincient_page')) {
      NodeType::create(['type' => 'aincient_page', 'name' => 'Aincient page'])->save();
    }
    foreach (['field_page_structure', 'field_page_content'] as $name) {
      if (!FieldStorageConfig::loadByName('media', $name)) {
        FieldStorageConfig::create([
          'field_name' => $name,
          'entity_type' => 'media',
          'type' => 'string_long',
          'translatable' => TRUE,
        ])->save();
      }
    }
    if (!MediaType::load('block')) {
      MediaType::create([
        'id' => 'block',
        'label' => 'Global block',
        'source' => 'aincient_block_fragment',
        'source_configuration' => ['source_field' => 'field_page_content'],
      ])->save();
    }
    foreach (['field_page_structure', 'field_page_content'] as $name) {
      // The media source may already have attached its source field; either
      // way, translatability is what the shipped YAML says.
      $shipped = $this->sync('field.field.media.block.' . $name);
      $field = FieldConfig::loadByName('media', 'block', $name);
      $field === NULL
        ? FieldConfig::create($shipped)->save()
        : $field->setTranslatable((bool) $shipped['translatable'])->save();
    }
    ContentLanguageSettings::load('media.block')?->delete();
    ContentLanguageSettings::create($this->sync('language.content_settings.media.block'))->save();
    $this->assertTrue(
      \Drupal::service('content_translation.manager')->isEnabled('media', 'block'),
      'The shipped language.content_settings.media.block enables content translation.',
    );
    $this->setUpEditorialWorkflow([], ['block']);

    $this->setUpCurrentUser(['uid' => 2], [
      'view any unpublished content',
      'view latest version',
      'view media',
      'update any media',
      'create block media',
      'edit any block media',
      'use aincient_editorial transition create_new_draft',
      'use aincient_editorial transition submit_for_review',
      'use aincient_editorial transition approve',
      'use aincient_editorial transition publish',
    ]);
  }

  /**
   * One config object exactly as config/sync ships it.
   */
  private function sync(string $name): array {
    $path = DRUPAL_ROOT . '/../config/sync/' . $name . '.yml';
    $this->assertFileExists($path);
    $data = Yaml::decode((string) file_get_contents($path));
    unset($data['_core']);
    return $data;
  }

  private function blocks(): BlockStore {
    return $this->container->get('aincient_pages.block_store');
  }

  /**
   * A one-section fragment titled $marker.
   */
  private function fragment(string $marker): array {
    return [
      'title' => $marker,
      'sections' => [['component' => 'cta', 'props' => ['heading' => $marker]]],
    ];
  }

  /**
   * Draft DE, then draft EN, then publish DE: EN's draft stays pending and EN
   * live is unchanged; publishing EN later leaves DE alone. EN and DE pins are
   * independent throughout.
   */
  public function testPublishingOneLanguageIsolatesTheOther(): void {
    $id = $this->blocks()->store($this->fragment('EN old'));
    $this->blocks()->publish($id, $this->fragment('EN old'));
    $this->blocks()->saveDraft($this->fragment('DE old'), $id, 'de');
    $this->blocks()->publish($id, $this->fragment('DE old'), 'de');
    $this->assertSame('EN old', $this->blocks()->load($id, 'en')['title']);
    $this->assertSame('DE old', $this->blocks()->load($id, 'de')['title']);

    $deBase = $this->blocks()->loadLatest($id, 'de')['base_vid'];
    $enBase = $this->blocks()->loadLatest($id, 'en')['base_vid'];
    $de = $this->blocks()->saveDraft($this->fragment('DE draft'), $id, 'de', $deBase);
    // EN's pin is unaffected by the DE save.
    $this->assertNotNull($this->blocks()->saveDraft($this->fragment('EN draft'), $id, 'en', $enBase));

    $this->blocks()->publish($id, NULL, 'de', $de['base_vid']);
    $this->assertSame('DE draft', $this->blocks()->load($id, 'de')['title']);
    $this->assertSame('EN old', $this->blocks()->load($id, 'en')['title'], 'Publishing DE leaves the EN block live copy unchanged.');
    $en = $this->blocks()->loadLatest($id, 'en');
    $this->assertSame('EN draft', $en['schema']['title'], 'The EN draft is still pending.');
    $this->assertTrue($en['has_pending_draft']);

    $this->blocks()->publish($id, NULL, 'en', $en['base_vid']);
    $this->assertSame('EN draft', $this->blocks()->load($id, 'en')['title']);
    $this->assertSame('DE draft', $this->blocks()->load($id, 'de')['title'], 'Publishing EN leaves DE unchanged.');
  }

  /**
   * translationsOf() lists a block's non-source languages for the studio's
   * language picker — including one that exists only as an unpublished draft.
   */
  public function testTranslationsOfCountsDraftOnlyTranslations(): void {
    $id = $this->blocks()->store($this->fragment('EN'));
    $this->blocks()->publish($id, $this->fragment('EN'));
    $this->assertSame([], $this->blocks()->translationsOf($id));

    $this->blocks()->saveDraft($this->fragment('DE draft'), $id, 'de');
    $this->assertSame(['de'], $this->blocks()->translationsOf($id), 'A draft-only translation counts as existing.');
    $this->assertSame([], $this->blocks()->translationsOf('999999'), 'A missing block has none.');
  }

}
