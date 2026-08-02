<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_core\Kernel;

use Drupal\aincient_core\Usage\UsageRecorder;
use Drupal\aincient_core\ModelRoles;
use Drupal\aincient_core\Usage\UsageQuery;
use Drupal\Core\Database\Statement\FetchAs;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\aincient_core\Traits\ScriptedInferenceTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The wired seam: a real call through the container lands a real metering row.
 *
 * WHAT THIS GUARDS. Nothing subscribes to an inference event on this site's
 * behalf any more — the contrib subscriber that used to learn about every call
 * for free listened to `drupal/ai` response events nothing dispatches, and the
 * module is gone. Every seam we own is responsible for its own row. That
 * responsibility is invisible — a seam that forgets to record still answers
 * correctly, and the only symptom is a usage number that is quietly too small.
 * So this test drives the REAL services out of the container (scripted adapter →
 * gateway → recorder → UsageLog → `aincient_ai_usage`) and asserts against the
 * table, not against a mock.
 *
 * THE TABLE IS OURS NOW, and this test is the proof it is wired end to end:
 * `aincient_core_schema()` installs it, {@see \Drupal\aincient_core\Usage\UsageLog}
 * writes it, and the column names asserted below are the ones the dashboard and
 * both exports read. Nothing here installs a contrib schema or config any more.
 *
 * @group aincient_core
 */
#[RunTestsInSeparateProcesses]
final class UsageMeteringTest extends KernelTestBase {

  use ScriptedInferenceTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'key', 'dblog',
    'aincient_core', 'aincient_inference_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installSchema('aincient_core', ['aincient_ai_usage']);
    // Dblog so the loud zero can be asserted as a real logged event rather than
    // as a mock expectation — the whole complaint about the predecessor was that
    // its silence was invisible from outside.
    $this->installSchema('dblog', ['watchdog']);
    $this->installConfig(['system']);

    // A price for the scripted model, in OUR config object. Not
    // `ai_metering.litellm_pricing` in State, which is where this test used to
    // put it: that is the table whose four-deep lookup silently answers 0.0, and
    // pointing the test at it would test the path we deliberately stopped using.
    $this->container->get('config.factory')
      ->getEditable('aincient_core.pricing')
      ->set('models', [
        [
          'provider' => 'scripted_test',
          'model' => 'scripted-chat',
          'input_per_mtok' => 2.0,
          'output_per_mtok' => 10.0,
          'cache_read_per_mtok' => 0.2,
          'cache_write_per_mtok' => 2.5,
          'source' => 'test',
          'checked' => '2026-08-02',
        ],
      ])
      ->save();

    $this->setUpCurrentUser();
    $this->connectScriptedProvider();
  }

  /**
   * A one-shot gateway call records its own tokens, cost and call site.
   */
  public function testAOneShotCallIsMetered(): void {
    $this->bindScriptedRole(ModelRoles::FAST);
    $this->scriptInferenceUsage(prompt: 1240, completion: 96);

    $text = $this->container->get('aincient_core.inference.gateway')
      ->text('Name this thread.', ModelRoles::FAST, 'aincient_chat_thread_namer');

    $this->assertNotSame('', $text, 'The scripted provider answered nothing, so nothing was metered.');

    $rows = $this->rows();
    $this->assertCount(1, $rows);
    $row = $rows[0];
    $this->assertSame('scripted_test', $row['provider_id']);
    $this->assertSame('scripted-chat', $row['model_id']);
    $this->assertSame('chat', $row['operation']);
    $this->assertSame(1240, (int) $row['input_tokens']);
    $this->assertSame(96, (int) $row['output_tokens']);
    // The tag the call site passes, unchanged — historical rows and new ones line
    // up on it, so it must survive the trip verbatim.
    $this->assertSame('aincient_chat_thread_namer', $row['context_id']);
    // 1240 × $0.000002 + 96 × $0.00001 = $0.003440. Compared as a NUMBER, not as
    // the string the driver hands back: the column is DECIMAL(12,8) and its
    // textual form is driver-specific ('0.00344000' on PostgreSQL, '0.00344' on
    // the SQLite the fast lane uses), which says nothing about the money.
    $this->assertEqualsWithDelta(0.00344, (float) $row['cost_usd'], 1.0e-10);
  }

  /**
   * Two different call sites are two distinguishable rows.
   *
   * The regression this whole change repairs was not a missing number, it was an
   * ATTRIBUTED one: thread naming's ~105/9 tokens read as the cost of an agent
   * turn. Distinguishability is therefore the property to pin, not just presence.
   */
  public function testCallSitesAreTellableApart(): void {
    $this->bindScriptedRole(ModelRoles::FAST);
    $this->bindScriptedRole(ModelRoles::TASK);

    $this->scriptInferenceUsage(prompt: 105, completion: 9);
    $this->container->get('aincient_core.inference.gateway')
      ->text('Name this thread.', ModelRoles::FAST, 'aincient_chat_thread_namer');

    $this->scriptInferenceUsage(prompt: 8400, completion: 640);
    $this->container->get('aincient_core.inference.chat_completer')
      ->complete('Make it lavender.', [], 'You are a specialist.', 'aincient_role:task');

    $byTag = [];
    foreach ($this->rows() as $row) {
      $byTag[$row['context_id']] = $row;
    }

    $this->assertSame(
      ['aincient_chat_thread_namer', UsageRecorder::TAG_SIMPLE_CHAT],
      array_keys($byTag),
    );
    $this->assertSame(105, (int) $byTag['aincient_chat_thread_namer']['input_tokens']);
    $this->assertSame(8400, (int) $byTag[UsageRecorder::TAG_SIMPLE_CHAT]['input_tokens']);
  }

  /**
   * A prompt-cache write is charged, at the WRITE rate, not the input rate.
   *
   * The first turn of a real conversation reports almost no plain input and a
   * few thousand cache-creation tokens; pricing only the plain input recorded a
   * fraction of a cent for a call that billed cents. Two things are pinned here:
   * the writes still land in the input COLUMN (every historical row means that
   * by it), and they are PRICED at the 1.25x premium a provider actually charges
   * to create a cache entry — which only became possible once the rate table
   * stopped being ai_metering's, since that one carries no write rate at all.
   */
  public function testACacheWriteIsPricedAtTheWriteRate(): void {
    $this->bindScriptedRole(ModelRoles::FAST);
    $this->scriptInferenceUsage(prompt: 2, completion: 56, cacheCreation: 7415);

    $this->container->get('aincient_core.inference.gateway')
      ->text('Go on.', ModelRoles::FAST, 'aincient_agent_turn');

    $row = $this->rows()[0];
    $this->assertSame(7417, (int) $row['input_tokens']);
    // 2 × $2/Mtok + 7415 × $2.50/Mtok + 56 × $10/Mtok = $0.0191015. Priced as
    // plain input the same call comes to $0.014894 — a 22% under-report. The
    // tolerance is a millionth of a dollar, not a ten-billionth, because the
    // fast lane's SQLite rounds the DECIMAL(12,8) column to $0.019102 on the way
    // back out; the claim under test is the write premium, not the driver's
    // last decimal place.
    $this->assertEqualsWithDelta(0.0191015, (float) $row['cost_usd'], 1.0e-6);
    $this->assertSame(7415, json_decode((string) $row['token_details'], TRUE)['cache_creation']);
  }

  /**
   * A model with no price records its tokens at $0 and SAYS SO in the log.
   *
   * The end-to-end version of the failure this change exists to fix. Nothing
   * about the row itself can distinguish "free" from "we have no rate" — the
   * column is 0.00 either way — so the distinction has to be carried by a log
   * line that names the model. Asserted against the real watchdog table, because
   * a warning nobody can find is the same as no warning.
   */
  public function testAnUnpricedModelIsRecordedAndAnnounced(): void {
    // Price something else entirely: the scripted model is now unpriced.
    $this->container->get('config.factory')
      ->getEditable('aincient_core.pricing')
      ->set('models', [])
      ->save();

    $this->bindScriptedRole(ModelRoles::FAST);
    $this->scriptInferenceUsage(prompt: 1240, completion: 96);

    $this->container->get('aincient_core.inference.gateway')
      ->text('Name this thread.', ModelRoles::FAST, 'aincient_agent_turn');

    $row = $this->rows()[0];
    $this->assertSame(1240, (int) $row['input_tokens'], 'The row was dropped rather than flagged.');
    $this->assertEqualsWithDelta(0.0, (float) $row['cost_usd'], 1.0e-10);

    $logged = $this->container->get('database')->select('watchdog', 'w')
      ->fields('w', ['message', 'variables'])
      ->condition('type', 'aincient_core')
      ->execute()
      ->fetchAll(FetchAs::Associative);
    $rendered = '';
    foreach ($logged as $entry) {
      $rendered .= strtr($entry['message'], array_map(
        static fn (mixed $v): string => is_scalar($v) ? (string) $v : '',
        (array) unserialize($entry['variables'], ['allowed_classes' => FALSE]),
      ));
    }
    $this->assertStringContainsString('No price for scripted_test:scripted-chat', $rendered);
  }

  /**
   * A provider that reports no usage still lands a row, marked as unreported.
   *
   * The scripted platform files no `token_usage` unless a test asks it to, which
   * is exactly the state a streaming call or an extractor-less bridge is in. The
   * row must exist (the call happened and was billed upstream) and must say that
   * its zeros are an absence.
   */
  public function testAnUnreportedUsageStillLandsAMarkedRow(): void {
    $this->bindScriptedRole(ModelRoles::FAST);

    $this->container->get('aincient_core.inference.gateway')
      ->text('Name this thread.', ModelRoles::FAST, 'aincient_chat_thread_namer');

    $rows = $this->rows();
    $this->assertCount(1, $rows);
    $this->assertSame(0, (int) $rows[0]['input_tokens']);
    $this->assertSame(
      ['usage_reported' => FALSE],
      json_decode((string) $rows[0]['token_details'], TRUE),
    );
  }

  /**
   * Every row in the usage log, oldest first.
   *
   * @return array<int, array<string, mixed>>
   *   The recorded rows.
   */
  private function rows(): array {
    return $this->container->get('database')->select(UsageQuery::TABLE, 'u')
      ->fields('u')
      ->orderBy('u.id')
      ->execute()
      ->fetchAll(FetchAs::Associative);
  }

}
