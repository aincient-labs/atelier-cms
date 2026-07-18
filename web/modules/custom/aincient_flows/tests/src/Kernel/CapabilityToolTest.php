<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_flows\Kernel;

use Drupal\aincient_flows\Plugin\FlowDropNodeProcessor\CapabilityTool;
use Drupal\ai\Service\FunctionCalling\FunctionCallPluginManager;
use Drupal\flowdrop\DTO\ParameterBag;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the CapabilityTool node — one AIncient capability as a tool node.
 *
 * Proves the capability-node contract the operator agent loop relies on: the
 * tool's input_schema is built from the bound FunctionCall's OWN context (no
 * drift), and the node actually executes that capability (a real node creation,
 * no LLM). Wiring through FlowDrop's ToolProjector/ScopedToolInvoker is
 * FlowDrop's own (unit-tested) code and is proven live in the operator graph.
 *
 * The node is constructed directly with a derivative definition (carrying
 * `function_call_id`) so we don't need the flowdrop module's heavy dependency
 * tree — only the FunctionCall manager, exactly as ToolProjector instantiates
 * it (a bare instance, no node config).
 *
 * @coversDefaultClass \Drupal\aincient_flows\Plugin\FlowDropNodeProcessor\CapabilityTool
 * @group aincient_flows
 */
#[RunTestsInSeparateProcesses]
final class CapabilityToolTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'node',
    'key',
    'ai',
    'aincient_core',
    'workflows',
    'content_moderation',
    'aincient_pages',
  ];

  /**
   * The function-call plugin manager.
   */
  private FunctionCallPluginManager $manager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');

    $this->manager = $this->container->get('plugin.manager.ai.function_calls');

    $this->setCurrentUser($this->createUser(['administer aincient pages']));
  }

  /**
   * Build a CapabilityTool bound to a FunctionCall (as ToolProjector would).
   */
  private function tool(string $functionCallId): CapabilityTool {
    return new CapabilityTool(
      [],
      'aincient_capability:' . substr($functionCallId, (int) strpos($functionCallId, ':') + 1),
      ['function_call_id' => $functionCallId],
      $this->manager,
    );
  }

  /**
   * @covers ::getParameterSchema
   *
   * The input_schema is the FunctionCall's own typed context.
   */
  public function testSchemaComesFromTheBoundCapability(): void {
    $schema = $this->tool('aincient_pages:preview_page')->getParameterSchema();

    $this->assertSame('object', $schema['type']);
    $props = $schema['properties'];
    // Typed from the primitive's real signature: preview_page takes one required
    // `ops` array of op objects (a `list` context with a SimpleToolItems object
    // item shape), so a schema-respecting provider emits a native array — not a
    // string it must remember to JSON-encode.
    $this->assertSame('array', $props['ops']['type']);
    $this->assertSame('object', $props['ops']['items']['type']);
    $this->assertNotEmpty($props['ops']['description']);
    // Required is a standard JSON-schema top-level array; ops is required.
    $this->assertContains('ops', $schema['required']);
  }

  /**
   * @covers ::process
   *
   * The node actually runs the capability — preview_page emits its widget
   * envelope (it persists nothing; the studio's Publish is the only write).
   */
  public function testProcessExecutesTheCapability(): void {
    $result = $this->tool('aincient_pages:preview_page')->process(new ParameterBag([
      'ops' => json_encode([
        ['op' => 'set_meta', 'title' => 'Lumen'],
        ['op' => 'add_section', 'component' => 'hero', 'props' => ['heading' => 'Hi']],
      ]),
    ]));

    $this->assertTrue($result['ok']);
    $this->assertStringContainsString('page_preview', $result['result']);
    $envelope = json_decode($result['result'], TRUE);
    $this->assertSame('page_preview', $envelope['__widget__']);
    $this->assertCount(2, $envelope['payload']['ops']);
  }

  /**
   * @covers ::process
   *
   * A schema-respecting provider (Mistral, Gemini, …) sends `ops` as a NATIVE
   * JSON array, not a stringified one. The node must run it just the same — this
   * is the regression that surfaced as "Parameter 'ops' expects type 'string',
   * got 'array'" once the reasoning role moved off Claude.
   */
  public function testProcessAcceptsNativeArrayOps(): void {
    $result = $this->tool('aincient_pages:preview_page')->process(new ParameterBag([
      'ops' => [
        ['op' => 'set_meta', 'title' => 'Lumen'],
        ['op' => 'add_section', 'component' => 'hero', 'props' => ['heading' => 'Hi']],
      ],
    ]));

    $this->assertTrue($result['ok']);
    $envelope = json_decode($result['result'], TRUE);
    $this->assertSame('page_preview', $envelope['__widget__']);
    $this->assertCount(2, $envelope['payload']['ops']);
  }

  /**
   * @covers ::process
   * @covers ::normalizeArg
   *
   * The model habitually HTML-escapes text destined for a page; the node strips
   * those entities once, at this single boundary, so capabilities receive clean
   * plain text (not a double-encoded `&amp;`). JSON args are decoded on their
   * LEAF values only — an entity inside a value (`&quot;`) can't corrupt the
   * envelope — while bare, non-entity ampersands (a URL query) pass through.
   */
  public function testModelEntitiesAreDecodedInArgs(): void {
    $result = $this->tool('aincient_pages:preview_chrome')->process(new ParameterBag([
      'identity_json' => json_encode([
        'name' => 'Ember &amp; Oak',
        'tagline' => 'O&#39;Brien&#39;s &lt;pick&gt;',
        'description' => 'Ben &quot;Boss&quot; &amp; Co — see /a?x=1&y=2',
      ]),
    ]));

    $this->assertTrue($result['ok']);
    $g = json_decode($result['result'], TRUE)['payload']['identity']['guidelines'];
    $this->assertSame('Ember & Oak', $g['name']);
    $this->assertSame("O'Brien's <pick>", $g['tagline']);
    // `&quot;` decoded inside the value without breaking the JSON envelope; the
    // non-entity `&y=2` survives untouched.
    $this->assertSame('Ben "Boss" & Co — see /a?x=1&y=2', $g['description']);
  }

  /**
   * @covers ::process
   *
   * An unbound node fails gracefully rather than throwing.
   */
  public function testUnboundCapabilityFailsGracefully(): void {
    $node = new CapabilityTool([], 'aincient_capability:x', [], $this->manager);
    $result = $node->process(new ParameterBag([]));
    $this->assertFalse($result['ok']);
  }

}
