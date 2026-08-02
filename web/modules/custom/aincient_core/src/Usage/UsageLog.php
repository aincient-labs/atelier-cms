<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Usage;

use Drupal\aincient_core\Event\UsageRecordedEvent;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Database\Connection;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Writes the usage log — the only thing that puts a row in `aincient_ai_usage`.
 *
 * THE STORE IS OURS. Atelier's rows used to go through
 * `ai_metering`'s QuotaManager::logUsage(), which meant depending on that module
 * for the table to exist at all. What the dependency bought, once the pricing,
 * the dashboard and the recording seams had all been replaced, was one CREATE
 * TABLE and one INSERT — and it charged for that a config object, a permission
 * set, two admin routes we had to close, a Views integration nothing rendered
 * and a pricing sync we refused to run. {@see aincient_core_schema()} owns the
 * table now and this class is its writer.
 *
 * WHAT IS DELIBERATELY NOT HERE. The predecessor did four other things in the
 * same method, and each was dropped for its own reason. The QUOTA upsert
 * maintained a per-user monthly counter that nothing could act on — the
 * enforcement hook was invoked only from a subscriber to a `drupal/ai` event
 * nothing has dispatched since Atelier moved onto its own inference seams, so a
 * budget could be exceeded forever without a single call being refused; a
 * control that accepts a value it will never apply is worse than a missing one.
 * The ALERT service mailed a threshold crossing on that same dead counter. The
 * `hook_ai_metering_record_alter()` invocation let any module rewrite a
 * financial record on its way to disk, which is not an extension point a ledger
 * should offer. And `provider_type`/`status` were columns with one value in
 * them.
 *
 * ORDER MATTERS at the end: insert, then invalidate, then dispatch. The cache
 * tag has to be gone before a subscriber can react, or the console's frame and
 * the dashboard's cached total would describe the same call differently for as
 * long as the page stayed cached. Nothing here is wrapped in a try/catch: the
 * caller ({@see \Drupal\aincient_core\Usage\UsageRecorder::record()}) owns
 * that boundary, and swallowing a write failure here as well would leave it with
 * nothing to log.
 */
final class UsageLog {

  /**
   * The cache tag every reader of the log varies on.
   *
   * One tag for the whole table, not one per user. A per-uid tag would let a
   * personal page survive another editor's call, but every section of the usage
   * dashboard is a site-wide aggregate, so any row invalidates all of them
   * anyway — and the two tags would then have to agree about which is authority.
   */
  public const CACHE_TAG = 'aincient_ai_usage';

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    private readonly EventDispatcherInterface $eventDispatcher,
  ) {}

  /**
   * Records one completed call.
   *
   * The timestamp is the REQUEST time, not the wall clock at the moment of the
   * insert. Several rows come out of one agent turn and they should carry one
   * time: a turn whose rows straddle a second boundary would sort by id and
   * timestamp into two different orders, and the period filters on the dashboard
   * would occasionally cut a turn in half.
   *
   * @param int $uid
   *   The account the call was made for.
   * @param string $providerId
   *   The adapter that served it.
   * @param string $modelId
   *   The model that served it, as resolved.
   * @param string $operation
   *   One of the UsageRecorder OPERATION_* values.
   * @param int $inputTokens
   *   Input tokens, cache writes folded in — see the column's description.
   * @param int $outputTokens
   *   Output tokens, thinking included.
   * @param int $cachedTokens
   *   Cache-read tokens, a subset of the input figure.
   * @param float $costUsd
   *   What the call cost, per Atelier's rate table.
   * @param string|null $contextId
   *   The call-site tag, or NULL when the caller has none.
   * @param string|null $tokenDetails
   *   A JSON breakdown for what the columns cannot hold.
   */
  public function record(
    int $uid,
    string $providerId,
    string $modelId,
    string $operation,
    int $inputTokens,
    int $outputTokens,
    int $cachedTokens,
    float $costUsd,
    ?string $contextId = NULL,
    ?string $tokenDetails = NULL,
  ): void {
    $this->database->insert(UsageQuery::TABLE)
      ->fields([
        'uid' => $uid,
        'timestamp' => $this->time->getRequestTime(),
        'provider_id' => $providerId,
        'model_id' => $modelId,
        'operation' => $operation,
        'input_tokens' => $inputTokens,
        'output_tokens' => $outputTokens,
        'cached_tokens' => $cachedTokens,
        'cost_usd' => $costUsd,
        'context_id' => $contextId,
        'token_details' => $tokenDetails,
      ])
      ->execute();

    $this->cacheTagsInvalidator->invalidateTags([self::CACHE_TAG]);

    $this->eventDispatcher->dispatch(
      new UsageRecordedEvent(
        $uid,
        $providerId,
        $modelId,
        $operation,
        $inputTokens,
        $outputTokens,
        $cachedTokens,
        $costUsd,
      ),
      UsageRecordedEvent::EVENT_NAME,
    );
  }

}
