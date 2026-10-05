<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_audit\Kernel;

use Drupal\aincient_audit\AuditTarget;
use Drupal\Component\Serialization\Yaml;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\Tests\aincient_pages\Kernel\EditorialWorkflowTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Which copy of a page Checks audits: the requested LANGUAGE and REVISION.
 *
 * Revision (DECISIONS 0450): the live page by default, the latest saved draft
 * on request, each falling to the other copy when the requested one doesn't
 * exist — and the report's `audited` block names the copy actually read. The
 * policy workflows re-load the page themselves, so the findings must come from
 * that same copy, not just the envelope.
 *
 * Language (M3, finding B): Checks opened from a TRANSLATION audits that
 * translation.
 * The console deep link now carries the langcode
 * (`/atelier/checks/node/5/de` → `?langcode=de` on the report endpoint), and
 * {@see \Drupal\aincient_audit\Controller\AuditController::report} loads that
 * translation's latest revision. This pins the engine end of that contract: the
 * report envelope must describe the GERMAN page, not the English source — before
 * the fix a German reader's audit graded the English title.
 *
 * @group aincient_audit
 * @covers \Drupal\aincient_audit\AuditEngine::audit
 * @covers \Drupal\aincient_audit\AuditTarget
 */
#[RunTestsInSeparateProcesses]
final class AuditTargetTest extends KernelTestBase {

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
    $this->importShippedConfig('flowdrop_node_type', 'flowdrop_node_type.flowdrop_node_type.aincient_audit_policy_check');
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


  /** A landing page whose CTA points at #$fragment — no heading carries it. */
  private function withBrokenAnchor(string $title, string $fragment): array {
    return ['type' => 'landing', 'title' => $title, 'sections' => [
      ['component' => 'cta', 'props' => ['heading' => 'Go', 'cta_label' => 'Go', 'cta_url' => '#' . $fragment]],
    ]];
  }

  /** The finding ids of a report, across every check. */
  private function findingIds(array $report): array {
    return array_column(array_merge(...array_column($report['checks'], 'findings')), 'id');
  }

  /**
   * Live by default; the draft on request — and the FINDINGS come from the same
   * copy as the envelope (the policy workflows re-load through AuditTarget).
   */
  public function testLiveByDefaultDraftOnRequest(): void {
    /** @var \Drupal\aincient_pages\PageStore $store */
    $store = $this->container->get('aincient_pages.store');
    $nid = $store->store($this->withBrokenAnchor('Our Story', 'live-anchor'));
    $store->publish($nid, $this->withBrokenAnchor('Our Story', 'live-anchor'));
    // Edit the LOADED page: sections are keyed by slot id, so a fresh schema
    // would not replace the CTA.
    $revised = $store->load($nid);
    $revised['title'] = 'Our Story, revised';
    $revised['sections'][0]['props']['cta_url'] = '#draft-anchor';
    $store->saveDraft($revised, $nid);

    $target = $this->container->get('aincient_audit.target');
    $engine = $this->container->get('aincient_audit.engine');

    $live = $engine->audit($target->load($nid));
    $this->assertSame('Our Story', $live['title']);
    $this->assertSame('live', $live['audited']['requested']);
    $this->assertSame('live', $live['audited']['revision']);
    $this->assertContains('links.fragment:live-anchor', $this->findingIds($live));
    $this->assertNotContains('links.fragment:draft-anchor', $this->findingIds($live));

    $draft = $engine->audit($target->load($nid, AuditTarget::DRAFT), AuditTarget::DRAFT);
    $this->assertSame('Our Story, revised', $draft['title']);
    $this->assertSame('draft', $draft['audited']['requested']);
    $this->assertSame('draft', $draft['audited']['revision']);
    $this->assertNotSame($live['audited']['revision_id'], $draft['audited']['revision_id']);
    $this->assertContains('links.fragment:draft-anchor', $this->findingIds($draft));
    $this->assertNotContains('links.fragment:live-anchor', $this->findingIds($draft));

    // The header's language switcher (`hreflang` links) is built from the
    // CURRENT request — here the CLI's `<none>` route — so it must not be
    // graded as page content (it once failed as `links.broken:/%3Cnone%3E`).
    $broken = array_filter(array_merge($this->findingIds($live), $this->findingIds($draft)), fn (string $id): bool => str_starts_with($id, 'links.broken:'));
    $this->assertSame([], array_values($broken));
  }

  /**
   * The unsaved draft (DECISIONS 0453): a schema written onto a CLONE of the
   * latest revision is graded in memory — title and links included — saves
   * nothing, and grades exactly as the same schema does once saved.
   *
   * @covers \Drupal\aincient_audit\AuditEngine::auditUnsaved
   */
  public function testUnsavedDraftIsGradedInMemory(): void {
    /** @var \Drupal\aincient_pages\PageStore $store */
    $store = $this->container->get('aincient_pages.store');
    $nid = $store->store($this->withBrokenAnchor('Our Story', 'live-anchor'));
    $store->publish($nid, $this->withBrokenAnchor('Our Story', 'live-anchor'));

    $target = $this->container->get('aincient_audit.target');
    $engine = $this->container->get('aincient_audit.engine');
    $nodes = $this->container->get('entity_type.manager')->getStorage('node');
    $head = $target->load($nid, AuditTarget::DRAFT);
    $revisions = $nodes->revisionIds($head);

    $schema = $store->load($nid);
    $schema['title'] = 'Our Story, staged';
    $schema['sections'][0]['props']['cta_url'] = '#staged-anchor';
    $draft = clone $head;
    $store->writeSchema($draft, $schema);
    $unsaved = $engine->auditUnsaved($draft);

    $this->assertSame('Our Story, staged', $unsaved['title']);
    $this->assertSame(['draft', 'unsaved'], [$unsaved['audited']['requested'], $unsaved['audited']['revision']]);
    $this->assertContains('links.fragment:staged-anchor', $this->findingIds($unsaved));
    $this->assertNotContains('links.fragment:live-anchor', $this->findingIds($unsaved));

    // Nothing was written: no new revision, and the loaded head is untouched.
    $nodes->resetCache();
    $this->assertSame($revisions, $nodes->revisionIds($head));
    $this->assertSame('Our Story', $target->load($nid, AuditTarget::DRAFT)->label());
    $this->assertSame('Our Story', $head->label());

    // Saving the same schema grades identically: in memory is what Save stores.
    $store->saveDraft($schema, $nid);
    $saved = $engine->audit($target->load($nid, AuditTarget::DRAFT), AuditTarget::DRAFT);
    $this->assertSame($saved['checks'], $unsaved['checks']);
    $this->assertSame($saved['summary'], $unsaved['summary']);
  }

  /**
   * A translation's unsaved draft is graded as that translation.
   *
   * @covers \Drupal\aincient_audit\AuditEngine::auditUnsaved
   */
  public function testUnsavedTranslationDraft(): void {
    /** @var \Drupal\aincient_pages\PageStore $store */
    $store = $this->container->get('aincient_pages.store');
    $nid = $store->store($this->schema('Our Story'));
    $store->publish($nid, $this->schema('Our Story'));
    $store->publish($nid, $this->schema('Unsere Geschichte'), 'de');

    $head = $this->container->get('aincient_audit.target')->load($nid, AuditTarget::DRAFT, 'de');
    $draft = clone $head;
    $store->writeSchema($draft, $this->schema('Unsere Geschichte, neu'));
    $report = $this->container->get('aincient_audit.engine')->auditUnsaved($draft);

    $this->assertSame('Unsere Geschichte, neu', $report['title']);
    $this->assertSame('de', $report['audited']['langcode']);
    $this->assertSame('Our Story', $store->load($nid)['title']);
  }

  /**
   * A requested copy that doesn't exist falls to the other one, and the report
   * says so: never published → the draft; no pending draft → the live page.
   */
  public function testMissingCopyFallsToTheOther(): void {
    /** @var \Drupal\aincient_pages\PageStore $store */
    $store = $this->container->get('aincient_pages.store');
    $target = $this->container->get('aincient_audit.target');
    $engine = $this->container->get('aincient_audit.engine');

    $nid = $store->store($this->schema('Not yet live'));
    $report = $engine->audit($target->load($nid));
    $this->assertSame(['live', 'draft'], [$report['audited']['requested'], $report['audited']['revision']]);

    $store->publish($nid, $this->schema('Now live'));
    $report = $engine->audit($target->load($nid, AuditTarget::DRAFT), AuditTarget::DRAFT);
    $this->assertSame('Now live', $report['title']);
    $this->assertSame(['draft', 'live'], [$report['audited']['requested'], $report['audited']['revision']]);
  }

  /**
   * A translation that exists only as a draft has no live copy: a live request
   * for it reads the German draft, never the English live page.
   */
  public function testUnpublishedTranslationFallsToItsDraft(): void {
    /** @var \Drupal\aincient_pages\PageStore $store */
    $store = $this->container->get('aincient_pages.store');
    $nid = $store->store($this->schema('Our Story'));
    $store->publish($nid, $this->schema('Our Story'));
    $store->saveDraft($this->schema('Unsere Geschichte'), $nid, 'de');

    $node = $this->container->get('aincient_audit.target')->load($nid, AuditTarget::LIVE, 'de');
    $report = $this->container->get('aincient_audit.engine')->audit($node);
    $this->assertSame('Unsere Geschichte', $report['title']);
    $this->assertSame('draft', $report['audited']['revision']);
    $this->assertSame('de', $report['audited']['langcode']);
  }

  /**
   * Only the names "live" and "draft" mean anything; anything else is live.
   */
  public function testNormalize(): void {
    $this->assertSame('draft', AuditTarget::normalize('draft'));
    foreach (['live', NULL, '', 'DRAFT', 'latest', 1] as $value) {
      $this->assertSame('live', AuditTarget::normalize($value));
    }
  }

}
