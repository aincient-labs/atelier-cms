<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Usage;

use Drupal\aincient_core\Inference\ResultUnpacker;
use Drupal\Core\Session\AccountProxyInterface;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;

/**
 * Writes what an inference call actually cost into the metering log.
 *
 * WHY THIS EXISTS. `ai_metering` used to learn about every call for free: it
 * subscribed to `drupal/ai`'s PostGenerate/PostStreaming events, which that
 * module's provider proxy fired on the way out of every request. Atelier now owns
 * every inference seam, so nothing fires those events and NOTHING WAS RECORDED —
 * except thread naming, which still ran through a path that landed a row. The
 * console's usage footer therefore showed the thread-namer's ~105/9 haiku tokens
 * as if they were the whole turn: not a blank number anybody would question, a
 * small plausible one. Silent under-reporting that surfaces as a bill.
 *
 * ONE recorder, three seams. {@see \Drupal\aincient_core\Inference\SymfonyAiReasoner}
 * (the agent loop), {@see \Drupal\aincient_core\Inference\AiGateway} (one-shot
 * product calls) and {@see \Drupal\aincient_core\Inference\ChatCompleter} (the plain
 * chat node) all call this after they have a result. Duplicating the composition
 * per seam is how the three of them would drift into recording three subtly
 * different rows, and a dashboard that mixes those is worse than an empty one.
 *
 * WHY NOT DISPATCH drupal/ai's EVENT. Faking a `PostGenerateResponseEvent` to
 * re-arm a contrib subscriber was considered and rejected: it would mean
 * building `ChatInput`/`ChatOutput` objects — the exact vendor types the
 * migration removed — to lie to a subscriber about where the call came from, and
 * it would re-couple our seams to a module we no longer call. Writing the row
 * ourselves through {@see UsageLog::record()} is one function call and names no
 * `Drupal\ai` type.
 *
 * THE COST AND THE STORE ARE BOTH OURS. The `costUsd` this writes comes from
 * {@see ModelPricing} rather than from the `ai_metering` TokenEstimator it
 * replaced — not a preference: that estimator answered 0.0 for a model it had no
 * rate for and said nothing about it, which is how four consecutive rows of a
 * $0.0475 sonnet-5 turn were written as $0.00. The TABLE followed for the same
 * reason the number did: once the pricing, the dashboard and the recording seams
 * were all Atelier's,
 * the contrib module was a dependency charged for a schema and an INSERT. So
 * {@see UsageLog} is a HARD dependency, and this class no longer has a "the
 * metering module is absent" branch — a site running this code has the table,
 * and a recorder that could quietly decide to record nothing is precisely the
 * silence the class was written to end.
 */
final class UsageRecorder {

  /**
   * The operation value a chat turn records — vision turns included.
   *
   * Kept as the literal `chat` the drupal/ai era used, even though the new table
   * starts empty and nothing forces the continuity: an export taken before the
   * cutover and one taken after still line up on this axis, which is the only
   * way the two can be compared at all. A vision call is a chat call with an
   * image part on this backend, so it is not a third value.
   */
  public const OPERATION_CHAT = 'chat';

  /**
   * Generating an image from a prompt.
   */
  public const OPERATION_TEXT_TO_IMAGE = 'text_to_image';

  /**
   * Editing an image the caller supplied.
   */
  public const OPERATION_IMAGE_TO_IMAGE = 'image_to_image';

  /**
   * The call-site tag for a turn of the agent loop.
   *
   * The reasoner serves every FlowDrop `reason` node — the operator and its
   * sub-agents — so this identifies "an agent turn", which is exactly the
   * distinction the dashboard was missing against thread naming. The gateway's
   * tags are NOT defined here: they are passed in by their callers
   * (`aincient_chat_thread_namer`, `aincient_media_studio`) and this class
   * preserves them verbatim so rows keep lining up if those call sites move.
   */
  public const TAG_AGENT_TURN = 'aincient_agent_turn';

  /**
   * The call-site tag for the plain chat node the brand specialists run on.
   */
  public const TAG_SIMPLE_CHAT = 'aincient_flows_simple_chat';

  public function __construct(
    private readonly ResultUnpacker $unpacker,
    private readonly ModelPricing $pricing,
    private readonly AccountProxyInterface $currentUser,
    private readonly LoggerInterface $logger,
    private readonly UsageLog $log,
  ) {}

  /**
   * Records one completed inference call.
   *
   * A BROKEN BOOKKEEPER MUST NOT STOP THE WORK. Everything below is wrapped: a
   * metering failure is a logged warning and the turn continues. The operator
   * asked for a page, not for an accounting subsystem, and throwing here would
   * turn a successful answer into a visible error over a row nobody was waiting
   * for. The inverse — swallowing the failure silently — is what this class
   * exists to fix, hence the log line.
   *
   * @param string $providerId
   *   The provider that served the call.
   * @param string $modelId
   *   The model that served the call, as resolved (never as requested).
   * @param object $result
   *   The result the platform yielded, for its token metadata.
   * @param string $tag
   *   The call-site tag ({@see self::TAG_AGENT_TURN}).
   * @param string $operation
   *   One of the OPERATION_* values.
   */
  public function record(
    string $providerId,
    string $modelId,
    object $result,
    string $tag,
    string $operation = self::OPERATION_CHAT,
  ): void {
    try {
      $usage = $this->unpacker->tokenUsage($result);
      [$input, $output, $cacheRead, $cacheWrite] = $this->counts($usage);
      $cost = $this->pricing->cost($providerId, $modelId, $input, $output, $cacheRead, $cacheWrite);
      $this->announceUnpriced($providerId, $modelId, $tag, $cost);

      $this->log->record(
        uid: (int) $this->currentUser->id(),
        providerId: $providerId,
        modelId: $modelId,
        operation: $operation,
        // Cache writes ride in the input column. They are billed apart (see
        // counts()), but the column means "everything charged at or above the
        // input rate" — it says so in the schema — and a fourth token column
        // would have to be summed back together by every reader to answer the
        // one question the dashboard actually asks.
        inputTokens: $input + $cacheWrite,
        outputTokens: $output,
        cachedTokens: $cacheRead,
        costUsd: $cost['total'],
        // The call-site tag rides in context_id, which is now OUR column and
        // documents that meaning outright — contrib's version held a run UUID
        // and, on this site, never held anything at all (0 non-null rows across
        // 384). The substr is a bound, not a policy: every TAG_* constant fits
        // in 64 comfortably, and the point of clipping is that a call site that
        // one day passes something longer gets a short tag rather than a
        // database error in the middle of a turn.
        contextId: $tag !== '' ? substr($tag, 0, 64) : NULL,
        tokenDetails: $this->details($usage, $input + $cacheWrite, $output),
      );
    }
    catch (\Throwable $e) {
      $this->logger->warning('Recording AI usage for @provider/@model (@tag) failed: @message', [
        '@provider' => $providerId,
        '@model' => $modelId,
        '@tag' => $tag !== '' ? $tag : 'untagged',
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * A zero that nobody chose has to say so out loud.
   *
   * THIS IS THE WHOLE POINT OF THE CHANGE. The predecessor recorded $0.00 for a
   * turn that billed $0.0475 and left no trace of having failed — a small
   * plausible number is the one kind of wrong an operator never questions. So
   * whenever real tokens were charged at no rate, name the provider and the
   * model, because "which model is unpriced" is the only fact needed to fix it.
   *
   * A local Ollama stays quiet: its entry is marked `free`, which is what turns
   * its zero from an absence into a statement. A call that reported no tokens at
   * all stays quiet too — `usage_reported: false` in token_details already says
   * that, and warning twice about one silence trains people to ignore it.
   *
   * @param string $providerId
   *   The provider that served the call.
   * @param string $modelId
   *   The model that served it.
   * @param string $tag
   *   The call-site tag, so the line points at where to look.
   * @param array{total: float, free: bool, unpriced: list<string>} $cost
   *   What {@see ModelPricing::cost()} answered.
   */
  private function announceUnpriced(string $providerId, string $modelId, string $tag, array $cost): void {
    if ($cost['unpriced'] === []) {
      return;
    }

    $this->logger->warning('No price for @provider:@model — @classes tokens were recorded at $0 (@tag). Cost reporting under-counts this model until it is added to aincient_core.pricing.', [
      '@provider' => $providerId,
      '@model' => $modelId,
      '@classes' => implode(', ', $cost['unpriced']),
      '@tag' => $tag !== '' ? $tag : 'untagged',
    ]);
  }

  /**
   * The four billable token classes, from whatever the bridge reported.
   *
   * DISJOINT, unlike the columns they end up in: cache writes are returned
   * separately from plain input because they are billed at a premium (1.25x
   * input on Anthropic's 5-minute TTL), and {@see ModelPricing} now has a rate
   * to charge that with. The predecessor folded creation into input at 1.0x and
   * documented the ~25% understatement as unavoidable — it was only unavoidable
   * because `ai_metering`'s table carried no cache-write rate to read.
   *
   * @param \Symfony\AI\Platform\TokenUsage\TokenUsageInterface|null $usage
   *   The provider's accounting, or NULL when it reported none.
   *
   * @return array{0: int, 1: int, 2: int, 3: int}
   *   Plain input, output, cache-read and cache-write tokens.
   */
  private function counts(?TokenUsageInterface $usage): array {
    if ($usage === NULL) {
      return [0, 0, 0, 0];
    }

    // Thinking tokens are ADDED to output, and this is not double counting:
    // Gemini reports `thoughtsTokenCount` alongside `candidatesTokenCount` and
    // bills both as output, while Anthropic folds thinking into `output_tokens`
    // and leaves thinkingTokens null. Verified in each bridge's
    // TokenUsageExtractor rather than assumed.
    $output = (int) $usage->getCompletionTokens() + (int) $usage->getThinkingTokens();

    // Cache READ, not the combined cached figure. Anthropic's extractor sums
    // creation + read into getCachedTokens(), but the two are billed at opposite
    // ends of the scale — a read is 0.1x input, a write is 1.25x — so charging
    // creation at the read rate would understate it by more than 12x.
    $cacheRead = $usage->getCacheReadTokens() ?? $usage->getCachedTokens() ?? 0;

    // Cache CREATION is counted, and leaving it out was measurably wrong.
    // Anthropic's `input_tokens` EXCLUDES both cache figures: the first turn of a
    // real pages-agent conversation reported input 2 with cache_creation 7415, so
    // a row that priced only `input_tokens` charged $0.000004 for a call that
    // billed about $0.019 — a 100% miss on the dominant term. It is returned on
    // its own rather than folded into input so it can be charged the write
    // premium; the recorder still sums the two into the input COLUMN, which the
    // schema defines as everything charged at or above the input rate.
    $cacheWrite = (int) $usage->getCacheCreationTokens();

    return [(int) $usage->getPromptTokens(), $output, (int) $cacheRead, $cacheWrite];
  }

  /**
   * The provider-specific breakdown the three columns cannot hold.
   *
   * Keeps the predecessor's two keys (`reasoning`, `total`) so a dashboard
   * reading token_details finds what it always found, and adds `usage_reported`
   * — the flag that makes a zero honest. A row with 0/0 and
   * `usage_reported: false` says "the provider filed no accounting for this
   * call"; the same row without the flag would be indistinguishable from a call
   * that genuinely cost nothing, which is the failure mode this whole change is
   * about.
   *
   * @param \Symfony\AI\Platform\TokenUsage\TokenUsageInterface|null $usage
   *   The provider's accounting, or NULL when it reported none.
   * @param int $input
   *   The recorded input tokens.
   * @param int $output
   *   The recorded output tokens.
   *
   * @return string
   *   A JSON object. Never NULL — the flag is the point.
   */
  private function details(?TokenUsageInterface $usage, int $input, int $output): string {
    if ($usage === NULL) {
      return (string) json_encode(['usage_reported' => FALSE]);
    }

    $details = [
      'usage_reported' => TRUE,
      'reasoning' => $usage->getThinkingTokens(),
      // Anthropic reports no total at all, so the arithmetic sum stands in —
      // otherwise every Anthropic row would carry total 0 next to non-zero
      // input and output, which reads as corruption.
      'total' => $usage->getTotalTokens() ?? ($input + $output),
      // Included in the input_tokens column (see counts()); kept here as its own
      // number so the write premium can be reconciled against a real invoice.
      'cache_creation' => $usage->getCacheCreationTokens(),
      'cache_read' => $usage->getCacheReadTokens(),
      'tool' => $usage->getToolTokens(),
    ];

    return (string) json_encode(array_filter(
      $details,
      static fn (mixed $v): bool => $v !== NULL,
    ));
  }

}
