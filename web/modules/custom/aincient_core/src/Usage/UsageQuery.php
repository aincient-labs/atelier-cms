<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Usage;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Database\StatementInterface;

/**
 * Reads the usage log — the questions the usage surfaces are built out of.
 *
 * Two summaries for the dashboard ({@see self::byCallSite()},
 * {@see self::byModel()}) over {@see self::totals()}, a paged window for the call
 * log ({@see self::page()}, {@see self::count()}), a stream for the exports, and
 * {@see self::unpricedModels()} — the one that says a figure above it is short.
 *
 * THE TABLE AND THE QUESTIONS ARE BOTH OURS. `aincient_ai_usage` is installed by
 * this module ({@see aincient_core_schema()}) and written by
 * {@see \Drupal\aincient_core\Usage\UsageLog}; this class is the read side of the
 * same store. It replaced `ai_metering`'s `QuotaManager::getDashboardData()`,
 * which groups by uid and month and has no notion of a CALL SITE — the one axis
 * worth having, and the reason the aggregation is reimplemented here rather than
 * borrowed.
 *
 * STILL DEFENSIVE ABOUT THE TABLE, for one narrow reason. The module owns the
 * schema, so on any sane install the table is there — but `hook_schema()` runs
 * only on a fresh install, and a site that upgraded without running
 * {@see aincient_core_update_11007()} has the code and not the table. Every
 * method is safe to call in that state: {@see self::available()} answers FALSE
 * and the controller says so on the page, which is a report an operator can act
 * on. A page that fataled instead would report a missing update as a broken site.
 *
 * ORDERING IS DONE BY THE CALLER, IN PHP. Every result set here is one row per
 * distinct call site, model or editor — tens of rows on any real site — so
 * sorting them in the database buys nothing and would put an ORDER BY on an
 * aggregate alias into every query, which is the sort of thing that works on one
 * engine and not the next.
 */
final class UsageQuery {

  /**
   * The log table, installed by this module.
   */
  public const TABLE = 'aincient_ai_usage';

  public function __construct(
    private readonly Connection $database,
  ) {}

  /**
   * Whether there is a usage log on this site to read at all.
   *
   * The TABLE is the whole question. It used to be paired with a
   * `moduleExists('ai_metering')` check because the store belonged to an optional
   * module that could be there without its schema, or the reverse; now that this
   * module installs the table, asking whether this module is installed would be
   * asking whether the code currently running exists. What remains possible is a
   * site upgraded past {@see aincient_core_update_11007()} without running it,
   * and that is exactly what a `tableExists()` catches.
   */
  public function available(): bool {
    return $this->database->schema()->tableExists(self::TABLE);
  }

  /**
   * The period totals: what was spent, over how many calls and how many tokens.
   *
   * @param int|null $since
   *   Unix timestamp to count from, or NULL for the whole log.
   *
   * @return array{calls: int, spend: float, input: int, output: int, cached: int, tokens: int}
   *   Zeroes throughout when there is nothing to report — including when the
   *   table has not been created yet.
   */
  public function totals(?int $since): array {
    $empty = ['calls' => 0, 'spend' => 0.0, 'input' => 0, 'output' => 0, 'cached' => 0, 'tokens' => 0];
    if (!$this->available()) {
      return $empty;
    }

    $query = $this->database->select(self::TABLE, 't');
    $query->addExpression('COUNT(*)', 'calls');
    $query->addExpression('SUM([t].[cost_usd])', 'spend');
    $query->addExpression('SUM([t].[input_tokens])', 'input');
    $query->addExpression('SUM([t].[output_tokens])', 'output');
    $query->addExpression('SUM([t].[cached_tokens])', 'cached');
    $this->period($query, $since);

    $row = $query->execute()?->fetchAssoc();
    if (!$row) {
      return $empty;
    }

    // SUM() over an empty set is NULL, and on PostgreSQL a SUM over NUMERIC
    // arrives as a string. Both are cast here so nothing downstream has to know.
    $totals = [
      'calls' => (int) $row['calls'],
      'spend' => (float) $row['spend'],
      'input' => (int) $row['input'],
      'output' => (int) $row['output'],
      'cached' => (int) $row['cached'],
    ];
    // `cached_tokens` is a SUBSET of the input column (the recorder folds cache
    // reads in), so adding it here would count those tokens twice.
    $totals['tokens'] = $totals['input'] + $totals['output'];
    return $totals;
  }

  /**
   * The models that were charged nothing while consuming real tokens.
   *
   * THE MOST IMPORTANT QUERY ON THE PAGE. A row with tokens and
   * `cost_usd = 0` is a call Atelier could not price when it recorded
   * it, and the only trace it leaves is a total that is too low — a small
   * plausible number, which is the one kind of wrong nobody questions. Grouped by
   * model because that is the fact needed to fix it: the rate goes into
   * `aincient_core.pricing` under a `provider:model`.
   *
   * Rows with no tokens at all are excluded. Those are calls where the provider
   * filed no accounting (`usage_reported: false` in `token_details`), which is a
   * different silence and not one a rate would cure.
   *
   * @return list<array{provider_id: string, model_id: string, calls: int, tokens: int}>
   *   One row per model, unordered.
   */
  public function unpricedModels(?int $since): array {
    if (!$this->available()) {
      return [];
    }

    $query = $this->database->select(self::TABLE, 't');
    $query->addField('t', 'provider_id', 'provider_id');
    $query->addField('t', 'model_id', 'model_id');
    $query->addExpression('COUNT(*)', 'calls');
    $query->addExpression('SUM([t].[input_tokens] + [t].[output_tokens])', 'tokens');
    $query->groupBy('t.provider_id');
    $query->groupBy('t.model_id');
    $query->condition('t.cost_usd', 0);
    $query->where('([t].[input_tokens] + [t].[output_tokens]) > 0');
    $this->period($query, $since);

    $rows = [];
    foreach ($query->execute() ?? [] as $row) {
      $rows[] = [
        'provider_id' => (string) $row->provider_id,
        'model_id' => (string) $row->model_id,
        'calls' => (int) $row->calls,
        'tokens' => (int) $row->tokens,
      ];
    }
    return $rows;
  }

  /**
   * Spend, calls and tokens per call site — the section this page exists for.
   *
   * @return list<array{context_id: ?string, calls: int, tokens: int, spend: float}>
   *   One row per distinct tag, unordered. NULL and '' are one row: both mean
   *   "no tag", and splitting them would put the same fact on two lines.
   */
  public function byCallSite(?int $since): array {
    $rows = [];
    foreach ($this->aggregate(['context_id'], $since) as $row) {
      $tag = (string) ($row->context_id ?? '');
      if (isset($rows[$tag])) {
        $rows[$tag]['calls'] += (int) $row->calls;
        $rows[$tag]['tokens'] += (int) $row->tokens;
        $rows[$tag]['spend'] += (float) $row->spend;
        continue;
      }
      $rows[$tag] = [
        'context_id' => $tag,
        'calls' => (int) $row->calls,
        'tokens' => (int) $row->tokens,
        'spend' => (float) $row->spend,
      ];
    }
    return array_values($rows);
  }

  /**
   * Spend, calls and tokens per `provider:model`.
   *
   * @return list<array{provider_id: string, model_id: string, calls: int, tokens: int, spend: float, unpriced_calls: int}>
   *   One row per model, unordered.
   */
  public function byModel(?int $since): array {
    $rows = [];
    foreach ($this->aggregate(['provider_id', 'model_id'], $since) as $row) {
      $rows[] = [
        'provider_id' => (string) $row->provider_id,
        'model_id' => (string) $row->model_id,
        'calls' => (int) $row->calls,
        'tokens' => (int) $row->tokens,
        'spend' => (float) $row->spend,
        'unpriced_calls' => (int) $row->unpriced_calls,
      ];
    }
    return $rows;
  }

  // TWO METHODS USED TO LIVE HERE AND WERE DELETED WITH THEIR CALLERS.
  // `byEditor()` fed the dashboard's per-editor table, and `recent()` fed its
  // fifty-row tail. The table went because it is one row and no information on
  // an appliance one person administers — `uid` survives as a column in both
  // exports, which is where a multi-editor site can still pivot on it. The tail
  // went because it became a page of its own: `page()` and `count()` below serve
  // the call log, which is the same rows without a cap. Kept as a note rather
  // than as unreachable code: an aggregate nothing calls is an aggregate nothing
  // proves, and the next reader would have to work out which surface wanted it.

  /**
   * Every row in the period, for the exports.
   *
   * Returns the STATEMENT rather than an array so the CSV can be streamed: an
   * export is the one place on this surface that can meet a table with a year of
   * rows in it, and buffering all of them to build a download is how a report
   * page becomes a memory limit.
   *
   * @return \Traversable<int, object>
   *   Raw log rows, oldest first — an export is read as a ledger.
   */
  public function stream(?int $since): \Traversable {
    if (!$this->available()) {
      return new \ArrayIterator([]);
    }

    $query = $this->database->select(self::TABLE, 't')
      ->fields('t', [
        'id', 'uid', 'timestamp', 'provider_id', 'model_id', 'operation',
        'context_id', 'input_tokens', 'output_tokens', 'cached_tokens',
        'cost_usd',
      ]);
    $this->period($query, $since);
    $query->orderBy('t.timestamp', 'ASC');
    $query->orderBy('t.id', 'ASC');

    $statement = $query->execute();
    return $statement instanceof StatementInterface ? $statement : new \ArrayIterator([]);
  }

  /**
   * The shared GROUP BY: calls, tokens, spend and the zero-cost count.
   *
   * `unpriced_calls` travels with every aggregate because a section that shows a
   * spend figure has to be able to say when that figure is short. Counting it in
   * the same pass keeps it impossible for the count and the sum to describe
   * different sets of rows.
   *
   * @param list<string> $columns
   *   The columns to group on.
   * @param int|null $since
   *   Unix timestamp to count from, or NULL for the whole log.
   *
   * @return iterable<int, object>
   *   The result rows, or nothing at all when there is no table.
   */
  private function aggregate(array $columns, ?int $since): iterable {
    if (!$this->available()) {
      return [];
    }

    $query = $this->database->select(self::TABLE, 't');
    foreach ($columns as $column) {
      $query->addField('t', $column, $column);
      $query->groupBy('t.' . $column);
    }
    $query->addExpression('COUNT(*)', 'calls');
    $query->addExpression('SUM([t].[input_tokens] + [t].[output_tokens])', 'tokens');
    $query->addExpression('SUM([t].[cost_usd])', 'spend');
    // CASE rather than a second query: one pass, and the two numbers cannot
    // disagree about which rows they counted.
    $query->addExpression(
      'SUM(CASE WHEN [t].[cost_usd] = 0 AND ([t].[input_tokens] + [t].[output_tokens]) > 0 THEN 1 ELSE 0 END)',
      'unpriced_calls',
    );
    $this->period($query, $since);

    return $query->execute() ?? [];
  }

  /**
   * Narrows a query to the selected period. NULL means the whole log.
   */
  private function period(SelectInterface $query, ?int $since): void {
    if ($since !== NULL) {
      $query->condition('t.timestamp', $since, '>=');
    }
  }

  /**
   * How many rows the log page shows at a time.
   *
   * Fifty because that is a screen a person scans rather than scrolls, and
   * because the page below it is one click away. The dashboard shows no call
   * list at all any more, so this is the only paged read on the surface.
   */
  public const PAGE_LIMIT = 50;

  /**
   * One page of the call log, newest first.
   *
   * A window, not a tail. {@see self::recent()} answers "the last N calls" and
   * cannot go further back than N; this answers "rows @offset to @offset+@limit
   * of everything in the period", which is what a pager needs. The offset is
   * applied in the database rather than by slicing a full fetch, because the
   * table this reads grows one row per AI call and a site a year old would
   * otherwise buffer its whole history to render fifty lines of it.
   *
   * Ordered the same way {@see self::recent()} is, and for the same reason: the
   * timestamp column is second-resolution and one agent turn writes several rows
   * inside one second, so timestamp alone leaves their order to the engine — and
   * an order the engine is free to change is a pager that shows the same row on
   * two pages and skips another.
   *
   * @param int|null $since
   *   Unix timestamp to read from, or NULL for the whole log.
   * @param int $offset
   *   How many rows to skip.
   * @param int $limit
   *   How many rows to return.
   *
   * @return list<array<string, mixed>>
   *   Raw log rows, newest first.
   */
  public function page(?int $since, int $offset, int $limit = self::PAGE_LIMIT): array {
    if (!$this->available()) {
      return [];
    }

    $query = $this->database->select(self::TABLE, 't')
      ->fields('t', [
        'id', 'uid', 'timestamp', 'provider_id', 'model_id', 'operation',
        'context_id', 'input_tokens', 'output_tokens', 'cached_tokens',
        'cost_usd',
      ]);
    $this->period($query, $since);
    $query->orderBy('t.timestamp', 'DESC');
    $query->orderBy('t.id', 'DESC');
    $query->range($offset, $limit);

    return array_map(
      static fn (object $row): array => (array) $row,
      $query->execute()?->fetchAll() ?? [],
    );
  }

  /**
   * How many calls the period holds — the pager's denominator.
   *
   * Separate from {@see self::totals()} rather than read off its `calls` figure:
   * that method sums four more columns to answer a different question, and a
   * pager asking it would make the cost of drawing page controls scale with the
   * width of the row instead of with COUNT(*).
   *
   * @param int|null $since
   *   Unix timestamp to count from, or NULL for the whole log.
   */
  public function count(?int $since): int {
    if (!$this->available()) {
      return 0;
    }

    $query = $this->database->select(self::TABLE, 't');
    $query->addExpression('COUNT(*)', 'calls');
    $this->period($query, $since);
    return (int) $query->execute()?->fetchField();
  }

}
