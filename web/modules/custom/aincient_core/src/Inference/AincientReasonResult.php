<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Inference;

/**
 * The outcome of one reasoning step, with the account the wire can carry.
 *
 * FlowDrop core's {@see \Drupal\flowdrop\DTO\Reason\ReasonResult} is a fixed
 * four-field DTO — text, tool calls, `hasToolCalls`, and the derived assistant
 * message — and it is `@api`, so its shape cannot grow. That shape is enough for
 * the engine's own reason node, but not for the seam Atelier needs: a BYOK
 * gateway lies about which model answered, so the tool-call codec has to be
 * chosen by fingerprinting the ACTUAL response (`codec`), which means the raw
 * provider body has to survive the reasoner→node boundary (`rawResult`); and a
 * provider failure has to reach a downstream branch as a struct, not a
 * flattened sentence (`errorDetail`). None of those three has a slot on
 * `ReasonResult`.
 *
 * So Atelier's own reason node ({@see
 * \Drupal\aincient_flows\Plugin\FlowDropNodeProcessor\AincientReason}) returns
 * THIS instead. It carries everything `ReasonResult` does — identically, so an
 * agent wired to the old four ports is unaffected — plus the three fields that
 * let Phases 3–4 (structured error, trust-the-wire codec) live entirely in our
 * own code with no upstream change (ADR 0365).
 *
 * @see \Drupal\aincient_core\Inference\AincientReasonerInterface
 */
final class AincientReasonResult {

  /**
   * Constructs an AincientReasonResult.
   *
   * @param string $text
   *   The assistant prose — a final answer, or commentary alongside tool calls.
   * @param array<int, array<string, mixed>> $toolCalls
   *   The calls the model requested, each {name, args, tool_call_id}. Empty
   *   when the model answered directly.
   * @param array<string, mixed> $rawResult
   *   The un-parsed provider body as the bridge decoded it (an associative
   *   array), or `[]` when it could not be captured. This is what Phase 4
   *   fingerprints to detect the real dialect a lying gateway used; capturing
   *   it must never be able to fail a turn, hence the empty-array fallback.
   * @param string $codec
   *   The tool-call dialect actually detected on the wire (Phase 4), or `''`
   *   when detection has not run (the first turn, or the config-trusted parse
   *   already produced tool calls). Empirically detected, never copied from the
   *   node's configured model/provider id.
   * @param array<string, mixed>|null $errorDetail
   *   The structured provider failure (Phase 3) —
   *   `{kind, provider, model, message, retryable}` — or NULL on a successful
   *   turn. Distinct from the reserved `error` port: this is the contract a
   *   downstream branch reads, not the engine's fixed error envelope.
   */
  public function __construct(
    private readonly string $text,
    private readonly array $toolCalls = [],
    private readonly array $rawResult = [],
    private readonly string $codec = '',
    private readonly ?array $errorDetail = NULL,
  ) {}

  /**
   * The assistant prose.
   *
   * @return string
   *   A final answer, or commentary alongside tool calls.
   */
  public function getText(): string {
    return $this->text;
  }

  /**
   * The tool calls the model requested.
   *
   * @return array<int, array<string, mixed>>
   *   Each call is {name, args, tool_call_id}; empty when answered directly.
   */
  public function getToolCalls(): array {
    return $this->toolCalls;
  }

  /**
   * Whether the model wants to call at least one tool.
   *
   * @return bool
   *   TRUE if there are tool calls to dispatch — branch the loop on this.
   */
  public function hasToolCalls(): bool {
    return $this->toolCalls !== [];
  }

  /**
   * The assistant turn as a conversation-buffer message.
   *
   * Same shape as {@see \Drupal\flowdrop\DTO\Reason\ReasonResult}'s, so an
   * append node persists it identically whichever result type produced it.
   *
   * @return array<string, mixed>
   *   The {role, content, tool_calls} assistant message.
   */
  public function getAssistantMessage(): array {
    return [
      'role' => 'assistant',
      'content' => $this->text,
      'tool_calls' => $this->toolCalls,
    ];
  }

  /**
   * The un-parsed provider body.
   *
   * @return array<string, mixed>
   *   The decoded wire body, or `[]` when it could not be captured.
   */
  public function getRawResult(): array {
    return $this->rawResult;
  }

  /**
   * The detected tool-call dialect.
   *
   * @return string
   *   The codec identifier fingerprinted from the wire, or `''` when unset.
   */
  public function getCodec(): string {
    return $this->codec;
  }

  /**
   * The structured provider failure, when this step failed.
   *
   * @return array<string, mixed>|null
   *   `{kind, provider, model, message, retryable}`, or NULL on success.
   */
  public function getErrorDetail(): ?array {
    return $this->errorDetail;
  }

}
