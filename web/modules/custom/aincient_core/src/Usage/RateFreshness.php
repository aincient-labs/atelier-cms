<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Usage;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Decides which shipped rates can no longer be trusted, and how badly.
 *
 * THE FAILURE THIS EXISTS FOR IS THE ONE THE RATE TABLE CANNOT SEE. Owning the
 * prices ({@see ModelPricing}) fixed a model having NO rate — loudly reported in
 * four places. It did nothing about a rate that is present, plausible, and wrong,
 * which is strictly worse: an unpriced model announces itself, while a lapsed one
 * keeps producing confident numbers that are quietly too low. `claude-sonnet-5`
 * ships at an INTRODUCTORY $2/$10 that Anthropic has published an end date for;
 * on 2026-09-01 the list price is $3/$15 and every figure on the usage dashboard
 * silently becomes a 33% under-report. Nothing about the site changes on that day.
 * No call fails. The only thing that changed is a date.
 *
 * WHY A DATE AND NOT A CHECK. An appliance cannot ask a vendor what it charges:
 * there may be no route to the internet, and there is deliberately no pricing
 * sync at all — the one Atelier inherited (a LiteLLM fetch into a 2,497-row
 * table nothing on this site read) was shut off, and then removed along with the
 * contrib module that shipped it, rather than repaired. So the
 * only warning available is one we WRITE DOWN IN ADVANCE, on the day we read the
 * price sheet and can see the end date printed on it. That is what `expires` is:
 * not a guess about the future, a fact recorded from the source.
 *
 * TWO DIFFERENT CLAIMS, kept apart because the honest wording differs:
 *
 * - LAPSED (`expires` is in the past) — we KNOW this number is wrong. The vendor
 *   told us when it would stop applying and that date has gone. Reported as an
 *   error, because it is not a suspicion.
 * - STALE (`checked` older than {@see self::STALE_AFTER_DAYS}) — we do not know
 *   anything, and that is the point. Nobody has compared this line to a price
 *   sheet in half a year, and provider prices move. Reported as a warning: the
 *   action is to go and look, not to change a number.
 *
 * A `free` entry is exempt from staleness. Local inference does not have a price
 * sheet to re-read, so nagging about it would train an operator to dismiss the
 * one warning here that matters. It is NOT exempt from `expires` — if someone
 * writes an end date on a free entry they meant something by it.
 */
final class RateFreshness {

  /**
   * How long a `checked` date stands before it is worth re-reading the source.
   *
   * Six months, chosen against the observed rate of change rather than a round
   * number: within the last year the frontier vendors have cut prices, added
   * cache tiers, and shipped introductory rates with end dates. A year would let
   * a whole generation of models drift; a quarter would fire on every install
   * often enough to be ignored, which costs more than it catches.
   */
  public const STALE_AFTER_DAYS = 182;

  /**
   * We know this rate is wrong: its published end date has passed.
   */
  public const LAPSED = 'lapsed';

  /**
   * We no longer know whether this rate is right: nobody has looked in months.
   */
  public const STALE = 'stale';

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Every shipped rate that has lapsed or gone stale, worst first.
   *
   * Reads the WHOLE table rather than only the bound models, unlike
   * {@see ModelPricing::unpriced()}. An unpriced model only matters if something
   * uses it; a lapsed rate matters the moment anyone might bind it, and the
   * preset that binds `claude-opus-5` in one click is exactly the case where the
   * warning has to already be there. The table is five lines — there is no
   * volume argument for narrowing it.
   *
   * @return list<array{binding: string, state: string, date: string, days: int, note: string, source: string}>
   *   `state` is self::LAPSED or self::STALE; `date` is the date that triggered
   *   it (the expiry for lapsed, the check for stale) and `days` how long ago.
   */
  public function problems(): array {
    $today = $this->today();
    $lapsed = [];
    $stale = [];

    foreach ((array) ($this->configFactory->get(ModelPricing::CONFIG)->get('models') ?? []) as $entry) {
      if (!is_array($entry)) {
        continue;
      }
      $binding = (string) ($entry['provider'] ?? '') . ':' . (string) ($entry['model'] ?? '');
      $note = (string) ($entry['note'] ?? '');
      $source = (string) ($entry['source'] ?? '');

      // Expiry first, and it wins outright: a rate that has lapsed is wrong
      // whether or not anybody checked it recently, and reporting the same entry
      // twice would let the milder claim dilute the certain one.
      $expires = $this->parse((string) ($entry['expires'] ?? ''));
      if ($expires !== NULL && $expires < $today) {
        $lapsed[] = [
          'binding' => $binding,
          'state' => self::LAPSED,
          'date' => (string) $entry['expires'],
          'days' => $this->daysBetween($expires, $today),
          'note' => $note,
          'source' => $source,
        ];
        continue;
      }

      if ($entry['free'] ?? FALSE) {
        continue;
      }

      $checked = $this->parse((string) ($entry['checked'] ?? ''));
      // An entry with no `checked` date at all is stale by definition — there is
      // no evidence it was ever verified, which is the same epistemic position as
      // a check from years ago and should read the same way.
      if ($checked === NULL) {
        $stale[] = [
          'binding' => $binding,
          'state' => self::STALE,
          'date' => '',
          'days' => 0,
          'note' => $note,
          'source' => $source,
        ];
        continue;
      }
      $age = $this->daysBetween($checked, $today);
      if ($age > self::STALE_AFTER_DAYS) {
        $stale[] = [
          'binding' => $binding,
          'state' => self::STALE,
          'date' => (string) $entry['checked'],
          'days' => $age,
          'note' => $note,
          'source' => $source,
        ];
      }
    }

    // Lapsed before stale: one is a known error and the other a request to look.
    return array_merge($lapsed, $stale);
  }

  /**
   * The state of one entry, for a row that wants to flag itself.
   *
   * @return string|null
   *   self::LAPSED, self::STALE, or NULL when the rate is current.
   */
  public function state(string $providerId, string $modelId): ?string {
    foreach ($this->problems() as $problem) {
      // Compares against the binding the ENTRY declares, which is how a `*`
      // wildcard row is matched: a provider-wide entry reports as `ollama:*`, and
      // a caller asking about `ollama:llama3` gets the same verdict because that
      // is the entry its price comes from.
      if ($problem['binding'] === $providerId . ':' . $modelId
        || $problem['binding'] === $providerId . ':*') {
        return $problem['state'];
      }
    }
    return NULL;
  }

  /**
   * Today, at midnight UTC.
   *
   * Through the injected clock rather than `new \DateTimeImmutable()` so a test
   * can stand on either side of an expiry date. Midnight so a comparison is
   * date-to-date: a rate in force "through 2026-08-31" is correct all day on the
   * 31st and wrong on the 1st, and comparing timestamps would lapse it at
   * whatever hour the test or the request happened to run.
   */
  private function today(): \DateTimeImmutable {
    return (new \DateTimeImmutable('@' . $this->time->getRequestTime()))
      ->setTimezone(new \DateTimeZone('UTC'))
      ->setTime(0, 0);
  }

  /**
   * An ISO date from config, or NULL if it is missing or unparseable.
   *
   * Never throws: these strings are hand-written into a YAML file, and a typo in
   * one must not take down the status report that would have told you about it.
   */
  private function parse(string $date): ?\DateTimeImmutable {
    if ($date === '') {
      return NULL;
    }
    $parsed = \DateTimeImmutable::createFromFormat(
      '!Y-m-d',
      $date,
      new \DateTimeZone('UTC'),
    );
    return $parsed === FALSE ? NULL : $parsed;
  }

  /**
   * Whole days from the earlier date to the later one.
   */
  private function daysBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): int {
    return (int) $from->diff($to)->days;
  }

}
