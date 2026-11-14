<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_flows\Unit\Eval;

use Drupal\aincient_flows\Eval\DocsEvalCase;
use Drupal\aincient_flows\Eval\DocsEvalRunner;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The docs-eval corpus loads, its regexes compile, and the pass rule holds.
 *
 * The corpus only runs against real models (`drush aincient:docs-eval`), so a
 * typo in a case would surface as a confusing live failure. This catches it in
 * the gate, and pins how failures() reads an observed run.
 */
#[Group('aincient_flows')]
final class DocsEvalCorpusTest extends UnitTestCase {

  public function testCorpusLoadsAndEveryPatternCompiles(): void {
    $cases = DocsEvalCase::loadDirectory(dirname(__DIR__, 4) . '/evals/docs');
    $this->assertGreaterThanOrEqual(20, count($cases));
    $kinds = array_count_values(array_map(static fn(DocsEvalCase $c) => $c->kind, $cases));
    $this->assertGreaterThanOrEqual(5, $kinds['uncovered'] ?? 0, 'Keep enough asks Atelier cannot do — they are where invention happens.');
    foreach ($cases as $case) {
      foreach (array_merge($case->mentionAll, $case->proseNot === '' ? [] : [$case->proseNot]) as $pattern) {
        $this->assertNotFalse(@preg_match($pattern, ''), "{$case->name}: bad regex $pattern");
      }
      foreach ($case->linksAny as $link) {
        $this->assertStringStartsWith('/docs', $link, $case->name);
      }
    }
  }

  public function testFailuresReadsARun(): void {
    $case = new DocsEvalCase('x', 'x.yml', 'q', 'uncovered', ['/docs/components'], ['/carousel/i'], '/typeform/i');
    $good = [
      'answer' => 'The docs do not cover forms. A carousel: [Components](https://aincient-labs.com/docs/components).',
      'error' => '',
      'searched' => TRUE,
      'links' => ['/docs/components'],
      'judge' => ['grounded' => TRUE, 'admits_gap' => TRUE, 'unsupported' => []],
    ];
    $this->assertSame([], DocsEvalRunner::failures($case, $good));

    $bad = ['answer' => 'Paste a Typeform.', 'searched' => FALSE, 'links' => [], 'judge' => ['grounded' => FALSE, 'admits_gap' => FALSE, 'unsupported' => ['Typeform embed']]] + $good;
    $failed = DocsEvalRunner::failures($case, $bad);
    $this->assertCount(6, $failed, implode("\n", $failed));
  }

  public function testANegatedSentenceIsNotAClaim(): void {
    $pattern = '/import(er)? (tool|plugin)/i';
    $this->assertNull(DocsEvalRunner::assertedMatch($pattern, "Atelier doesn't have an import tool. Copy posts by hand."));
    $this->assertNull(DocsEvalRunner::assertedMatch($pattern, 'There is no import tool for WordPress.'));
    $this->assertSame('import tool', DocsEvalRunner::assertedMatch($pattern, "It's not built in. Use the import tool in Settings."));
  }

}
