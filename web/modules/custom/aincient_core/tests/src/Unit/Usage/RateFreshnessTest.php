<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_core\Unit\Usage;

use Drupal\aincient_core\Usage\ModelPricing;
use Drupal\aincient_core\Usage\RateFreshness;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Tests\UnitTestCase;

/**
 * The one behaviour here is "what day is it", so the clock is the test subject.
 *
 * A rate goes wrong on a date, without anything else about the site changing —
 * no failed call, no error, no edit. That makes every interesting case a pair of
 * runs on either side of a boundary, which is only assertable with an injected
 * clock. Four boundaries matter and all four are off-by-one candidates:
 *
 * - the day an `expires` date is still in force versus the day after;
 * - the `checked` age threshold, where "exactly six months" must not fire;
 * - an entry with no `checked` at all, which is not fresh just because there is
 *   nothing to compare;
 * - `free`, which is exempt from staleness but NOT from an explicit expiry.
 *
 * @group aincient_core
 */
final class RateFreshnessTest extends UnitTestCase {

  /**
   * A freshness service reading the given entries, on the given date.
   *
   * @param list<array<string, mixed>> $models
   *   Rate entries as `aincient_core.pricing` would hold them.
   * @param string $today
   *   The date to run as, `Y-m-d`.
   */
  private function service(array $models, string $today): RateFreshness {
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(
      (int) (new \DateTimeImmutable($today . ' 12:00:00', new \DateTimeZone('UTC')))->format('U'),
    );

    return new RateFreshness(
      $this->getConfigFactoryStub([ModelPricing::CONFIG => ['models' => $models]]),
      $time,
    );
  }

  /**
   * The real sonnet-5 entry: an introductory rate with a published end date.
   *
   * @return list<array<string, mixed>>
   *   One entry.
   */
  private static function introductory(): array {
    return [
      [
        'provider' => 'anthropic',
        'model' => 'claude-sonnet-5',
        'input_per_mtok' => 2.0,
        'output_per_mtok' => 10.0,
        'checked' => '2026-08-02',
        'expires' => '2026-08-31',
      ],
    ];
  }

  /**
   * On the last day it applies, the rate is current — not "about to lapse".
   *
   * A rate in force "through 2026-08-31" is correct all day on the 31st. Firing
   * early would teach an operator that the warning is approximate, and a warning
   * that is approximate about dates is one they will not act on when it counts.
   */
  public function testARateIsCurrentOnItsFinalDay(): void {
    $problems = $this->service(self::introductory(), '2026-08-31')->problems();

    $this->assertSame([], $problems);
  }

  /**
   * The next day it is lapsed, and reported as a certainty.
   */
  public function testARateLapsesTheDayAfterItsEndDate(): void {
    $problems = $this->service(self::introductory(), '2026-09-01')->problems();

    $this->assertCount(1, $problems);
    $this->assertSame(RateFreshness::LAPSED, $problems[0]['state']);
    $this->assertSame('anthropic:claude-sonnet-5', $problems[0]['binding']);
    $this->assertSame('2026-08-31', $problems[0]['date']);
    $this->assertSame(1, $problems[0]['days']);
  }

  /**
   * Lapsed wins over stale, and the entry is reported ONCE.
   *
   * Long after the end date the `checked` age also crosses the threshold. Both
   * are true; only the stronger claim should be made. Reporting the same entry
   * twice would let "may still be right" sit directly under "is wrong".
   */
  public function testALapsedRateIsNotAlsoReportedAsStale(): void {
    $problems = $this->service(self::introductory(), '2027-06-01')->problems();

    $this->assertCount(1, $problems);
    $this->assertSame(RateFreshness::LAPSED, $problems[0]['state']);
  }

  /**
   * Exactly at the threshold is not yet stale; one day past it is.
   *
   * The comparison is `>`, so the boundary day itself stays quiet. Asserted from
   * both sides because an off-by-one here is invisible — the page simply warns a
   * day early or a day late, forever.
   */
  public function testStalenessFiresOnlyAfterTheThreshold(): void {
    $checked = new \DateTimeImmutable('2026-01-01', new \DateTimeZone('UTC'));
    $entry = [
      [
        'provider' => 'anthropic',
        'model' => 'claude-opus-5',
        'input_per_mtok' => 5.0,
        'checked' => '2026-01-01',
      ],
    ];

    $onThreshold = $checked->modify('+' . RateFreshness::STALE_AFTER_DAYS . ' days');
    $this->assertSame([], $this->service($entry, $onThreshold->format('Y-m-d'))->problems());

    $past = $checked->modify('+' . (RateFreshness::STALE_AFTER_DAYS + 1) . ' days');
    $problems = $this->service($entry, $past->format('Y-m-d'))->problems();
    $this->assertCount(1, $problems);
    $this->assertSame(RateFreshness::STALE, $problems[0]['state']);
    $this->assertSame(RateFreshness::STALE_AFTER_DAYS + 1, $problems[0]['days']);
  }

  /**
   * An entry never recorded as checked is stale, not fresh.
   *
   * No evidence of verification is the same epistemic position as a check from
   * years ago. Treating a missing date as "fine" would make the whole mechanism
   * opt-in, and the entries most likely to lack a date are the hastily added
   * ones.
   */
  public function testAnEntryWithNoCheckedDateIsStale(): void {
    $problems = $this->service([
      ['provider' => 'openai', 'model' => 'gpt-4o', 'input_per_mtok' => 2.5],
    ], '2026-08-02')->problems();

    $this->assertCount(1, $problems);
    $this->assertSame(RateFreshness::STALE, $problems[0]['state']);
    $this->assertSame('', $problems[0]['date']);
  }

  /**
   * A `free` entry never goes stale — there is no price sheet to re-read.
   *
   * Local inference has no vendor to check with, so nagging about it would train
   * an operator to dismiss the one warning on this page that matters.
   */
  public function testAFreeEntryDoesNotGoStale(): void {
    $problems = $this->service([
      [
        'provider' => 'ollama',
        'model' => '*',
        'input_per_mtok' => 0.0,
        'free' => TRUE,
        'checked' => '2020-01-01',
      ],
    ], '2026-08-02')->problems();

    $this->assertSame([], $problems);
  }

  /**
   * But a `free` entry with an explicit expiry still lapses.
   *
   * The staleness exemption is about there being nothing to verify. An end date
   * is not a failure to verify — somebody wrote it down on purpose, and ignoring
   * it would silently discard a deliberate statement.
   */
  public function testAFreeEntryStillLapses(): void {
    $problems = $this->service([
      [
        'provider' => 'ollama',
        'model' => '*',
        'input_per_mtok' => 0.0,
        'free' => TRUE,
        'checked' => '2026-08-01',
        'expires' => '2026-08-01',
      ],
    ], '2026-08-02')->problems();

    $this->assertCount(1, $problems);
    $this->assertSame(RateFreshness::LAPSED, $problems[0]['state']);
  }

  /**
   * A malformed date must not take down the report that would explain it.
   *
   * These strings are hand-written into YAML. A typo should degrade to "we have
   * no usable date here" — which is stale — and never to an exception on the
   * status page.
   */
  public function testAMalformedDateIsTreatedAsUnverifiedRatherThanThrowing(): void {
    $problems = $this->service([
      [
        'provider' => 'anthropic',
        'model' => 'claude-sonnet-5',
        'input_per_mtok' => 2.0,
        'checked' => 'last tuesday',
        'expires' => 'soon',
      ],
    ], '2026-08-02')->problems();

    $this->assertCount(1, $problems);
    $this->assertSame(RateFreshness::STALE, $problems[0]['state']);
  }

  /**
   * `state()` answers for a concrete model, wildcard entries included.
   *
   * The rate sheet asks per bound model, and a provider-wide `*` entry is what
   * prices a local Ollama model — so the verdict has to reach the model whose
   * price actually comes from that row.
   */
  public function testStateResolvesThroughAWildcardEntry(): void {
    $freshness = $this->service([
      [
        'provider' => 'ollama',
        'model' => '*',
        'input_per_mtok' => 1.0,
        'checked' => '2020-01-01',
      ],
    ], '2026-08-02');

    $this->assertSame(RateFreshness::STALE, $freshness->state('ollama', 'llama3'));
    $this->assertNull($freshness->state('anthropic', 'claude-sonnet-5'));
  }

}
