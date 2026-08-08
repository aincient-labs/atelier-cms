<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Inference;

/**
 * Trust-the-wire tool-call recovery: read the dialect the response ACTUALLY is.
 *
 * WHY THIS EXISTS. The bridge that parses a response is chosen by the CONFIGURED
 * provider id, and a BYOK gateway lies about that — an operator points a
 * `sonnet`-labelled role at an OpenAI-compatible gateway that silently falls
 * back to gemini/ollama, or to a model that answers in a shape the config bridge
 * does not read. The commonest form needs no cross-provider fallback at all: the
 * OpenAI-compatible bridge only reads `message.tool_calls` when
 * `finish_reason === 'tool_calls'` (see
 * `Bridge/Generic/Completions/CompletionsConversionTrait::convertChoice()`), so
 * a gateway that returns a tool call under `finish_reason: stop` has the whole
 * call DROPPED — no exception, an empty-looking answer, the DECISIONS 0278/0279
 * silence with a fourth cause. The config-trusted parse ({@see ResultUnpacker})
 * stays the source of truth; this only runs when that parse found NO tool call
 * yet the raw wire body still carries one, and re-reads it from the shape it
 * really is.
 *
 * WHAT IT IS NOT. It is not an encoder — Atelier always SENDS through the
 * config-selected bridge, so there is no per-dialect request encoding to route;
 * the lie only costs us on the way back, which is the only side this repairs. It
 * adds no provider call: it reads a body {@see SymfonyAiReasoner} already holds.
 * Argument normalisation (JSON-string vs native, LLM HTML-entity decode) is NOT
 * duplicated here — a recovered call flows through the same
 * {@see \Drupal\aincient_flows\Plugin\FlowDropNodeProcessor\ToolInvoke} act-out
 * boundary as any other, so it inherits `coerceArgs`/`normalizeArg` there.
 *
 * The output shape is FlowDrop's stored `{name, args, tool_call_id}` — identical
 * to {@see MessageMapper::fromToolCalls()} — so a recovered call is
 * indistinguishable downstream from one the bridge parsed cleanly.
 */
final class ToolCallCodec {

  /**
   * OpenAI / chat-completions dialect (OpenAI, Mistral, DeepSeek, openai-compat,
   * Ollama-via-openai): `choices[].message.tool_calls[]`, arguments a JSON string.
   */
  public const OPENAI = 'openai';

  /**
   * Anthropic messages dialect: `content[]` blocks with `type: tool_use`.
   */
  public const ANTHROPIC = 'anthropic';

  /**
   * Gemini dialect: `candidates[].content.parts[].functionCall`.
   */
  public const GEMINI = 'gemini';

  /**
   * The dialect whose tool calls this raw body carries, or '' if none.
   *
   * Empirically detected from the SHAPE of the wire body, never copied from the
   * configured model/provider id — that id is exactly the thing a lying gateway
   * misreports. Returns '' when no dialect's tool-call markers are present (a
   * genuine text answer, or a shape we do not recognise), so the caller leaves
   * the config-trusted parse untouched rather than inventing a call.
   *
   * @param array<string, mixed> $raw
   *   The un-parsed provider response body ({@see RawHttpResult::getData()}).
   *
   * @return string
   *   One of the dialect constants, or '' when none matches.
   */
  public function detect(array $raw): string {
    if ($this->openAiToolCalls($raw) !== []) {
      return self::OPENAI;
    }
    if ($this->anthropicToolUse($raw) !== []) {
      return self::ANTHROPIC;
    }
    if ($this->geminiFunctionCalls($raw) !== []) {
      return self::GEMINI;
    }
    return '';
  }

  /**
   * Decodes the tool calls in a raw body, read as the given dialect.
   *
   * @param array<string, mixed> $raw
   *   The un-parsed provider response body.
   * @param string $codec
   *   The dialect to read it as — normally the return of {@see self::detect()}.
   *
   * @return array<int, array<string, mixed>>
   *   FlowDrop's stored tool-call shape `{name, args, tool_call_id}`; empty when
   *   nothing decodable was found, or the args were malformed past reading.
   */
  public function decode(array $raw, string $codec): array {
    return match ($codec) {
      self::OPENAI => $this->decodeOpenAi($raw),
      self::ANTHROPIC => $this->decodeAnthropic($raw),
      self::GEMINI => $this->decodeGemini($raw),
      default => [],
    };
  }

  /**
   * The raw `tool_calls` entries across every choice, or [] if none.
   *
   * @param array<string, mixed> $raw
   *   The wire body.
   *
   * @return array<int, array<string, mixed>>
   *   The raw entries, unparsed.
   */
  private function openAiToolCalls(array $raw): array {
    $choices = $raw['choices'] ?? NULL;
    if (!is_array($choices)) {
      return [];
    }
    $calls = [];
    foreach ($choices as $choice) {
      $entries = $choice['message']['tool_calls'] ?? NULL;
      if (is_array($entries)) {
        foreach ($entries as $entry) {
          if (is_array($entry)) {
            $calls[] = $entry;
          }
        }
      }
    }
    return $calls;
  }

  /**
   * The raw `tool_use` content blocks, or [] if none.
   *
   * @param array<string, mixed> $raw
   *   The wire body.
   *
   * @return array<int, array<string, mixed>>
   *   The raw blocks, unparsed.
   */
  private function anthropicToolUse(array $raw): array {
    $content = $raw['content'] ?? NULL;
    if (!is_array($content)) {
      return [];
    }
    $blocks = [];
    foreach ($content as $block) {
      if (is_array($block) && ($block['type'] ?? NULL) === 'tool_use') {
        $blocks[] = $block;
      }
    }
    return $blocks;
  }

  /**
   * The raw `functionCall` parts across every candidate, or [] if none.
   *
   * @param array<string, mixed> $raw
   *   The wire body.
   *
   * @return array<int, array<string, mixed>>
   *   The raw functionCall arrays, unparsed.
   */
  private function geminiFunctionCalls(array $raw): array {
    $candidates = $raw['candidates'] ?? NULL;
    if (!is_array($candidates)) {
      return [];
    }
    $calls = [];
    foreach ($candidates as $candidate) {
      $parts = $candidate['content']['parts'] ?? NULL;
      if (!is_array($parts)) {
        continue;
      }
      foreach ($parts as $part) {
        $fn = is_array($part) ? ($part['functionCall'] ?? NULL) : NULL;
        if (is_array($fn) && isset($fn['name'])) {
          $calls[] = $fn;
        }
      }
    }
    return $calls;
  }

  /**
   * Decodes chat-completions tool calls into FlowDrop's stored shape.
   *
   * Mirrors the bridge's own `convertToolCall`: `function.arguments` is a JSON
   * STRING that must be decoded. A call whose arguments will not parse is
   * SKIPPED rather than dispatched with empty args — a call that needed
   * arguments and lost them is a wrong action, worse than a recovered-nothing;
   * that condition is already surfaced separately as `tool_malformed`. Some
   * gateways send an object rather than a string, so a native array is taken
   * as-is.
   *
   * @param array<string, mixed> $raw
   *   The wire body.
   *
   * @return array<int, array<string, mixed>>
   *   The recovered calls.
   */
  private function decodeOpenAi(array $raw): array {
    $out = [];
    foreach ($this->openAiToolCalls($raw) as $i => $entry) {
      $fn = $entry['function'] ?? NULL;
      if (!is_array($fn) || !isset($fn['name'])) {
        continue;
      }
      $args = $this->decodeArgs($fn['arguments'] ?? NULL);
      if ($args === NULL) {
        continue;
      }
      $out[] = [
        'name' => (string) $fn['name'],
        'args' => $args,
        'tool_call_id' => $this->id($entry['id'] ?? NULL, $raw, $i),
      ];
    }
    return $out;
  }

  /**
   * Decodes Anthropic tool_use blocks into FlowDrop's stored shape.
   *
   * `input` is already a native object on the wire, so no JSON string decode —
   * an absent or non-array input is a zero-argument call, not a malformed one.
   *
   * @param array<string, mixed> $raw
   *   The wire body.
   *
   * @return array<int, array<string, mixed>>
   *   The recovered calls.
   */
  private function decodeAnthropic(array $raw): array {
    $out = [];
    foreach ($this->anthropicToolUse($raw) as $i => $block) {
      if (!isset($block['name'])) {
        continue;
      }
      $out[] = [
        'name' => (string) $block['name'],
        'args' => is_array($block['input'] ?? NULL) ? $block['input'] : [],
        'tool_call_id' => $this->id($block['id'] ?? NULL, $raw, $i),
      ];
    }
    return $out;
  }

  /**
   * Decodes Gemini functionCall parts into FlowDrop's stored shape.
   *
   * Gemini's `functionCall` carries `args` as a native object and only
   * sometimes an `id`, so an id is synthesised when absent — a stored call with
   * an empty id is dropped from any replayed history by
   * {@see MessageMapper::mapToolCalls()}, which would strand the tool result on
   * the next turn.
   *
   * @param array<string, mixed> $raw
   *   The wire body.
   *
   * @return array<int, array<string, mixed>>
   *   The recovered calls.
   */
  private function decodeGemini(array $raw): array {
    $out = [];
    foreach ($this->geminiFunctionCalls($raw) as $i => $fn) {
      $out[] = [
        'name' => (string) $fn['name'],
        'args' => is_array($fn['args'] ?? NULL) ? $fn['args'] : [],
        'tool_call_id' => $this->id($fn['id'] ?? NULL, $raw, $i),
      ];
    }
    return $out;
  }

  /**
   * The call's id, or a stable synthesised one when the wire carried none.
   *
   * Deterministic (a hash of the body and the call's position) rather than
   * random so the same response decodes to the same id — a replay or a second
   * read cannot mint a different id for the same call and break pairing.
   *
   * @param mixed $id
   *   The id as it appeared on the wire, if any.
   * @param array<string, mixed> $raw
   *   The wire body, seeding the synthesised id.
   * @param int $index
   *   The call's position, so several id-less calls in one body stay distinct.
   *
   * @return string
   *   A non-empty tool-call id.
   */
  private function id(mixed $id, array $raw, int $index): string {
    $id = is_string($id) ? trim($id) : '';
    if ($id !== '') {
      return $id;
    }
    return 'call_' . substr(md5(json_encode($raw) . ':' . $index), 0, 16);
  }

  /**
   * Reads a chat-completions arguments field, or NULL when it will not parse.
   *
   * @param mixed $arguments
   *   A JSON string, a native array, or nothing.
   *
   * @return array<string, mixed>|null
   *   The decoded arguments (possibly empty), or NULL when a non-empty string
   *   was not valid JSON — the signal to skip an unreadable call.
   */
  private function decodeArgs(mixed $arguments): ?array {
    if (is_array($arguments)) {
      return $arguments;
    }
    if (!is_string($arguments) || trim($arguments) === '') {
      return [];
    }
    try {
      $decoded = json_decode($arguments, TRUE, flags: JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return NULL;
    }
    return is_array($decoded) ? $decoded : [];
  }

}
