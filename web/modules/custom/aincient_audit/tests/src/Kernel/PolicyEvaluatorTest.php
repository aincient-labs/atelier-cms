<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_audit\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\aincient_pages\Kernel\EditorialWorkflowTestTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The Phase-1b seam end-to-end (DECISIONS 0129).
 *
 * Exercises the whole path — {@see \Drupal\aincient_audit\AuditEngine::audit}
 * → {@see \Drupal\aincient_audit\PolicyEvaluator} → the SHIPPED default policy
 * workflows run through the real SynchronousOrchestrator → the policy_check node
 * → the checks — against the actual `config/sync` workflow definitions (not
 * fixtures), so this also guards that the shipped config stays runnable.
 *
 * Characterization: the workflow-backed report is byte-identical to running the
 * checks directly, plus an additive `policyId`; deterministic policies persist
 * nothing (no pipeline/job/session entities); and the PHP selector prefilter
 * excludes bundles the policies don't target.
 *
 * @coversDefaultClass \Drupal\aincient_audit\PolicyEvaluator
 * @group aincient_audit
 */
#[RunTestsInSeparateProcesses]
final class PolicyEvaluatorTest extends KernelTestBase {

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
    'ai',
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
    'flowdrop_ai_provider',
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

    // Install the SHIPPED policy config (node type + both workflows) from
    // config/sync, so the test runs the real definitions.
    $this->importShippedConfig('flowdrop_node_type', 'flowdrop_node_type.flowdrop_node_type.aincient_flows_policy_check');
    $this->importShippedConfig('flowdrop_workflow', 'flowdrop_workflow.flowdrop_workflow.aincient_policy_seo');
    $this->importShippedConfig('flowdrop_workflow', 'flowdrop_workflow.flowdrop_workflow.aincient_policy_links');

    // Install the SHIPPED default policy entities (Phase 3) — the workflows they
    // depend on are already present above, so their config dependencies are met.
    $this->installConfig(['aincient_audit']);
  }

  /**
   * Create a config entity from its shipped config/sync YAML.
   */
  private function importShippedConfig(string $entityTypeId, string $configName): void {
    $path = DRUPAL_ROOT . '/../config/sync/' . $configName . '.yml';
    $data = Yaml::decode((string) file_get_contents($path));
    \Drupal::entityTypeManager()->getStorage($entityTypeId)->create($data)->save();
  }

  /**
   * Create a saved aincient_page and return its node id.
   */
  private function makePage(): string {
    /** @var \Drupal\aincient_pages\PageStore $store */
    $store = $this->container->get('aincient_pages.store');
    return $store->store([
      'type' => 'landing',
      'title' => 'Hi',
      'sections' => [],
    ]);
  }

  /**
   * The head revision of a page.
   */
  private function head(string $nid): Node {
    return $this->container->get('aincient_pages.moderation')->loadLatestRevision($nid, 'aincient_page');
  }

  /**
   * The report built by running the checks directly (pre-1b behaviour), with
   * `policyId` stamped — the byte-for-byte expectation for the workflow path.
   *
   * @return array<string, mixed>
   */
  private function directReport(string $nid): array {
    $node = $this->head($nid);
    $reg = $this->container->get('aincient_audit.check_registry');
    $checks = [];
    $summary = ['pass' => 0, 'warn' => 0, 'fail' => 0, 'total' => 0];
    foreach ($reg->all() as $id => $check) {
      $findings = $check->evaluate($node);
      foreach ($findings as &$f) {
        $f['policyId'] = $id;
        $summary[$f['severity']]++;
        $summary['total']++;
      }
      unset($f);
      $checks[] = ['key' => $id, 'label' => $check->label(), 'findings' => $findings];
    }
    return [
      'node_id' => $nid,
      'title' => (string) $node->label(),
      'url' => $this->container->get('aincient_pages.store')->url($nid, FALSE),
      'summary' => $summary,
      'checks' => $checks,
    ];
  }

  /**
   * The workflow-backed report equals the direct-check report + policyId.
   *
   * @covers ::evaluate
   */
  public function testWorkflowReportIsByteIdenticalPlusPolicyId(): void {
    $nid = $this->makePage();
    $report = $this->container->get('aincient_audit.engine')->audit($this->head($nid));
    $this->assertSame($this->directReport($nid), $report);

    // Every finding carries policyId == its group key (additive stamp).
    foreach ($report['checks'] as $group) {
      foreach ($group['findings'] as $finding) {
        $this->assertSame($group['key'], $finding['policyId']);
      }
    }
    // The two shipped policies both reported.
    $this->assertSame(['seo', 'links'], array_column($report['checks'], 'key'));
  }

  /**
   * Deterministic policies run synchronously and persist NOTHING.
   *
   * @covers ::evaluate
   */
  public function testDeterministicPoliciesAreDbFree(): void {
    $nid = $this->makePage();
    $etm = \Drupal::entityTypeManager();
    $count = static fn (string $t): int => (int) $etm->getStorage($t)->getQuery()->accessCheck(FALSE)->count()->execute();

    $before = [$count('flowdrop_pipeline'), $count('flowdrop_job'), $count('flowdrop_session')];
    $this->container->get('aincient_audit.engine')->audit($this->head($nid));
    $after = [$count('flowdrop_pipeline'), $count('flowdrop_job'), $count('flowdrop_session')];

    $this->assertSame($before, $after, 'A deterministic policy run must write no pipeline/job/session entities.');
  }

  /**
   * Phase 2 (DECISIONS 0133): every finding carries the `dimension` it touches,
   * and non-pass findings carry a declarative `remediation` descriptor — and
   * those additive fields survive the workflow boundary VERBATIM (the validator
   * passes them through unchanged, so the report stays byte-identical).
   *
   * @covers ::evaluate
   */
  public function testFindingsCarryDimensionAndRemediation(): void {
    $nid = $this->makePage();
    $report = $this->container->get('aincient_audit.engine')->audit($this->head($nid));

    $groups = [];
    foreach ($report['checks'] as $group) {
      $groups[$group['key']] = $group['findings'];
    }

    // Every SEO finding is the `meta` axis; every link finding is `content`.
    foreach ($groups['seo'] as $f) {
      $this->assertSame('meta', $f['dimension'], $f['id'] . ' should be the meta dimension');
    }
    foreach ($groups['links'] as $f) {
      $this->assertSame('content', $f['dimension'], $f['id'] . ' should be the content dimension');
    }

    // The empty page's missing description (FAIL) carries the exact declarative
    // remediation the check authored — proving passthrough is byte-identical
    // across the workflow boundary.
    $byId = [];
    foreach ($groups['seo'] as $f) {
      $byId[$f['id']] = $f;
    }
    $this->assertSame(
      ['action' => 'edit_field', 'field' => 'description', 'input' => 'textarea', 'label' => 'Meta description', 'constraints' => ['min' => 50, 'max' => 160], 'aiFixable' => TRUE],
      $byId['seo.description']['remediation'],
    );

    // No `pass` finding carries a remediation (nothing to fix).
    foreach ($report['checks'] as $group) {
      foreach ($group['findings'] as $f) {
        if ($f['severity'] === 'pass') {
          $this->assertArrayNotHasKey('remediation', $f, $f['id'] . ' (pass) should carry no remediation');
        }
      }
    }
  }

  /**
   * The boundary gate keeps the five base fields but DROPS an unknown dimension
   * and a malformed remediation — the Phase-2 additive fields are validate-or-
   * drop, never a partial/coerced shape (the `brand_validate_slice` posture).
   *
   * @covers ::evaluate
   */
  public function testValidateDropsMalformedAdditiveFields(): void {
    $evaluator = $this->container->get('aincient_audit.policy_evaluator');
    $validate = new \ReflectionMethod($evaluator, 'validate');
    $validate->setAccessible(TRUE);

    $out = $validate->invoke($evaluator, [
      // Unknown dimension + non-array remediation → both dropped, base kept.
      ['id' => 'x', 'severity' => 'warn', 'title' => 't', 'detail' => 'd', 'location' => 'l', 'dimension' => 'bogus', 'remediation' => 'nope'],
      // Valid dimension + valid remediation → both kept verbatim.
      ['id' => 'y', 'severity' => 'fail', 'title' => 't', 'detail' => 'd', 'location' => 'l', 'dimension' => 'meta', 'remediation' => ['action' => 'edit_field', 'field' => 'description', 'aiFixable' => TRUE]],
      // Missing aiFixable → remediation malformed → dropped; dimension kept.
      ['id' => 'z', 'severity' => 'warn', 'title' => 't', 'detail' => 'd', 'location' => 'l', 'dimension' => 'content', 'remediation' => ['action' => 'edit_prop', 'target' => ['href' => '/x']]],
    ]);

    $this->assertSame(['id' => 'x', 'severity' => 'warn', 'title' => 't', 'detail' => 'd', 'location' => 'l'], $out[0]);
    $this->assertSame('meta', $out[1]['dimension']);
    $this->assertSame(['action' => 'edit_field', 'field' => 'description', 'aiFixable' => TRUE], $out[1]['remediation']);
    $this->assertSame('content', $out[2]['dimension']);
    $this->assertArrayNotHasKey('remediation', $out[2]);
  }

  /**
   * The policy storage helper.
   */
  private function policyStorage(): \Drupal\Core\Entity\EntityStorageInterface {
    return $this->container->get('entity_type.manager')->getStorage('aincient_policy');
  }

  /**
   * Phase 3 (DECISIONS 0134): a disabled policy is skipped entirely — its group
   * vanishes from the report; the others are untouched.
   *
   * @covers ::evaluate
   */
  public function testDisabledPolicyIsSkipped(): void {
    $nid = $this->makePage();
    $this->policyStorage()->load('links')->disable()->save();

    $report = $this->container->get('aincient_audit.engine')->audit($this->head($nid));
    $this->assertSame(['seo'], array_column($report['checks'], 'key'), 'Only the enabled policy reports.');
  }

  /**
   * Phase 3: a tuned parameter reaches the check through the whole workflow
   * boundary — an impossibly high `title_min` makes the title fail the lower
   * bound, and the resolved bound is stamped onto the finding's remediation
   * `constraints` (so the fix UI and the finding always agree).
   *
   * @covers ::evaluate
   */
  public function testTunedThresholdReachesTheCheck(): void {
    $nid = $this->makePage();
    $seo = $this->policyStorage()->load('seo');
    $params = $seo->getParameters();
    $params['title_min'] = 9999;
    $seo->set('parameters', $params)->save();

    $report = $this->container->get('aincient_audit.engine')->audit($this->head($nid));
    $byId = [];
    foreach ($report['checks'] as $group) {
      if ($group['key'] === 'seo') {
        foreach ($group['findings'] as $f) {
          $byId[$f['id']] = $f;
        }
      }
    }
    // Any real title is < 9999 chars → the title finding is non-pass and carries
    // the tuned lower bound — proving the param flowed node→workflow→check.
    $this->assertContains($byId['seo.title']['severity'], ['warn', 'fail']);
    $this->assertSame(9999, $byId['seo.title']['remediation']['constraints']['min']);
  }

  /**
   * Phase 3: `enforcing` is stored but INERT in v1 — an enforcing policy still
   * only reports (no exception, no publish gate); enforcement wires up in Phase 6.
   *
   * @covers ::evaluate
   */
  public function testEnforcementIsInert(): void {
    $nid = $this->makePage();
    $this->policyStorage()->load('seo')->set('enforcement', 'enforcing')->save();

    $report = $this->container->get('aincient_audit.engine')->audit($this->head($nid));
    $this->assertContains('seo', array_column($report['checks'], 'key'), 'An enforcing policy still reports.');
  }

  /**
   * The PHP selector prefilter excludes bundles the policies don't target — no
   * workflow runs for a non-aincient_page node, so the report has no checks.
   *
   * @covers ::evaluate
   */
  public function testSelectorExcludesOtherBundles(): void {
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    $node = Node::create(['type' => 'article', 'title' => 'Not a page']);
    $node->save();

    $body = $this->container->get('aincient_audit.policy_evaluator')->evaluate($node);
    $this->assertSame([], $body['checks']);
    $this->assertSame(['pass' => 0, 'warn' => 0, 'fail' => 0, 'total' => 0], $body['summary']);
  }

}
