<?php

declare(strict_types=1);

namespace Aincient\Capability\Tests;

use Aincient\Capability\ArgNormalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests the model tool-argument normalisation (DECISIONS 0197).
 *
 * LLMs HTML-escape text they think is bound for a page; capabilities store plain
 * text and escape at output, so the entities are stripped once, here. JSON args
 * are decoded on their LEAF values only, so an entity inside a value can never
 * corrupt the envelope, and a bare non-entity `&` (a URL query) survives.
 * The end-to-end path through the FlowDrop node stays covered by
 * `aincient_flows` CapabilityToolTest::testModelEntitiesAreDecodedInArgs().
 */
#[CoversClass(ArgNormalizer::class)]
#[Group('aincient')]
final class ArgNormalizerTest extends TestCase {

  /**
   * The CapabilityToolTest JSON case: leaves decoded, envelope intact.
   */
  public function testJsonStringArgDecodesLeavesOnly(): void {
    $arg = (string) json_encode([
      'name' => 'Ember &amp; Oak',
      'tagline' => 'O&#39;Brien&#39;s &lt;pick&gt;',
      'description' => 'Ben &quot;Boss&quot; &amp; Co — see /a?x=1&y=2',
    ]);

    $out = ArgNormalizer::normalizeArg($arg);

    $this->assertIsString($out);
    $this->assertSame([
      'name' => 'Ember & Oak',
      'tagline' => "O'Brien's <pick>",
      'description' => 'Ben "Boss" & Co — see /a?x=1&y=2',
    ], json_decode($out, TRUE));
    // Re-encoded unescaped: slashes and non-ASCII stay literal.
    $this->assertStringContainsString('/a?x=1&y=2', $out);
    $this->assertStringContainsString('—', $out);
  }

  /**
   * A native array arg (schema-respecting providers): leaves decoded, keys not.
   */
  public function testNativeArrayArgDecodesLeavesNotKeys(): void {
    $out = ArgNormalizer::normalizeArg([
      ['op' => 'set_meta', 'title' => 'Ember &amp; Oak'],
      ['op' => 'add_section', 'props' => ['heading' => 'O&#39;Brien', 'level' => 2, 'a&amp;b' => TRUE]],
    ]);

    $this->assertSame([
      ['op' => 'set_meta', 'title' => 'Ember & Oak'],
      ['op' => 'add_section', 'props' => ['heading' => "O'Brien", 'level' => 2, 'a&amp;b' => TRUE]],
    ], $out);
  }

  /**
   * Plain strings are decoded directly; non-strings and entity-free text pass.
   */
  #[DataProvider('passthroughAndPlain')]
  public function testScalarArgs(mixed $in, mixed $expected): void {
    $this->assertSame($expected, ArgNormalizer::normalizeArg($in));
  }

  /**
   * [input, expected].
   *
   * @return array<string, array{mixed, mixed}>
   */
  public static function passthroughAndPlain(): array {
    return [
      'named entity' => ['Ember &amp; Oak', 'Ember & Oak'],
      'numeric entities' => ['O&#39;Brien &#x27;s', "O'Brien 's"],
      'html5 entity' => ['caf&eacute; &hellip;', 'café …'],
      'bare ampersand untouched' => ['/a?x=1&y=2', '/a?x=1&y=2'],
      'entity-free text untouched' => ['Ember and Oak', 'Ember and Oak'],
      'single decode only' => ['&amp;amp;', '&amp;'],
      'integer' => [42, 42],
      'bool' => [TRUE, TRUE],
      'null' => [NULL, NULL],
      'empty string' => ['', ''],
    ];
  }

  /**
   * A string that looks like JSON but is not parses as plain text.
   */
  public function testInvalidJsonFallsBackToPlainDecode(): void {
    $this->assertSame('{not json & "x"', ArgNormalizer::normalizeArg('{not json &amp; &quot;x&quot;'));
  }

  /**
   * JSON with a bare `&` is re-encoded compactly; JSON with no `&` is verbatim.
   */
  public function testJsonEnvelopeReencodingRules(): void {
    $raw = '{"a": "x & y",  "b": [1, 2]}';
    // No entity to decode, but `&` present: parsed, re-encoded compactly.
    $this->assertSame('{"a":"x & y","b":[1,2]}', ArgNormalizer::normalizeArg($raw));
    // No `&` at all: the fast path returns it byte-identical.
    $this->assertSame('{"a": 1}', ArgNormalizer::normalizeArg('{"a": 1}'));
  }

  /**
   * Leading whitespace before the envelope still takes the JSON path.
   */
  public function testLeadingWhitespaceJson(): void {
    $this->assertSame('["a & b"]', ArgNormalizer::normalizeArg("  [\"a &amp; b\"]"));
  }

  /**
   * The data-type map behind every tool's `input_schema`.
   */
  #[DataProvider('dataTypes')]
  public function testToJsonType(string $dataType, string $jsonType): void {
    $this->assertSame($jsonType, ArgNormalizer::toJsonType($dataType));
  }

  /**
   * [Drupal data type, JSON-schema type].
   *
   * @return array<string, array{string, string}>
   */
  public static function dataTypes(): array {
    return [
      'boolean' => ['boolean', 'boolean'],
      'integer' => ['integer', 'integer'],
      'float' => ['float', 'number'],
      'decimal' => ['decimal', 'number'],
      'list' => ['list', 'array'],
      'map' => ['map', 'object'],
      'string' => ['string', 'string'],
      'unknown defaults to string' => ['entity:node', 'string'],
    ];
  }

}
