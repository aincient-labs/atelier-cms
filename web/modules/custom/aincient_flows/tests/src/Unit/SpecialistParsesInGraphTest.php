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
