<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_chat\Unit;

use Drupal\aincient_chat\Chat\FlowDropDispatcher;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the two reads that decide whether a turn's WORK reaches the console:
 * the widget harvest and the status-echo guard.
 *
 * Both regressed together when FlowDrop began persisting only a node's EXPOSED
 * outputs, and both failed SILENTLY — the tools ran, the pipeline was green, and
 * the user saw a bare "success" with an unchanged page. These are unit tests on
 * the decoding itself, which is where the assumption about output shape lives.
 *
 * @coversDefaultClass \Drupal\aincient_chat\Chat\FlowDropDispatcher
 * @group aincient
 */
final class DispatcherWidgetHarvestTest extends UnitTestCase {

  /**
   * Call a private method under test.
   *
   * Constructed WITHOUT the constructor: both methods are pure decoders that
   * touch neither injected service, and the catalog is final (undoubleable).
   */
  private function call(string $method, mixed ...$args): mixed {
    $dispatcher = (new \ReflectionClass(FlowDropDispatcher::class))->newInstanceWithoutConstructor();
    return (new \ReflectionMethod(FlowDropDispatcher::class, $method))->invoke($dispatcher, ...$args);
  }

  /**
   * One page_preview envelope, as ToolInvoke emits it under either key.
   */
  private function invokeOutput(string $key): string {
    $envelope = json_encode([
      '__widget__' => 'page_preview',
      'payload' => [
        'ops' => [['op' => 'update_section', 'id' => 'de91c56a', 'props' => ['secondary_url' => 'entity:node:10']]],
        'rejected' => [],
        'warnings' => [],
      ],
      'summary' => 'Previewing 1 page edit.',
    ]);
    return (string) json_encode([
      $key => [['role' => 'tool', 'name' => 'preview_page', 'tool_call_id' => 'toolu_1', 'content' => $envelope]],
      'status' => 'success',
    ]);
  }

  /**
   * The regression: a persisted Invoke job that carries ONLY `tool_messages`
   * (because the instance marks the unwired `tool_results` port unexposed) must
   * still yield its widget. Reading one key meant the studio preview silently
   * stopped applying while every tool kept reporting success.
   *
   * @covers ::widgetEnvelopesFromInvokeOutput
   */
  public function testHarvestsWidgetsFromEitherOutputKey(): void {
    foreach (['tool_results', 'tool_messages'] as $key) {
      $envelopes = $this->call('widgetEnvelopesFromInvokeOutput', $this->invokeOutput($key));
      $this->assertCount(1, $envelopes, sprintf('No widget harvested from "%s".', $key));
      $this->assertSame('page_preview', $envelopes[0]['widget']);
      $this->assertSame('entity:node:10', $envelopes[0]['payload']['ops'][0]['props']['secondary_url']);
    }
  }

  /**
   * Output with no tool rows at all, or nothing envelope-shaped, yields nothing
   * rather than throwing — most jobs in a turn are not Invoke nodes.
   *
   * @covers ::widgetEnvelopesFromInvokeOutput
   */
  public function testNonEnvelopeOutputYieldsNothing(): void {
    $this->assertSame([], $this->call('widgetEnvelopesFromInvokeOutput', '{"status":"success"}'));
    $this->assertSame([], $this->call('widgetEnvelopesFromInvokeOutput', 'not json'));
    $this->assertSame([], $this->call('widgetEnvelopesFromInvokeOutput', '{"tool_messages":[{"content":"plain text"}]}'));
  }

  /**
   * A bare node status is recognised as an echo (so the real reply is recovered
   * from the chat_output input), and a real sentence never is — including one
   * that merely CONTAINS a status word.
   *
   * @covers ::isNodeStatusEcho
   */
  public function testStatusEchoDetectionIsNarrow(): void {
    foreach (['success', 'SUCCESS', ' success ', 'completed', 'failed', 'error'] as $echo) {
      $this->assertTrue($this->call('isNodeStatusEcho', $echo), sprintf('"%s" should read as a status echo.', $echo));
    }
    foreach ([
      'Done — "Common myths" now links to the Salmon page.',
      'success!',
      'The update was a success',
      '',
    ] as $reply) {
      $this->assertFalse($this->call('isNodeStatusEcho', $reply), sprintf('"%s" is a reply, not a status.', $reply));
    }
  }

}
