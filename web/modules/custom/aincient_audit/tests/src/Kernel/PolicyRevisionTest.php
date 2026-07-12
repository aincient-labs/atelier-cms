<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_audit\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The Phase-4 policy revision substrate (the Brand-studio parallel).
 *
 * Exercises {@see \Drupal\aincient_audit\PolicyRevisioner} +
 * {@see \Drupal\aincient_audit\EventSubscriber\PolicyConfigSubscriber}: every
 * policy config SAVE appends a per-policy snapshot (deduped + pruned), and a
 * restore rewrites config verbatim and re-enters history.
 *
 * @coversDefaultClass \Drupal\aincient_audit\PolicyRevisioner
 * @group aincient_audit
 */
#[RunTestsInSeparateProcesses]
final class PolicyRevisionTest extends KernelTestBase {

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
    // The history table must exist BEFORE the policy seed saves, so the baseline
    // 'created' revisions are captured.
    $this->installEntitySchema('aincient_policy_revision');

    // Install the shipped policy config (node type + workflows) so the seeded
    // policies' config dependencies resolve, then the policy entities.
    $this->importShippedConfig('flowdrop_node_type', 'flowdrop_node_type.flowdrop_node_type.aincient_flows_policy_check');
    $this->importShippedConfig('flowdrop_workflow', 'flowdrop_workflow.flowdrop_workflow.aincient_policy_seo');
    $this->importShippedConfig('flowdrop_workflow', 'flowdrop_workflow.flowdrop_workflow.aincient_policy_links');
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

  private function revisioner(): \Drupal\aincient_audit\PolicyRevisioner {
    return $this->container->get('aincient_audit.policy_revisioner');
  }

  private function policyStorage(): \Drupal\Core\Entity\EntityStorageInterface {
    return $this->container->get('entity_type.manager')->getStorage('aincient_policy');
  }

  /**
   * Seeding a policy records a baseline 'created' revision, keyed per policy.
   *
   * @covers ::snapshot
   */
  public function testSeedCreatesBaselineRevisionPerPolicy(): void {
    // Both shipped policies got a baseline on install.
    $seo = $this->revisioner()->recent('seo');
    $links = $this->revisioner()->recent('links');
    $this->assertCount(1, $seo);
    $this->assertCount(1, $links);
    $this->assertSame('created', $seo[0]->get('summary')->value);

    // A policy's history is isolated: seo's latest is not links'.
    $this->assertSame('seo', $seo[0]->get('policy_id')->value);
    $this->assertSame('links', $links[0]->get('policy_id')->value);
  }

  /**
   * A meaningful edit appends a revision with a summary; a no-op re-save doesn't.
   *
   * @covers ::snapshot
   */
  public function testEditAppendsAndNoOpDedupes(): void {
    $seo = $this->policyStorage()->load('seo');

    // Disable → one new revision summarising the change.
    $seo->disable()->save();
    $recent = $this->revisioner()->recent('seo');
    $this->assertCount(2, $recent);
    $this->assertSame('disabled', $recent[0]->get('summary')->value);

    // Re-saving identical data is deduped by the revisioner — no new row.
    $this->policyStorage()->load('seo')->save();
    $this->assertCount(2, $this->revisioner()->recent('seo'));

    // links is untouched by seo's churn.
    $this->assertCount(1, $this->revisioner()->recent('links'));
  }

  /**
   * A parameter tune is summarised as `key → value`.
   *
   * @covers ::snapshot
   */
  public function testTuneSummarisesParameter(): void {
    $seo = $this->policyStorage()->load('seo');
    $params = $seo->getParameters();
    $params['title_max'] = 12;
    $seo->set('parameters', $params)->save();

    $this->assertSame('title_max → 12', $this->revisioner()->recent('seo')[0]->get('summary')->value);
  }

  /**
   * restore() rewrites the policy config verbatim and re-enters history.
   *
   * @covers ::restore
   */
  public function testRestoreRewritesConfigAndReentersHistory(): void {
    $seo = $this->policyStorage()->load('seo');
    $original = $seo->getParameters()['title_max'];

    // Tune, then capture the baseline revision id to restore back to.
    $baseline = $this->revisioner()->recent('seo')[0];
    $seo->set('parameters', ['title_min' => 30, 'title_max' => 12, 'description_min' => 50, 'description_max' => 160])->save();
    $this->assertSame(12, $this->policyStorage()->load('seo')->getParameters()['title_max']);

    // Restore the baseline snapshot → config returns to the original value.
    $this->assertTrue($this->revisioner()->restore((int) $baseline->id()));
    $this->assertSame($original, $this->policyStorage()->load('seo')->getParameters()['title_max']);

    // The restore is itself a save → a new revision was appended (reversible).
    $recent = $this->revisioner()->recent('seo');
    $this->assertGreaterThanOrEqual(3, count($recent));
  }

  /**
   * History is pruned to MAX_REVISIONS PER POLICY, keeping the newest.
   *
   * @covers ::snapshot
   */
  public function testPrunePerPolicy(): void {
    $rev = $this->revisioner();
    // Push well past the cap with distinct data each time.
    for ($i = 0; $i < \Drupal\aincient_audit\PolicyRevisioner::MAX_REVISIONS + 5; $i++) {
      $rev->snapshot('seo', ['n' => $i], 'edit ' . $i);
    }
    $recent = $rev->recent('seo', 1000);
    $this->assertCount(\Drupal\aincient_audit\PolicyRevisioner::MAX_REVISIONS, $recent);
    // Newest is kept.
    $this->assertSame('edit ' . (\Drupal\aincient_audit\PolicyRevisioner::MAX_REVISIONS + 4), $recent[0]->get('summary')->value);
  }

}
