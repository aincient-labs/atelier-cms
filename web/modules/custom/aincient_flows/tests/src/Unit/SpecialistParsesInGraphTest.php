<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_flows\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Guards where a brand specialist parses its model's output.
 *
 * Every specialist must run the engine's tolerant JSON parser between the model
 * and the validator:
 *
 *   simple_chat → json_to_data (tolerant) → brand_validate_slice → data_to_json
 *
 * This used to be PHP inside the validator, stripping a ```-fence with a regex
 * anchored at both ends of the string. Specialists emit fenced JSON followed by
 * a rationale about half the time, which puts the closing fence mid-string: the
 * decode failed, the slice was passed through as an "intentional no-op", and the
 * agent told the user their brand had changed when nothing had. A coin flip
 * between working and silently doing nothing, invisible in the logs because a
 * NULL returned inside PHP leaves no trace.
 *
 * Parsing in the graph fixes both halves. The engine's parser is tested upstream
 * and handles the shapes models actually emit, and — because a node execution is
 * a job with recorded input and output — a parse failure is now something you
 * can SEE in the job trail for the run that failed.
 *
 * That makes the wiring load-bearing, and wiring is config: no PHP change can
 * break it, and no PHP test would catch it being undone on the canvas. Hence
 * this test, in the same spirit as {@see ShippedLoopGateTest}.
 *
 * @group aincient_flows
 */
#[Group('aincient_flows')]
final class SpecialistParsesInGraphTest extends TestCase {

  /**
   * Node type of the engine's tolerant JSON parser.
   */
  private const PARSER = 'json_to_data';

  /**
   * Node type of our slice validator.
   */
  private const VALIDATOR = 'aincient_flows_brand_validate_slice';

  /**
   * The shipped brand specialist workflows, keyed by workflow id.
   *
   * @return array<string, array<string, mixed>>
   *   Parsed config, keyed by workflow id.
   */
  private function specialists(): array {
    $dir = dirname(__DIR__, 7) . '/config/sync';
    $out = [];
    foreach (glob($dir . '/flowdrop_workflow.flowdrop_workflow.aincient_brand_specialist_*.yml') ?: [] as $file) {
      $config = Yaml::parseFile($file);
      $out[$config['id']] = $config;
    }
    self::assertNotEmpty($out, "No brand specialist workflows found in $dir.");
    return $out;
  }

  /**
   * Node ids keyed by node type, for one workflow.
   *
   * @param array<string, mixed> $config
   *   A parsed workflow config.
   *
   * @return array<string, string>
   *   Node id keyed by node type id.
   */
  private function nodeTypes(array $config): array {
    $out = [];
    foreach ($config['nodes'] ?? [] as $node) {
      $out[$node['id']] = (string) ($node['data']['metadata']['node_type_id'] ?? '');
    }
    return $out;
  }

  /**
   * The COLOUR specialist owns the colour maths, so it must defend against a
   * narrow ask rather than obey one into a bad result.
   *
   * Live regression, Locked brand + a pale yellow draft + "make primary
   * darker". The orchestrator framed the ask as "lower lightness only. Don't
   * change hue/chroma or any other token." and the specialist complied exactly:
   *  - chroma pinned at 0.071 (a near-white tint's chroma, meaningless at
   *    L 0.62) → oklch(0.65 0.071 103), rendered #97915E, a khaki;
   *  - brand_primary_foreground left at the previous colour's near-white →
   *    2.94:1, a WCAG Fail, reported as "nothing else touched".
   *
   * The orchestrator side is fixed in BrandState::SURGICAL_MEANS; this is the
   * second line of defence.
   */
  public function testColourSpecialistDefendsAxisCouplingAndPairs(): void {
    $colour = $this->specialists()['aincient_brand_specialist_colour'] ?? NULL;
    self::assertNotNull($colour, 'The colour specialist workflow is missing.');
    $prompt = json_encode($colour);

    foreach ([
      'LIGHTNESS AND CHROMA ARE COUPLED' => 'the coupling is not stated',
      'Chroma must FOLLOW' => 'chroma is not told to track lightness',
      'AN ON-COLOUR IS PART OF ITS SURFACE' => 'the pair is still framed as a separate token',
      'shipping a WCAG Fail is never the smaller change' => 'no floor against obeying a narrow ask into a Fail',
    ] as $needle => $why) {
      self::assertStringContainsString($needle, $prompt, "Colour specialist: $why.");
    }
  }

  /**
   * The colour specialist writes a swatch BACK, not a literal computed from it.
   *
   * 29 specialist outputs on record, 0 carrying a var(--color-*) swatch: every
   * swatch the studio picked was read as a number and overwritten with an
   * oklch() literal on the first touch, so the swatch grid unlit and the
   * imagery brief lost its name (cms #44). The ramp is printed by
   * TokenGrounding; this is the rule that tells the model to walk it.
   */
  public function testColourSpecialistWritesSwatchesBack(): void {
    $colour = $this->specialists()['aincient_brand_specialist_colour'] ?? NULL;
    self::assertNotNull($colour, 'The colour specialist workflow is missing.');
    $prompt = json_encode($colour);

    foreach ([
      'A SWATCH SITS ON A RAMP' => 'the ramp is not introduced',
      'STEP ALONG THE RAMP' => 'a relative ask is not told to step rungs',
      'WRITE THE SWATCH BACK' => 'the model is not told to keep token space',
      'the ramp WINS over' => 'no precedence over the hold-the-hue rule',
      'ABSOLUTE COLOUR ASKS' => 'an absolute pick is not told to land on a swatch',
      'ONLY when no rung fits' => 'no escape hatch, so the model will over-snap',
      'ONE rung is a nudge' => 'no step size, so "darker" lands one rung over (live-caught: 100 → 200)',
    ] as $needle => $why) {
      self::assertStringContainsString($needle, $prompt, "Colour specialist: $why.");
    }
    self::assertStringNotContainsString('keep the same hue unless', $prompt, 'The superseded resolve-and-adjust sentence is still there.');
  }

  /**
   * The orchestrator treats a successful specialist result as FINAL.
   *
   * Live-caught on the cms #44 verification turn: the user had set primary to
   * yellow-100 by hand after the agent had made it blue. "Make primary darker"
   * → the orchestrator asked correctly, the specialist wrote yellow-200 back —
   * and the orchestrator, judging that result against its OWN earlier message
   * ("Primary is now blue"), re-delegated "darker blue (not yellow)" and
   * overwrote the user's pick. The conversation was stale; the state was not.
   */
  public function testOrchestratorTreatsASuccessfulResultAsFinal(): void {
    $file = dirname(__DIR__, 7) . '/config/sync/flowdrop_workflow.flowdrop_workflow.brand_studio.yml';
    self::assertFileExists($file, 'The brand orchestrator workflow is missing.');
    $prompt = (string) file_get_contents($file);

    foreach ([
      'A SUCCESSFUL RESULT IS FINAL' => 'a clean result can still be second-guessed',
      'YOUR MEMORY IS STALE' => 'a surprising result is not attributed to the user\'s own edit',
      'NEVER re-delegate to steer a successful result' => 'no bar on re-delegating to "correct" a colour',
      'the USER changed it by hand since' => 'the live-state header does not explain a divergence from the conversation',
    ] as $needle => $why) {
      self::assertStringContainsString($needle, $prompt, "Orchestrator: $why.");
    }
  }

  /**
   * The validator is fed by the tolerant parser, never by the model directly.
   */
  public function testValidatorIsFedByTheTolerantParser(): void {
    foreach ($this->specialists() as $id => $config) {
      $types = $this->nodeTypes($config);
      $validators = array_keys($types, self::VALIDATOR, TRUE);
      $this->assertCount(1, $validators, "$id: expected exactly one slice validator.");
      $validator = $validators[0];

      $feeders = [];
      foreach ($config['edges'] ?? [] as $edge) {
        if (($edge['target'] ?? '') === $validator) {
          $feeders[] = $types[$edge['source'] ?? ''] ?? 'unknown';
        }
      }

      $this->assertContains(
        self::PARSER,
        $feeders,
        "$id: the slice validator is fed by [" . implode(', ', $feeders) . "], not by a "
        . self::PARSER . " node. Wire simple_chat → json_to_data (parse_mode: tolerant) "
        . "→ brand_validate_slice. The validator takes DECODED data and no longer strips "
        . 'fences: feeding it the model response directly means every fenced answer is '
        . 'silently dropped, which is the bug this wiring exists to prevent.',
      );
    }
  }

  /**
   * The parser runs in tolerant mode, or fenced output still fails.
   */
  public function testParserIsTolerant(): void {
    foreach ($this->specialists() as $id => $config) {
      foreach ($config['nodes'] ?? [] as $node) {
        if (($node['data']['metadata']['node_type_id'] ?? '') !== self::PARSER) {
          continue;
        }
        $this->assertSame(
          'tolerant',
          $node['data']['config']['parse_mode'] ?? 'strict',
          "$id: node {$node['id']} parses in strict mode. Strict throws on the fenced "
          . 'JSON-plus-rationale that specialists emit about half the time; tolerant '
          . 'extracts the first fenced block and reports `success` so the graph can '
          . 'branch on failure instead of hiding it.',
        );
      }
    }
  }

}
