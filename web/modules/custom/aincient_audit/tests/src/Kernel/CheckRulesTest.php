<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_audit\Kernel;

use Drupal\aincient_pages\SiteIdentity;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\Tests\aincient_pages\Kernel\EditorialWorkflowTestTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * M5: two new Checks rules added to the existing `links` and `seo` policies —
 * a same-page fragment check (DECISIONS 0413's anchor/heading ids) and a
 * global missing-favicon warning. Both live INSIDE the existing checks (no
 * new check service, no new policy config entity — see
 * {@see \Drupal\Tests\aincient_audit\Kernel\PolicyEvaluatorTest} for why: a
 * new policy needs its own FlowDrop workflow + seeding, out of scope here).
 *
 * Calls the check services directly (`aincient_audit.check.internal_links`,
 * `aincient_audit.check.seo_meta`) — the same seam {@see PolicyEvaluatorTest}
 * treats as the byte-identity baseline for the workflow-backed report.
 *
 * @group aincient_audit
 */
#[RunTestsInSeparateProcesses]
final class CheckRulesTest extends KernelTestBase {

  use EditorialWorkflowTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'options',
    'filter',
    'node',
    'key',
    'metatag',
    'token',
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

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('content_moderation_state');
    $this->installEntitySchema('flowdrop_workflow');
    $this->installEntitySchema('flowdrop_node_type');
    $this->installEntitySchema('flowdrop_pipeline');
    $this->installEntitySchema('flowdrop_job');
    $this->installEntitySchema('flowdrop_session');
    $this->installEntitySchema('flowdrop_session_message');
    $this->installEntitySchema('flowdrop_interrupt');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'node', 'aincient_pages', 'flowdrop_node_processor']);

    if (\Drupal::entityTypeManager()->getStorage('workflow')->load('aincient_editorial') === NULL) {
      $this->setUpEditorialWorkflow(['aincient_page']);
    }
  }

  /**
   * Create a saved aincient_page with the given sections and return its head
   * revision.
   *
   * @param array<int, array{component: string, props: array<string, mixed>}> $sections
   */
  private function makePage(array $sections): Node {
    /** @var \Drupal\aincient_pages\PageStore $store */
    $store = $this->container->get('aincient_pages.store');
    $nid = $store->store([
      'type' => 'landing',
      'title' => 'Hi',
      'sections' => $sections,
    ]);
    return $this->container->get('aincient_pages.moderation')->loadLatestRevision($nid, 'aincient_page');
  }

  /**
   * Index a finding list by id.
   *
   * @return array<string, array<string, mixed>>
   */
  private function byId(array $findings): array {
    $out = [];
    foreach ($findings as $f) {
      $out[$f['id']] = $f;
    }
    return $out;
  }

  /**
   * (a) A `#pricing` href resolving against a section's `anchor: pricing` is
   * valid — no `links.fragment:` finding, and `links.internal_ok` still
   * reports (the fragment counts as a resolved internal link).
   */
  public function testValidFragmentResolvesAgainstSectionAnchor(): void {
    $node = $this->makePage([
      ['component' => 'hero', 'props' => ['anchor' => 'pricing', 'heading' => 'Pricing']],
      ['component' => 'cta', 'props' => ['heading' => 'Get it', 'cta_label' => 'Buy', 'cta_url' => '#pricing']],
    ]);

    $findings = $this->byId($this->container->get('aincient_audit.check.internal_links')->evaluate($node));

    foreach (array_keys($findings) as $id) {
      $this->assertStringNotContainsString('links.fragment:', $id, 'A valid fragment must not fail.');
    }
    $this->assertArrayHasKey('links.internal_ok', $findings);
    $this->assertSame('pass', $findings['links.internal_ok']['severity']);
  }

  /**
   * (b) A `#nowhere` href with no matching id anywhere in the rendered page
   * is a dangling in-page link — a FAIL keyed `links.fragment:nowhere`.
   */
  public function testDanglingFragmentFails(): void {
    $node = $this->makePage([
      ['component' => 'cta', 'props' => ['heading' => 'Get it', 'cta_label' => 'Buy', 'cta_url' => '#nowhere']],
    ]);

    $findings = $this->byId($this->container->get('aincient_audit.check.internal_links')->evaluate($node));

    $this->assertArrayHasKey('links.fragment:nowhere', $findings);
    $f = $findings['links.fragment:nowhere'];
    $this->assertSame('fail', $f['severity']);
    $this->assertSame('Dangling in-page link', $f['title']);
    $this->assertSame('content', $f['dimension']);
    $this->assertSame(['action' => 'edit_prop', 'target' => ['href' => '#nowhere'], 'aiFixable' => TRUE], $f['remediation']);
    $this->assertArrayNotHasKey('links.internal_ok', $findings, 'A dangling fragment means not everything resolved.');
  }

  /**
   * (c) With no favicon configured (the shipped default — `favicon: ''`), the
   * SEO check reports `seo.favicon` as a WARN.
   */
  public function testMissingFaviconWarns(): void {
    $node = $this->makePage([]);

    $findings = $this->byId($this->container->get('aincient_audit.check.seo_meta')->evaluate($node));

    $this->assertArrayHasKey('seo.favicon', $findings);
    $this->assertSame('warn', $findings['seo.favicon']['severity']);
    $this->assertSame('No favicon set', $findings['seo.favicon']['title']);
    $this->assertArrayNotHasKey('remediation', $findings['seo.favicon'], 'No per-page field to edit — no remediation.');
  }

  /**
   * (d) Once a favicon token is set on site identity, `seo.favicon` PASSes.
   * `favicon()` reads the config value verbatim with no media-entity
   * validation, so a bare `media:1` token (no real media entity) is enough.
   */
  public function testConfiguredFaviconPasses(): void {
    /** @var \Drupal\Core\Config\ConfigFactoryInterface $configFactory */
    $configFactory = $this->container->get('config.factory');
    $configFactory->getEditable(SiteIdentity::CONFIG)->set('favicon', 'media:1')->save();

    $node = $this->makePage([]);
    $findings = $this->byId($this->container->get('aincient_audit.check.seo_meta')->evaluate($node));

    $this->assertArrayHasKey('seo.favicon', $findings);
    $this->assertSame('pass', $findings['seo.favicon']['severity']);
    $this->assertSame('Favicon set', $findings['seo.favicon']['title']);
  }

}
