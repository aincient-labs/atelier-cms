<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_core\Unit\Inference;

use Drupal\aincient_core\Inference\ToolCallCodec;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the fingerprint-and-decode contract for trust-the-wire recovery.
 *
 * The failure this guards is a BYOK gateway returning a tool call in a shape
 * (or under a finish_reason) the config-selected bridge does not read, so the
 * call is silently dropped and the agent never acts. detect() must recognise the
 * dialect from the wire SHAPE alone — never the configured id, which is the very
 * thing the gateway lies about — and decode() must produce the same
 * `{name, args, tool_call_id}` a cleanly-parsed call would, so a recovered call
 * is indistinguishable downstream.
 */
#[CoversClass(ToolCallCodec::class)]
final class ToolCallCodecTest extends TestCase {

  private ToolCallCodec $codec;

  protected function setUp(): void {
    parent::setUp();
    $this->codec = new ToolCallCodec();
  }

  /**
   * A chat-completions body is detected as OpenAI and its call decoded.
   *
   * `function.arguments` is a JSON STRING on the wire — the one shape that must
   * be json_decoded, mirroring the bridge's own convertToolCall.
   */
  public function testOpenAiToolCallIsDetectedAndDecoded(): void {
    $raw = [
      'choices' => [
        [
          'finish_reason' => 'stop',
          'message' => [
            'tool_calls' => [
              [
                'id' => 'call_1',
                'type' => 'function',
                'function' => ['name' => 'list_pages', 'arguments' => '{"limit": 10}'],
              ],
            ],
          ],
        ],
      ],
    ];

    self::assertSame(ToolCallCodec::OPENAI, $this->codec->detect($raw));
    self::assertSame(
      [['name' => 'list_pages', 'args' => ['limit' => 10], 'tool_call_id' => 'call_1']],
      $this->codec->decode($raw, ToolCallCodec::OPENAI),
    );
  }

  /**
   * An Anthropic messages body is detected and its tool_use block decoded.
   *
   * `input` is a native object on the wire — no string decode, and an absent
   * input is a zero-argument call, not a malformed one.
   */
  public function testAnthropicToolUseIsDetectedAndDecoded(): void {
    $raw = [
      'stop_reason' => 'tool_use',
      'content' => [
        ['type' => 'text', 'text' => 'Looking now.'],
        ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'preview_page', 'input' => ['id' => 3]],
      ],
    ];

    self::assertSame(ToolCallCodec::ANTHROPIC, $this->codec->detect($raw));
    self::assertSame(
      [['name' => 'preview_page', 'args' => ['id' => 3], 'tool_call_id' => 'toolu_1']],
      $this->codec->decode($raw, ToolCallCodec::ANTHROPIC),
    );
  }

  /**
   * A Gemini body is detected and its functionCall decoded, id synthesised.
   *
   * Gemini's functionCall carries `args` natively and often no id; an empty id
   * would be dropped from any replayed history by MessageMapper, so one is
   * synthesised — and deterministically, so a second read pairs identically.
   */
  public function testGeminiFunctionCallIsDetectedAndDecodedWithSynthesisedId(): void {
    $raw = [
      'candidates' => [
        [
          'content' => ['parts' => [['functionCall' => ['name' => 'preview_page', 'args' => ['id' => 7]]]]],
          'finishReason' => 'STOP',
        ],
      ],
    ];

    self::assertSame(ToolCallCodec::GEMINI, $this->codec->detect($raw));

    $decoded = $this->codec->decode($raw, ToolCallCodec::GEMINI);
    self::assertCount(1, $decoded);
    self::assertSame('preview_page', $decoded[0]['name']);
    self::assertSame(['id' => 7], $decoded[0]['args']);
    self::assertNotSame('', $decoded[0]['tool_call_id']);
    self::assertSame(
      $decoded[0]['tool_call_id'],
      $this->codec->decode($raw, ToolCallCodec::GEMINI)[0]['tool_call_id'],
      'A synthesised id must be deterministic across reads.',
    );
  }

  /**
   * A plain text answer carries no tool-call markers and detects as none.
   *
   * The false-positive guard: '' is the signal to leave the config-trusted
   * parse untouched, so a genuine text answer must never look like a dropped
   * call.
   */
  public function testPlainTextAnswerDetectsAsNone(): void {
    self::assertSame('', $this->codec->detect([
      'choices' => [['finish_reason' => 'stop', 'message' => ['content' => 'All done.']]],
    ]));
    self::assertSame('', $this->codec->detect([]));
  }

  /**
   * An empty `tool_calls` array is absence, not a call.
   *
   * Some gateways emit `tool_calls: []` on a text turn; treating that as a
   * dialect match would fire recovery on every plain answer.
   */
  public function testEmptyToolCallsIsTreatedAsNone(): void {
    self::assertSame('', $this->codec->detect([
      'choices' => [['finish_reason' => 'stop', 'message' => ['tool_calls' => []]]],
    ]));
  }

  /**
   * A call whose JSON arguments will not parse is skipped, not half-dispatched.
   *
   * Dispatching a call that needed arguments with empty ones is a wrong action;
   * that condition surfaces separately as `tool_malformed`. Recovery drops the
   * unreadable call rather than acting on a guess.
   */
  public function testToolCallWithMalformedJsonArgumentsIsSkipped(): void {
    $raw = [
      'choices' => [
        [
          'finish_reason' => 'stop',
          'message' => [
            'tool_calls' => [
              ['id' => 'bad', 'type' => 'function', 'function' => ['name' => 'x', 'arguments' => '{not json']],
              ['id' => 'ok', 'type' => 'function', 'function' => ['name' => 'y', 'arguments' => '{"a": 1}']],
            ],
          ],
        ],
      ],
    ];

    $decoded = $this->codec->decode($raw, ToolCallCodec::OPENAI);
    self::assertCount(1, $decoded, 'The malformed call is dropped; the readable one survives.');
    self::assertSame('y', $decoded[0]['name']);
  }

  /**
   * Arguments already sent as a native object are taken as-is.
   *
   * The chat-completions spec says arguments is a string, but some gateways send
   * an object; reading it as-is avoids a needless json_decode of a non-string.
   */
  public function testOpenAiArgumentsAsNativeObjectAreTakenAsIs(): void {
    $raw = [
      'choices' => [
        [
          'finish_reason' => 'stop',
          'message' => [
            'tool_calls' => [
              ['id' => 'c', 'type' => 'function', 'function' => ['name' => 'z', 'arguments' => ['k' => 'v']]],
            ],
          ],
        ],
      ],
    ];

    self::assertSame(['k' => 'v'], $this->codec->decode($raw, ToolCallCodec::OPENAI)[0]['args']);
  }

  /**
   * Parallel tool calls across choices are all recovered.
   *
   * A dropped call is rarely alone; taking only the first would strand the rest.
   */
  public function testParallelOpenAiToolCallsAreAllDecoded(): void {
    $raw = [
      'choices' => [
        [
          'finish_reason' => 'stop',
          'message' => [
            'tool_calls' => [
              ['id' => 'a', 'type' => 'function', 'function' => ['name' => 'one', 'arguments' => '{}']],
              ['id' => 'b', 'type' => 'function', 'function' => ['name' => 'two', 'arguments' => '{}']],
            ],
          ],
        ],
      ],
    ];

    $decoded = $this->codec->decode($raw, ToolCallCodec::OPENAI);
    self::assertCount(2, $decoded);
    self::assertSame(['one', 'two'], array_column($decoded, 'name'));
  }

  /**
   * Decode() with an unrecognised codec yields nothing.
   *
   * The caller passes detect()'s result, but a defensive '' or a future id must
   * not error — it decodes to an empty list.
   */
  public function testDecodeWithUnknownCodecYieldsNothing(): void {
    self::assertSame([], $this->codec->decode(['choices' => []], 'martian'));
    self::assertSame([], $this->codec->decode([], ''));
  }

}
