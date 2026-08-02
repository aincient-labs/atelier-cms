<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_core\Unit\Usage;

use Drupal\aincient_core\Inference\MessageMapper;
use Drupal\aincient_core\Usage\ModelPricing;
use Drupal\aincient_core\Inference\ResultUnpacker;
use Drupal\aincient_core\Usage\UsageRecorder;
use Drupal\aincient_core\Usage\UsageLog;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Insert;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\TokenUsage\TokenUsage;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Pins what a usage row says, and what happens when it cannot be written.
 *
 * Three properties are load-bearing and none of them is visible from a seam.
 * The row has to NAME the call site, or the dashboard cannot tell an agent turn
 * from thread naming — the confusion that made the console display a 105-token
 * haiku call as the cost of a whole turn. A failure to record has to be
 * survivable, because a broken bookkeeper must not stop the work. And a zero has
 * to be distinguishable from an absence, because a plausible zero is the exact
 * shape of the bug this class exists to fix: a real $0.0475 sonnet-5 turn was
 * recorded as $0.00, four times over, and nothing said so.
 *
 * ASSERTED ON THE COLUMNS, not on a parameter list. The recorder now hands its
 * row to Atelier's own {@see UsageLog}, so what a test can watch is the INSERT
 * that writer composes — `cost_usd`, `context_id`, `input_tokens`. That is the
 * vocabulary the dashboard, the exports and `aincient_core_schema()` all speak,
 * and pinning it here means a renamed column cannot pass through this class
 * unnoticed.
 *
 * @group aincient_core
 */
#[CoversClass(UsageRecorder::class)]
final class UsageRecorderTest extends UnitTestCase {

  /**
   * The row the recorder wrote, exactly as it reached the database.
   *
   * @var array<string, mixed>|null
   */
  private ?array $row = NULL;

  /**
   * Warnings the recorder logged during a test.
   *
   * @var list<string>
   */
  private array $warnings = [];

  /**
   * A row carries the resolved provider, model, operation, tokens and call site.
   */
  public function testRecordsACompleteRow(): void {
    $recorder = $this->recorder();

    $recorder->record(
      'anthropic',
      'claude-sonnet-5',
      $this->resultWithUsage(new TokenUsage(promptTokens: 4210, completionTokens: 318)),
      'aincient_agent_turn',
    );

    $this->assertNotNull($this->row, 'The recorder wrote no row at all.');
    $this->assertSame('anthropic', $this->row['provider_id']);
    $this->assertSame('claude-sonnet-5', $this->row['model_id']);
    $this->assertSame('chat', $this->row['operation']);
    $this->assertSame(4210, $this->row['input_tokens']);
    $this->assertSame(318, $this->row['output_tokens']);
    // The call site, which is the whole reason an agent turn is now tellable
    // apart from thread naming in the same dashboard.
    $this->assertSame('aincient_agent_turn', $this->row['context_id']);
    $this->assertSame(7, $this->row['uid']);
    // A cost, not just tokens: a row with tokens and no money is half a row.
    // 4210 input at $2/Mtok plus 318 output at $10/Mtok, priced from OUR table.
    $this->assertEqualsWithDelta(0.0116, $this->row['cost_usd'], 1.0E-9);
    $this->assertSame([], $this->warnings);
  }

  /**
   * The operation a caller names is what gets recorded.
   */
  public function testRecordsTheOperationTheCallerNamed(): void {
    $recorder = $this->recorder();

    $recorder->record(
      'nanobanana',
      'gemini-2.5-flash-image',
      $this->resultWithUsage(new TokenUsage(promptTokens: 20, completionTokens: 1290)),
      'aincient_media_studio',
      UsageRecorder::OPERATION_IMAGE_TO_IMAGE,
    );

    $this->assertSame('image_to_image', $this->row['operation']);
    $this->assertSame('aincient_media_studio', $this->row['context_id']);
  }

  /**
   * A call-site tag reaches the column whole, not clipped to contrib's width.
   *
   * The predecessor's `context_id` was 36 characters wide — it held a run UUID —
   * so the recorder used to cut every tag down to fit it, and
   * `aincient_chat_thread_namer_something_longer` would have arrived on the
   * dashboard as a truncated string that no grep of the code base finds. Our
   * column is 64 and the clip moved with it. Forty characters is the interesting
   * length: it survives here and would not have survived before.
   */
  public function testALongCallSiteTagIsNotClippedToTheOldWidth(): void {
    $recorder = $this->recorder();
    $tag = str_repeat('a', 40);

    $recorder->record(
      'anthropic',
      'claude-sonnet-5',
      $this->resultWithUsage(new TokenUsage(promptTokens: 10, completionTokens: 2)),
      $tag,
    );

    $this->assertSame($tag, $this->row['context_id']);
  }

  /**
   * Thinking counts as output, cache writes as input, cache reads on their own.
   *
   * Gemini reports thinking apart from completion and bills both as output,
   * while Anthropic folds thinking into output_tokens and reports none here — so
   * summing is right for both and dropping it would under-report a reasoning
   * model by however much it thought.
   */
  public function testThinkingCountsAsOutputAndCacheReadIsSeparate(): void {
    $recorder = $this->recorder();

    $recorder->record(
      'gemini',
      'gemini-3-pro',
      $this->resultWithUsage(new TokenUsage(
        promptTokens: 100,
        completionTokens: 40,
        thinkingTokens: 260,
        cacheReadTokens: 900,
        cacheCreationTokens: 50,
        totalTokens: 1350,
      )),
      'aincient_agent_turn',
    );

    // Cache CREATION joins input: Anthropic's input_tokens excludes it, and a
    // row that prices only input charges nothing at all for the writes that
    // dominate the first turn of a conversation. It is NOT folded into the
    // cached column, which carries the discounted read rate.
    $this->assertSame(150, $this->row['input_tokens']);
    $this->assertSame(300, $this->row['output_tokens']);
    $this->assertSame(900, $this->row['cached_tokens']);

    $details = json_decode((string) $this->row['token_details'], TRUE);
    $this->assertTrue($details['usage_reported']);
    $this->assertSame(260, $details['reasoning']);
    $this->assertSame(1350, $details['total']);
    $this->assertSame(50, $details['cache_creation']);
  }

  /**
   * Anthropic reports no total, so the row carries the arithmetic one.
   */
  public function testFillsInATotalWhenTheProviderReportsNone(): void {
    $recorder = $this->recorder();

    $recorder->record(
      'anthropic',
      'claude-haiku-4-5-20251001',
      $this->resultWithUsage(new TokenUsage(promptTokens: 105, completionTokens: 9)),
      'aincient_chat_thread_namer',
    );

    $details = json_decode((string) $this->row['token_details'], TRUE);
    $this->assertSame(114, $details['total']);
  }

  /**
   * A provider that reported nothing is recorded AS having reported nothing.
   *
   * The row still lands — the call happened — but `usage_reported: false` says
   * the zeros are an absence of accounting, not a free call. Without that flag
   * this row is indistinguishable from a genuinely costless one, which is how
   * unmetered turns hid in plain sight.
   */
  public function testAMissingUsageIsRecordedAsMissingNotAsZeroCost(): void {
    $recorder = $this->recorder();

    $recorder->record('anthropic', 'claude-sonnet-5', new TextResult('hi'), 'aincient_agent_turn');

    $this->assertNotNull($this->row, 'A call with no usage metadata recorded nothing.');
    $this->assertSame(0, $this->row['input_tokens']);
    $this->assertSame(0, $this->row['output_tokens']);
    $this->assertSame(
      ['usage_reported' => FALSE],
      json_decode((string) $this->row['token_details'], TRUE),
    );
  }

  /**
   * A failure to record is a logged warning, never an exception at the operator.
   *
   * The one branch the store being OURS did not remove. A missing table on a
   * site that upgraded without running `updb`, a locked database, a column that
   * moved — the write can still fail, and the operator asked for a page, not for
   * an accounting subsystem. The warning is what keeps the failure from being
   * the silence this class was written to end.
   */
  public function testARecordingFailureIsSwallowedAndLogged(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $warnings = [];
    $logger->method('warning')->willReturnCallback(
      static function (string|\Stringable $message, array $context = []) use (&$warnings): void {
        $warnings[] = strtr((string) $message, array_map('strval', $context));
      },
    );

    $recorder = new UsageRecorder(
      new ResultUnpacker(new MessageMapper()),
      $this->pricing(),
      $this->currentUser(),
      $logger,
      $this->log(new \RuntimeException('table is gone')),
    );

    $recorder->record(
      'anthropic',
      'claude-sonnet-5',
      $this->resultWithUsage(new TokenUsage(promptTokens: 10, completionTokens: 2)),
      'aincient_agent_turn',
    );

    $this->assertCount(1, $warnings, 'A metering failure was not logged.');
    $this->assertStringContainsString('aincient_agent_turn', $warnings[0]);
    $this->assertStringContainsString('table is gone', $warnings[0]);
  }

  /**
   * A cache write is billed at its premium, and still lands in the input column.
   *
   * Two claims that pull in opposite directions and both matter. The COST splits
   * creation out at 1.25x, because the predecessor charged it at 1x and
   * documented the ~25% understatement as unavoidable — it was only unavoidable
   * while the rate table belonged to someone else. The COLUMN keeps the sum,
   * because `input_tokens` has meant "input plus creation" in every row already
   * written and a dashboard that mixes two definitions is worse than one.
   */
  public function testACacheWriteIsPricedAtAPremiumButRecordedAsInput(): void {
    $recorder = $this->recorder();

    $recorder->record(
      'anthropic',
      'claude-sonnet-5',
      $this->resultWithUsage(new TokenUsage(
        promptTokens: 8,
        completionTokens: 232,
        cacheReadTokens: 0,
        cacheCreationTokens: 7162,
      )),
      'aincient_agent_turn',
    );

    $this->assertSame(7170, $this->row['input_tokens'], 'Creation left the input column.');
    // 8 * $2/Mtok + 232 * $10/Mtok + 7162 * $2.50/Mtok. Priced as plain input
    // the same call would come to $0.016660 — the 25% miss, made visible.
    $this->assertEqualsWithDelta(0.020241, $this->row['cost_usd'], 1.0E-9);
  }

  /**
   * A cache read is charged at the discounted rate, on its own column.
   */
  public function testACacheReadIsChargedAtTheReadRate(): void {
    $recorder = $this->recorder();

    $recorder->record(
      'anthropic',
      'claude-sonnet-5',
      $this->resultWithUsage(new TokenUsage(
        promptTokens: 2,
        completionTokens: 68,
        cacheReadTokens: 7670,
        cacheCreationTokens: 0,
      )),
      'aincient_agent_turn',
    );

    $this->assertSame(7670, $this->row['cached_tokens']);
    // 2 * $2/Mtok + 68 * $10/Mtok + 7670 * $0.20/Mtok.
    $this->assertEqualsWithDelta(0.002218, $this->row['cost_usd'], 1.0E-9);
  }

  /**
   * An unpriced model still records its tokens — and says so, by name.
   *
   * THE FAILURE THIS WHOLE CHANGE IS ABOUT. The row lands (the call happened and
   * the tokens are real), the cost is honestly 0.00 because we have no rate, and
   * a warning names the provider and model so the gap is findable. Silence here
   * is what let four sonnet-5 rows read as free.
   */
  public function testAnUnpricedModelIsRecordedAndAnnouncedByName(): void {
    $recorder = $this->recorder();

    $recorder->record(
      'openai',
      'gpt-5.6-terra',
      $this->resultWithUsage(new TokenUsage(promptTokens: 900, completionTokens: 120)),
      'aincient_agent_turn',
    );

    $this->assertSame(900, $this->row['input_tokens'], 'The row was dropped instead of flagged.');
    $this->assertSame(0.0, $this->row['cost_usd']);
    $this->assertCount(1, $this->warnings, 'A $0 cost on 1020 real tokens was recorded silently.');
    $this->assertStringContainsString('openai', $this->warnings[0]);
    $this->assertStringContainsString('gpt-5.6-terra', $this->warnings[0]);
  }

  /**
   * A local model is free, and a free zero says nothing.
   *
   * The case that keeps the warning above worth reading. If every Ollama call
   * logged "no price", the log line would be noise inside a day and the real
   * gap would hide in it.
   */
  public function testADeliberatelyFreeModelRecordsZeroWithoutComplaint(): void {
    $recorder = $this->recorder();

    $recorder->record(
      'ollama',
      'llama4:70b',
      $this->resultWithUsage(new TokenUsage(promptTokens: 5000, completionTokens: 900)),
      'aincient_agent_turn',
    );

    $this->assertSame(0.0, $this->row['cost_usd']);
    $this->assertSame([], $this->warnings, 'A local model was nagged about being free.');
  }

  /**
   * A provider that filed no accounting is not accused of being unpriced.
   *
   * Zero tokens is already reported as `usage_reported: false` in token_details.
   * Warning about it a second time as a pricing gap points at the wrong problem
   * and trains people to skip the line that matters.
   */
  public function testNoUsageMeansNoPricingWarning(): void {
    $recorder = $this->recorder();

    $recorder->record('openai', 'gpt-5.6-terra', new TextResult('hi'), 'aincient_agent_turn');

    $this->assertSame([], $this->warnings);
  }

  /**
   * A recorder whose written row lands in $this->row.
   */
  private function recorder(): UsageRecorder {
    return new UsageRecorder(
      new ResultUnpacker(new MessageMapper()),
      $this->pricing(),
      $this->currentUser(),
      $this->capturingLogger(),
      $this->log(),
    );
  }

  /**
   * The REAL {@see UsageLog} over a database that hands its row back.
   *
   * Not a doubled writer: the class is `final`, and un-finalling production code
   * to make a fixture possible would trade the thing this test is for. Composed
   * over a Connection double instead, so the row asserted on is the one
   * `UsageLog::record()` actually builds — column names, folded cache writes and
   * all — and a change to the schema or to that method fails here rather than
   * quietly passing through a stale mock.
   *
   * @param \Throwable|null $failure
   *   An exception the INSERT should throw, for the swallowed-failure case.
   */
  private function log(?\Throwable $failure = NULL): UsageLog {
    $insert = $this->createMock(Insert::class);
    $insert->method('fields')->willReturnCallback(
      function (array $fields) use ($insert): Insert {
        $this->row = $fields;
        return $insert;
      },
    );
    if ($failure !== NULL) {
      $insert->method('execute')->willThrowException($failure);
    }
    else {
      $insert->method('execute')->willReturn(NULL);
    }

    $database = $this->createMock(Connection::class);
    $database->method('insert')->willReturn($insert);

    $dispatcher = $this->createMock(EventDispatcherInterface::class);
    $dispatcher->method('dispatch')->willReturnArgument(0);

    return new UsageLog(
      $database,
      $this->createMock(TimeInterface::class),
      $this->createMock(CacheTagsInvalidatorInterface::class),
      $dispatcher,
    );
  }

  /**
   * The REAL pricing service over a fixture table, not a doubled one.
   *
   * Doubling it would double away the property under test: what the recorder
   * does when a model has no price. The fixture carries one priced model, one
   * deliberately-free provider, and — by omission — every unpriced model there
   * is.
   */
  private function pricing(): ModelPricing {
    return new ModelPricing($this->getConfigFactoryStub([
      ModelPricing::CONFIG => [
        'models' => [
          [
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-5',
            'input_per_mtok' => 2.0,
            'output_per_mtok' => 10.0,
            'cache_read_per_mtok' => 0.2,
            'cache_write_per_mtok' => 2.5,
            'source' => 'test',
            'checked' => '2026-08-02',
          ],
          [
            'provider' => 'anthropic',
            'model' => 'claude-haiku-4-5-20251001',
            'input_per_mtok' => 1.0,
            'output_per_mtok' => 5.0,
            'cache_read_per_mtok' => 0.1,
            'cache_write_per_mtok' => 1.25,
            'source' => 'test',
            'checked' => '2026-08-02',
          ],
          [
            'provider' => 'nanobanana',
            'model' => 'gemini-2.5-flash-image',
            'input_per_mtok' => 0.3,
            'output_per_mtok' => 30.0,
            'source' => 'test',
            'checked' => '2026-08-02',
          ],
          [
            'provider' => 'ollama',
            'model' => '*',
            'input_per_mtok' => 0.0,
            'output_per_mtok' => 0.0,
            'free' => TRUE,
            'source' => 'test',
            'checked' => '2026-08-02',
          ],
        ],
      ],
    ]));
  }

  /**
   * A logger that keeps its warnings where a test can read them.
   */
  private function capturingLogger(): LoggerInterface {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->method('warning')->willReturnCallback(
      function (string|\Stringable $message, array $context = []): void {
        $this->warnings[] = strtr((string) $message, array_map('strval', $context));
      },
    );
    return $logger;
  }

  /**
   * The operator the call is recorded against.
   */
  private function currentUser(): AccountProxyInterface {
    $currentUser = $this->createMock(AccountProxyInterface::class);
    $currentUser->method('id')->willReturn(7);
    return $currentUser;
  }

  /**
   * A result carrying token usage the way a real bridge files it.
   */
  private function resultWithUsage(TokenUsage $usage): TextResult {
    $result = new TextResult('Answered.');
    $result->getMetadata()->add('token_usage', $usage);
    return $result;
  }

}
