<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_core\Unit\Usage;

use Drupal\aincient_core\Usage\UsageRecorder;
use Drupal\aincient_core\Usage\CallSites;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Guards the labelling and the proportions the usage dashboard is read through.
 *
 * Split out of the controller for exactly the reason
 * {@see \Drupal\aincient_core\Form\PricingForm} is:
 * these are the assertions that are wrong-but-plausible if they slip. A tag
 * dropped for having no label, a row sorted by money while the money is
 * under-reported, or a bar scaled so the biggest consumer looks smallest — none
 * of those look like a bug on screen, they look like an answer.
 *
 * @group aincient_core
 */
#[CoversClass(CallSites::class)]
final class CallSitesTest extends UnitTestCase {

  /**
   * The subject.
   */
  private CallSites $callSites;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->callSites = new CallSites();
    $this->callSites->setStringTranslation($this->getStringTranslationStub());
  }

  /**
   * Every tag this release writes has a human name.
   */
  public function testTheTagsThisReleaseWritesAreNamed(): void {
    $this->assertSame('Agent turn', $this->callSites->label(UsageRecorder::TAG_AGENT_TURN));
    $this->assertSame('Thread naming', $this->callSites->label('aincient_chat_thread_namer'));
    $this->assertSame('Brand and flow specialists', $this->callSites->label(UsageRecorder::TAG_SIMPLE_CHAT));
    $this->assertSame('Media studio', $this->callSites->label('aincient_media_studio'));

    foreach ([UsageRecorder::TAG_AGENT_TURN, UsageRecorder::TAG_SIMPLE_CHAT] as $tag) {
      $this->assertTrue($this->callSites->isKnown($tag));
    }
  }

  /**
   * A tag we have never seen is shown verbatim, not hidden and not renamed.
   *
   * The load-bearing one. A new call site ships with a new tag, and the first
   * thing wanted from it is to see it on the dashboard before anyone thought to
   * label it. Silently dropping such a row would understate spend in exactly the
   * way this page exists to stop.
   */
  public function testAnUnrecognisedTagSurvivesVerbatim(): void {
    $this->assertSame('some_future_call_site', $this->callSites->label('some_future_call_site'));
    $this->assertFalse($this->callSites->isKnown('some_future_call_site'));
    // And it says why it has no name, rather than leaving a blank cell.
    $this->assertStringContainsString(
      'Not a call site this release writes',
      $this->callSites->description('some_future_call_site'),
    );
  }

  /**
   * A row with no tag reads as untagged, which is a fact and not a mystery.
   *
   * NULL and '' are the same state: rows written before the recorder tagged its
   * call sites. Both must land on one label so they aggregate into one row.
   */
  public function testAnAbsentTagIsUntagged(): void {
    $this->assertSame('Untagged', $this->callSites->label(NULL));
    $this->assertSame('Untagged', $this->callSites->label(''));
    $this->assertFalse($this->callSites->isKnown(NULL));
  }

  /**
   * Rows come back largest-first BY TOKENS, and the bar is scaled the same way.
   *
   * Not by spend, and this is the assertion that matters. The unpriced row below
   * consumed nine times the tokens of the priced one and recorded $0.00; sorted
   * or scaled by money it would sit last with an empty bar — the exact opposite
   * of the truth, drawn confidently.
   */
  public function testRowsAreRankedAndScaledByTokensNotBySpend(): void {
    $rows = $this->callSites->decorate([
      ['context_id' => 'aincient_chat_thread_namer', 'calls' => 8, 'tokens' => 1000, 'spend' => 0.0005],
      ['context_id' => UsageRecorder::TAG_AGENT_TURN, 'calls' => 6, 'tokens' => 9000, 'spend' => 0.0],
    ]);

    $this->assertSame(
      [UsageRecorder::TAG_AGENT_TURN, 'aincient_chat_thread_namer'],
      array_column($rows, 'context_id'),
    );
    // Relative to the largest row, so the ranking is what the eye reads.
    $this->assertSame(100.0, $rows[0]['share']);
    $this->assertEqualsWithDelta(11.11, $rows[1]['share'], 0.01);
  }

  /**
   * A period with nothing but zero-token rows divides by nothing and survives.
   */
  public function testAllZeroRowsProduceNoShareAndNoDivisionByZero(): void {
    $rows = $this->callSites->decorate([
      ['context_id' => UsageRecorder::TAG_AGENT_TURN, 'calls' => 2, 'tokens' => 0, 'spend' => 0.0],
    ]);

    $this->assertSame(0.0, $rows[0]['share']);
    $this->assertSame(2, $rows[0]['calls']);
  }

  /**
   * Nothing in, nothing out — and no notice from max() over an empty list.
   */
  public function testAnEmptyPeriodDecoratesToNothing(): void {
    $this->assertSame([], $this->callSites->decorate([]));
  }

}
