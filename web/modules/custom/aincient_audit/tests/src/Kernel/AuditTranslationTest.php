<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_audit\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\Tests\aincient_pages\Kernel\EditorialWorkflowTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Checks opened from a TRANSLATION audits that translation (M3, finding B).
 *
 * The console deep link now carries the langcode
 * (`/atelier/checks/node/5/de` → `?langcode=de` on the report endpoint), and
 * {@see \Drupal\aincient_audit\Controller\AuditController::report} loads that
 * translation's latest revision. This pins the engine end of that contract: the
 * report envelope must describe the GERMAN page, not the English source — before
 * the fix a German reader's audit graded the English title.
 *
 * @group aincient_audit
 * @covers \Drupal\aincient_audit\AuditEngine::audit
 */
#[RunTestsInSeparateProcesses]
final class AuditTranslationTest extends KernelTestBase {

  use EditorialWorkflowTestTrait;
  use UserCreationTrait;

  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'options',
    'filter',
    'node',
    'language',
    'content_translation',
    'key',
    'metatag',
    'token',
    'path',
    'path_alias',
    'workflows',
    'content_moderation',
    'flowdrop_ui_components',
    'flowdrop',
    'flowdrop_node_category',
    'flowdrop_node_type',
    'flowdrop_node_processor',
    'flowdrop_workflow',
    'flowdrop_orchestration',
    'flowdrop_runtime',
    'flowdrop_pipeline',
    'flowdrop_job',
    'flowdrop_session',
    'flowdrop_interrupt',
    'flowdrop_memory',
    'aincient_core',
    'aincient_pages',
    'aincient_audit',
    'aincient_flows',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('configurable_language');
    $this->installEntitySchema('content_moderation_state');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('flowdrop_workflow');
    $this->installEntitySchema('flowdrop_node_type');
    $this->installEntitySchema('flowdrop_pipeline');
    $this->installEntitySchema('flowdrop_job');
    $this->installEntitySchema('flowdrop_session');
    $this->installEntitySchema('flowdrop_session_message');
    $this->installEntitySchema('flowdrop_interrupt');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'node', 'language', 'content_translation', 'aincient_pages', 'flowdrop_node_processor']);

    ConfigurableLanguage::createFromLangcode('de')->save();

    // The translatable page-schema fields (aincient_pages ships them as base
    // config on a real install; the kernel container only gets the node type).
    foreach (['field_page_structure', 'field_page_content'] as $name) {
      if (FieldStorageConfig::loadByName('node', $name)) {
        continue;
      }
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
    if (!FieldStorageConfig::loadByName('node', 'field_layout_mode')) {
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
    }

    \Drupal::service('content_translation.manager')->setEnabled('node', 'aincient_page', TRUE);

    if (\Drupal::entityTypeManager()->getStorage('workflow')->load('aincient_editorial') === NULL) {
      $this->setUpEditorialWorkflow(['aincient_page']);
    }

    $this->config('aincient_pages.settings')
      ->set('translation', ['default_mode' => 'symmetric', 'allow_divergence' => TRUE])
      ->save();

    // A user who may write the translation — PageStore::publish gates the
    // second-language write on the editorial transitions.
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

    // The SHIPPED policy config (node type + both workflows) from config/sync,
    // so the report is produced by the real definitions.
    $this->importShippedConfig('flowdrop_node_type', 'flowdrop_node_type.flowdrop_node_type.aincient_flows_policy_check');
    $this->importShippedConfig('flowdrop_workflow', 'flowdrop_workflow.flowdrop_workflow.aincient_policy_seo');
    $this->importShippedConfig('flowdrop_workflow', 'flowdrop_workflow.flowdrop_workflow.aincient_policy_links');
    $this->installConfig(['aincient_audit']);
  }

  /** Create a config entity from its shipped config/sync YAML. */
  private function importShippedConfig(string $entityTypeId, string $configName): void {
    $path = DRUPAL_ROOT . '/../config/sync/' . $configName . '.yml';
    $data = Yaml::decode((string) file_get_contents($path));
    \Drupal::entityTypeManager()->getStorage($entityTypeId)->create($data)->save();
  }

  /** A minimal landing schema carrying $title. */
  private function schema(string $title): array {
    return ['type' => 'landing', 'title' => $title, 'sections' => []];
  }

  /**
   * The `de` head revision audits as the GERMAN page; `en` stays English.
   */
  public function testAuditFollowsTheRequestedTranslation(): void {
    /** @var \Drupal\aincient_pages\PageStore $store */
    $store = $this->container->get('aincient_pages.store');
    $nid = $store->store($this->schema('Our Story'));
    $store->publish($nid, $this->schema('Our Story'));
    $store->publish($nid, $this->schema('Unsere Geschichte'), 'de');

    $moderation = $this->container->get('aincient_pages.moderation');
    $engine = $this->container->get('aincient_audit.engine');

    // What AuditController::report() does for `?langcode=de`.
    $de = $engine->audit($moderation->loadLatestRevision($nid, 'aincient_page', 'de'));
    $this->assertSame('Unsere Geschichte', $de['title']);

    // …and with no langcode (the source), unchanged.
    $en = $engine->audit($moderation->loadLatestRevision($nid, 'aincient_page'));
    $this->assertSame('Our Story', $en['title']);

    // Same page, so the report is about ONE node — only the language differs.
    $this->assertSame($nid, $de['node_id']);
    $this->assertSame($en['node_id'], $de['node_id']);
  }

  /**
   * The Links check grades the TRANSLATION's links, not the source's.
   *
   * Regression (review of PR #56): the check rendered the schema via the
   * stateless seam with no language, so a German page's in-page links were
   * judged against the ENGLISH render. Link targets are structural (shared by a
   * symmetric translation) while copy is per-language, so a `#pricing` CTA that
   * resolves against the English heading slug is dangling on the German page,
   * whose heading slugs to `preise` — exactly the break the English render hid.
   */
  public function testLinksCheckRendersTheRequestedTranslation(): void {
    /** @var \Drupal\aincient_pages\PageStore $store */
    $store = $this->container->get('aincient_pages.store');
    $en = ['type' => 'landing', 'title' => 'Plans', 'sections' => [
      ['component' => 'markdown', 'props' => ['markdown' => "## Pricing\n\nBody."]],
      ['component' => 'cta', 'props' => ['heading' => 'Get it', 'cta_label' => 'Buy', 'cta_url' => '#pricing']],
    ]];
    $nid = $store->store($en);
    $store->publish($nid, $en);
    // The German copy, on the SAME slots (the content overlay is keyed by slot
    // id): only the heading changes, so its slug becomes `preise`.
    $de = $store->load($nid);
    $de['title'] = 'Tarife';
    $de['sections'][0]['props']['markdown'] = "## Preise\n\nText.";
    $store->publish($nid, $de, 'de');

    $moderation = $this->container->get('aincient_pages.moderation');
    $check = $this->container->get('aincient_audit.check.internal_links');
    $ids = fn (array $findings): array => array_column($findings, 'id');

    $deIds = $ids($check->evaluate($moderation->loadLatestRevision($nid, 'aincient_page', 'de')));
    $this->assertContains('links.fragment:pricing', $deIds, 'The German page must be graded on its own headings.');

    $enIds = $ids($check->evaluate($moderation->loadLatestRevision($nid, 'aincient_page')));
    $this->assertNotContains('links.fragment:pricing', $enIds, 'On the English page the anchor resolves.');
  }

}
