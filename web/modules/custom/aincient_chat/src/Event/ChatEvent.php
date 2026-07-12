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

  public static function token(string $text): self {
    return new self(ChatEventType::TOKEN, ['text' => $text]);
  }

  public static function toolCall(string $name, array $arguments = []): self {
    return new self(ChatEventType::TOOL_CALL, ['name' => $name, 'arguments' => $arguments]);
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
   * Relayed from ai_metering's per-call record (input/output/cached tokens and
   * its computed USD cost). A turn can emit several of these (operator step +
   * any sub-agent calls); the console sums them per turn and per session. Cost
   * is NULL when ai_metering has no pricing for the model — the console then
   * shows tokens only.
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
