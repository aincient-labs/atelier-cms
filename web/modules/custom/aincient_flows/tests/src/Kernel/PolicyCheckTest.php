<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_flows\Kernel;

use Drupal\aincient_flows\Plugin\FlowDropNodeProcessor\PolicyCheck;
use Drupal\flowdrop\DTO\ParameterBag;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\aincient_pages\Kernel\EditorialWorkflowTestTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the policy_check node processor (DECISIONS 0129 Phase 1b).
 *
 * The native building block of the default policy WORKFLOWS: it takes a check id
 * (`seo` / `links`) + a target node and returns that check's findings — the
 * "params in → findings-state out" contract, run as pure PHP (no LLM, no HTTP).
 * Here we exercise the node DIRECTLY (no orchestrator): the findings it emits
 * must be exactly what the underlying {@see \Drupal\aincient_audit\Check\CheckInterface}
 * produces, and a misconfiguration must degrade to empty findings rather than
 * throw (so one broken policy can't abort a whole evaluation).
 *
 * @coversDefaultClass \Drupal\aincient_flows\Plugin\FlowDropNodeProcessor\PolicyCheck
 * @group aincient_flows
 */
#[RunTestsInSeparateProcesses]
final class PolicyCheckTest extends KernelTestBase {

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
    // FlowDrop runtime closure — aincient_audit's policy_evaluator references
    // flowdrop_runtime.synchronous_orchestrator, so the container needs it even
    // though this test invokes the node directly.
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

    // aincient_pages ships the aincient_page node type; ensure the editorial
    // workflow governs it (the store + moderation service require it).
    if (\Drupal::entityTypeManager()->getStorage('workflow')->load('aincient_editorial') === NULL) {
      $this->setUpEditorialWorkflow(['aincient_page']);
    }
  }

  /**
   * The node under test, wired to the real registry + moderation service.
   */
  private function node(): PolicyCheck {
    return new PolicyCheck(
      [],
      'aincient_flows:policy_check',
      [],
      $this->container->get('aincient_audit.check_registry'),
      $this->container->get('aincient_pages.moderation'),
    );
  }

  /**
   * Create a saved aincient_page and return its node id.
   */
  private function makePage(): string {
    /** @var \Drupal\aincient_pages\PageStore $store */
    $store = $this->container->get('aincient_pages.store');
    return $store->store([
      'type' => 'landing',
      'title' => 'Test page',
      'sections' => [],
    ]);
  }

  /**
   * The check's own findings for the same node — the expected output.
   *
   * @return list<array<string, mixed>>
   */
  private function expected(string $checkId, string $nid): array {
    $node = $this->container->get('aincient_pages.moderation')->loadLatestRevision($nid, 'aincient_page');
    return $this->container->get('aincient_audit.check_registry')->get($checkId)->evaluate($node);
  }

  /**
   * A pinned `check` + a node_id (the way the evaluator feeds it via the
   * workflow initial data) yields exactly the check's findings.
   *
   * @covers ::process
   */
  public function testRunsTheNamedCheck(): void {
    $nid = $this->makePage();
    foreach (['seo', 'links'] as $checkId) {
      $result = $this->node()->process(new ParameterBag(['check' => $checkId, 'node_id' => $nid]));
      $this->assertSame(
        $this->expected($checkId, $nid),
        $result['findings'],
        "policy_check($checkId) must emit the check's own findings verbatim.",
      );
    }
  }

  /**
   * An unknown check id degrades to empty findings, not an error.
   *
   * @covers ::process
   */
  public function testUnknownCheckIsEmpty(): void {
    $nid = $this->makePage();
    $result = $this->node()->process(new ParameterBag(['check' => 'nope', 'node_id' => $nid]));
    $this->assertSame(['findings' => []], $result);
  }

  /**
   * A missing check id (workflow misconfigured) degrades to empty findings.
   *
   * @covers ::process
   */
  public function testMissingCheckIsEmpty(): void {
    $nid = $this->makePage();
    $result = $this->node()->process(new ParameterBag(['node_id' => $nid]));
    $this->assertSame(['findings' => []], $result);
  }

  /**
   * A non-existent / wrong-bundle node degrades to empty findings.
   *
   * @covers ::process
   */
  public function testMissingNodeIsEmpty(): void {
    $result = $this->node()->process(new ParameterBag(['check' => 'seo', 'node_id' => '9999']));
    $this->assertSame(['findings' => []], $result);
  }

  /**
   * No node_id at all (no param, no execution context) degrades to empty.
   *
   * @covers ::process
   */
  public function testNoNodeIdIsEmpty(): void {
    $result = $this->node()->process(new ParameterBag(['check' => 'seo']));
    $this->assertSame(['findings' => []], $result);
  }

}
