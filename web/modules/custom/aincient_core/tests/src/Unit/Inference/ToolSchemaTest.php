<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_core\Unit\Inference;

use Drupal\aincient_core\Inference\ToolSchema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Locks down the JSON Schema guarantees the drupal/ai path could not make.
 *
 * Each case here corresponds to a concrete failure we shipped a patch or a
 * workaround for. They are regression tests for the reasons this migration
 * happened, not incidental coverage.
 */
#[CoversClass(ToolSchema::class)]
final class ToolSchemaTest extends TestCase {

  private function schema(): ToolSchema {
    return new ToolSchema();
  }

  /**
   * A no-argument tool MUST render a valid empty object schema, never NULL.
   *
   * This is `patches/ai-tools-null-parameters.patch` as a test: drupal/ai emitted
   * `"parameters": null`, which OpenAI tolerated and a LiteLLM proxy in front of
   * Anthropic died on — failing the whole request, not just the tool.
   */
  public function testNoArgumentToolRendersEmptyObjectSchema(): void {
    $tools = $this->schema()->toTools([
      ['name' => 'list_pages', 'description' => 'List every page.'],
    ]);

    self::assertCount(1, $tools);
    $parameters = $tools[0]->getParameters();
    self::assertNotNull($parameters, 'A no-argument tool must never render NULL parameters.');
    self::assertSame(['type' => 'object'], $parameters);
    self::assertArrayNotHasKey(
      'properties',
      $parameters,
      'properties must be omitted, not emitted empty — [] encodes as a JSON array, not an object.',
    );
  }

  /**
   * Required-ness travels as the object-level array JSON Schema defines.
   *
   * The drupal/ai path could not express this: ToolsPropertyInput rendered
   * `"required": <bool>` INSIDE each property, which Anthropic rejects, so
   * required-ness had to be smuggled into the description as "(required)".
   */
  public function testRequirednessLiftsToObjectLevelArray(): void {
    $tools = $this->schema()->toTools([
      [
        'name' => 'create_page',
        'description' => 'Create a page.',
        'input_schema' => [
          'type' => 'object',
          'properties' => [
            'title' => ['type' => 'string', 'description' => 'The title', 'required' => TRUE],
            'teaser' => ['type' => 'string', 'description' => 'Optional teaser', 'required' => FALSE],
          ],
        ],
      ],
    ]);

    $parameters = $tools[0]->getParameters();
    self::assertSame(['title'], $parameters['required']);

    // The per-property boolean must be GONE from both properties — it is invalid
    // JSON Schema draft 2020-12 wherever it appears.
    self::assertArrayNotHasKey('required', $parameters['properties']['title']);
    self::assertArrayNotHasKey('required', $parameters['properties']['teaser']);

    // And the description must be untouched — no "(required)" suffix hack.
    self::assertSame('The title', $parameters['properties']['title']['description']);
  }

  /**
   * An explicit JSON Schema `required` array is honoured as-is.
   */
  public function testExplicitRequiredArrayIsPreserved(): void {
    $tools = $this->schema()->toTools([
      [
        'name' => 'set_brand',
        'input_schema' => [
          'type' => 'object',
          'properties' => ['colour' => ['type' => 'string']],
          'required' => ['colour'],
        ],
      ],
    ]);

    self::assertSame(['colour'], $tools[0]->getParameters()['required']);
  }

  /**
   * Nested object properties are cleaned recursively.
   */
  public function testNestedPropertiesAreNormalisedRecursively(): void {
    $tools = $this->schema()->toTools([
      [
        'name' => 'update_chrome',
        'input_schema' => [
          'type' => 'object',
          'properties' => [
            'header' => [
              'type' => 'object',
              'properties' => [
                'logo' => ['type' => 'string', 'required' => TRUE],
                'tagline' => ['type' => 'string', 'required' => FALSE],
              ],
            ],
          ],
        ],
      ],
    ]);

    $header = $tools[0]->getParameters()['properties']['header'];
    self::assertSame(['logo'], $header['required']);
    self::assertArrayNotHasKey('required', $header['properties']['logo']);
    self::assertArrayNotHasKey('required', $header['properties']['tagline']);
  }

  /**
   * A malformed binding is skipped, never fatal.
   *
   * A broken tool definition must not cost the operator the entire turn — the
   * silent-failure lesson from DECISIONS 0278/0279 cuts both ways.
   */
  public function testMalformedDefinitionsAreSkippedNotFatal(): void {
    $tools = $this->schema()->toTools([
      ['description' => 'no name at all'],
      ['name' => '   '],
      'not an array',
      ['name' => 'valid_tool'],
    ]);

    self::assertCount(1, $tools);
    self::assertSame('valid_tool', $tools[0]->getName());
  }

  /**
   * A non-array input_schema degrades to a valid empty object schema.
   */
  public function testNonArraySchemaDegradesToEmptyObject(): void {
    $tools = $this->schema()->toTools([
      ['name' => 'weird', 'input_schema' => 'nonsense'],
    ]);

    self::assertSame(['type' => 'object'], $tools[0]->getParameters());
  }

}
