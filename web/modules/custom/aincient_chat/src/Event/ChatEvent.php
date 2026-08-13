<?php

declare(strict_types=1);

namespace Drupal\aincient_chat\Event;

/**
 * One event in a chat turn's stream. Serializes to an SSE frame.
 */
final class ChatEvent {

  public function __construct(
    public readonly ChatEventType $type,
    public readonly array $data = [],
  ) {}

  public static function status(string $message, array $extra = []): self {
    return new self(ChatEventType::STATUS, ['message' => $message] + $extra);
  }

  /**
   * A status frame that only makes sense to someone debugging the engine.
   *
   * Same wire type as {@see status()} plus `debug: TRUE`, which the console
   * honours by NOT showing it: our routing decisions, node bookkeeping and the
   * like are machinery, and in-product copy names outcomes (brand.md §7). It
   * stays on the wire — and reaches the thinking indicator — when the console
   * runs with `aincient_chat.settings:features.technical_detail` on.
   */
  public static function debugStatus(string $message, array $extra = []): self {
    return self::status($message, ['debug' => TRUE] + $extra);
  }

  public static function token(string $text): self {
    return new self(ChatEventType::TOKEN, ['text' => $text]);
  }

  public static function toolCall(string $name, array $arguments = []): self {
    return new self(ChatEventType::TOOL_CALL, ['name' => $name, 'arguments' => $arguments]);
  }

  /**
   * A transient live-preview repaint (no card, nothing persisted).
   *
   * Deliberately mirrors {@see self::toolCall()}'s payload so a console can
   * reuse the same widget payload decoding — what differs is the CONTRACT, not
   * the shape: this one is applied to the studio draft and then forgotten.
   */
  public static function preview(string $name, array $arguments = []): self {
    return new self(ChatEventType::PREVIEW, ['name' => $name, 'arguments' => $arguments]);
  }

  public static function toolResult(string $name, string $output): self {
    return new self(ChatEventType::TOOL_RESULT, ['name' => $name, 'output' => $output]);
  }

  /**
   * One workflow node finished executing (live progress, not chat content).
   *
   * @param string $nodeId
   *   The workflow node id (e.g. "agent_reason").
   * @param string $label
   *   The node's design-time label, for display.
   * @param string $status
   *   The job status after execution (completed/failed/interrupted/…).
   * @param array $extra
   *   Extra context (e.g. node_type_id, elapsed_ms, error).
   */
  public static function node(string $nodeId, string $label, string $status, array $extra = []): self {
    return new self(ChatEventType::NODE, [
      'node_id' => $nodeId,
      'label' => $label,
      'status' => $status,
    ] + $extra);
  }

  /**
   * A human-in-the-loop pause awaiting user input.
   *
   * @param string $uuid
   *   The FlowDrop interrupt id the console posts back to resolve.
   * @param string $prompt
   *   The question to show the user.
   * @param array $schema
   *   The interrupt's JSON-Schema (e.g. {type:'string', enum, enumLabels} for
   *   single-select; {type:'array', items:{enum,…}, multiple:true} for multi).
   * @param array $extra
   *   Extra context (e.g. session_id).
   */
  public static function interrupt(string $uuid, string $prompt, array $schema, array $extra = []): self {
    return new self(ChatEventType::INTERRUPT, [
      'uuid' => $uuid,
      'prompt' => $prompt,
      'schema' => $schema,
    ] + $extra);
  }

  public static function result(string $text): self {
    return new self(ChatEventType::RESULT, ['text' => $text]);
  }

  /**
   * Token usage + estimated cost for one metered AI call within the turn.
   *
   * Relayed from the row Atelier's usage log just wrote (input/output/cached
   * tokens and the USD cost recorded with them). A turn can emit several of
   * these (operator step + any sub-agent calls); the console sums them per turn
   * and per session. Cost is NULL when `aincient_core.pricing` has no rate for
   * the model — the console then shows tokens only.
   *
   * @param int $input
   *   Input (prompt) tokens.
   * @param int $output
   *   Output (completion) tokens.
   * @param int $cached
   *   Cached input tokens (prompt-cache hit).
   * @param float|null $costUsd
   *   Estimated cost in USD, or NULL when no pricing is configured.
   * @param string $model
   *   The model id that served the call.
   * @param string $provider
   *   The provider id (e.g. "anthropic").
   */
  public static function usage(int $input, int $output, int $cached, ?float $costUsd, string $model, string $provider): self {
    return new self(ChatEventType::USAGE, [
      'input' => $input,
      'output' => $output,
      'cached' => $cached,
      'cost_usd' => $costUsd,
      'model' => $model,
      'provider' => $provider,
    ]);
  }

  /**
   * The studio-given thread name (minted once, after the first exchange).
   */
  public static function threadTitle(string $threadId, string $title): self {
    return new self(ChatEventType::THREAD_TITLE, ['thread_id' => $threadId, 'title' => $title]);
  }

  public static function error(string $message): self {
    return new self(ChatEventType::ERROR, ['message' => $message]);
  }

  public static function done(array $extra = []): self {
    return new self(ChatEventType::DONE, $extra);
  }

  /**
   * Render as a Server-Sent Events frame.
   */
  public function toSseFrame(): string {
    return 'event: ' . $this->type->value . "\n"
      . 'data: ' . json_encode($this->data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
  }

}
