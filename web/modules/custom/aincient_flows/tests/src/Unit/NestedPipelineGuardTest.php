<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_flows\Unit;

use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tripwire: a nested pipeline re-opens the deferred scratchpad-scoping question.
 *
 * The per-turn scratchpad (DECISIONS 0379) is addressed at `scope: pipeline`,
 * because one console turn is one pipeline. Sub-workflow scoping was
 * deliberately NOT designed, and safely so: no capability spawns a child
 * pipeline today. Specialists run inline in the parent's pipeline, so they
 * already share the parent's scratchpad, which is what the agent loop needs.
 *
 * The risk in deferring is that it rots into a silent wrong answer: someone
 * later has a capability start a real sub-pipeline, the child resolves a DIFFERENT
 * scope id, and its tool results land in a store the parent never reads — the
 * loop then cannot see what its own specialist did, and re-does the work. That
 * failure looks like a model problem and is not one. This test IS the alarm.
 *
 * No Drupal bootstrap: it reads plugin sources off disk, so container state
 * cannot defeat it.
 */
#[Group('aincient_flows')]
final class NestedPipelineGuardTest extends UnitTestCase {

  /**
   * Symbols that mean "this code starts a workflow run of its own".
   *
   * Matched as substrings against capability sources. Deliberately broad: a
   * false positive costs one reviewer a minute, a false negative costs a
   * silently broken agent loop.
   *
   * @var array<int, string>
   */
  private const PIPELINE_SPAWNING_SYMBOLS = [
    'executeTurn',
    'executeWorkflow',
    'createPipeline',
    'startPipeline',
    'WorkflowExecutor',
    'PipelineFactory',
  ];

  /**
   * No capability starts a pipeline; the scratchpad's turn scope stays correct.
   */
  public function testNoCapabilitySpawnsNestedPipelines(): void {
    // From tests/src/Unit up to modules/custom: Unit → src → tests →
    // aincient_flows → custom.
    $customModules = dirname(__DIR__, 4);
    $offenders = [];
    foreach (glob($customModules . '/*/src/Plugin/AiCapability/*.php') ?: [] as $file) {
      $source = (string) file_get_contents($file);
      foreach (self::PIPELINE_SPAWNING_SYMBOLS as $symbol) {
        if (str_contains($source, $symbol)) {
          $offenders[] = basename($file, '.php') . ' (' . $symbol . ')';
        }
      }
    }
    sort($offenders);

    $message = <<<'TXT'
A capability now looks like it starts a workflow run of its own — which makes the
sub-workflow scratchpad question REAL, and it was deferred on the grounds that it
was not (DECISIONS 0379).

The per-turn scratchpad lives at `scope: pipeline`. If this capability runs its
specialist in a NEW pipeline, that child resolves a different scope id, so its
tool_calls and tool results are written where the parent's conversation read
never looks. The parent agent then cannot see what its own specialist did and
will re-do the work — a failure that reads as a model problem but is a scoping
problem.

Decide, then record it in the decision log: does the child INHERIT the parent's
scratchpad address (pass the parent scope id down) or open its OWN (and hand its
results back to the parent as a tool result)? Then either wire that and add this
capability below, or keep the specialist inline in the parent pipeline.
TXT;

    $this->assertSame([], $offenders, $message);
  }

}
