<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_core\Kernel;

use Drupal\aincient_core\Usage\UsageQuery;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Update 11013: unpriced rows written as $0.00 become NULL; free zeros stay.
 *
 * @group aincient_core
 */
#[RunTestsInSeparateProcesses]
final class UnpricedCostUpdateTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'aincient_core'];

  /**
   * The table as it stood before 11013: `cost_usd` NOT NULL DEFAULT 0.
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get('module_handler')->loadInclude('aincient_core', 'install');
    $table = aincient_core_schema()[UsageQuery::TABLE];
    $table['fields']['cost_usd']['not null'] = TRUE;
    $table['fields']['cost_usd']['default'] = 0.0;
    $this->container->get('database')->schema()->createTable(UsageQuery::TABLE, $table);
  }

  public function testUnpricedZerosBecomeNullAndEverythingElseStays(): void {
    $this->seed('openai_compatible', 'production-fast', 900, 0.0);
    $this->seed('ollama', 'llama3', 900, 0.0);
    $this->seed('anthropic', 'claude-sonnet-5', 900, 0.0475);
    // No tokens reported: nothing to price, so its zero is not a gap.
    $this->seed('openai_compatible', 'production-fast', 0, 0.0);

    aincient_core_update_11013();

    $costs = $this->container->get('database')
      ->query('SELECT [cost_usd] FROM {aincient_ai_usage} ORDER BY [id]')
      ->fetchCol();
    $this->assertNull($costs[0], 'An unpriced $0.00 row kept reading as a price.');
    $this->assertEqualsWithDelta(0.0, (float) $costs[1], 1e-10, 'A free model lost its real zero.');
    $this->assertEqualsWithDelta(0.0475, (float) $costs[2], 1e-10);
    $this->assertNotNull($costs[3]);
    $this->assertEqualsWithDelta(0.0, (float) $costs[3], 1e-10);

    // The column now takes NULL from the writer too.
    $this->seed('openai_compatible', 'production-fast', 10, NULL);
    $this->assertNull($this->container->get('database')
      ->query('SELECT [cost_usd] FROM {aincient_ai_usage} ORDER BY [id] DESC')
      ->fetchField());
  }

  private function seed(string $provider, string $model, int $tokens, ?float $cost): void {
    $this->container->get('database')->insert(UsageQuery::TABLE)->fields([
      'uid' => 0,
      'timestamp' => 1,
      'provider_id' => $provider,
      'model_id' => $model,
      'operation' => 'chat',
      'input_tokens' => $tokens,
      'output_tokens' => 0,
      'cached_tokens' => 0,
      'cost_usd' => $cost,
    ])->execute();
  }

}
