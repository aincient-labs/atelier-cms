<?php

declare(strict_types=1);

namespace Drupal\aincient_chat\Chat;

/**
 * The generative-UI widget envelope: shared decode + summary helpers.
 *
 * A workflow opts a turn into a rich chat widget by writing its reply as a JSON
 * envelope — `{"__widget__": "<tool>", "payload": {…}, "summary": "<text>"}`
 * (bare or wrapped in a ```code fence```). The dispatcher unwraps it into a
 * `tool_call` SSE frame (the payload rides in `arguments`) plus a plain-text
 * RESULT (the summary) for the live stream; {@see SessionThreadStore} decodes
 * the same envelope when re-hydrating a thread on reload. Both surfaces share
 * this one implementation so a widget renders identically live and after a
 * page refresh.
 */
final class WidgetEnvelope {

  /**
   * Decode a widget envelope from an assistant write-back, or NULL.
   *
   * Accepts a bare JSON object or one wrapped in a ```code fence``` (a shaper
   * may emit either). A valid envelope has a non-empty string `__widget__` and
   * an object `payload`; `summary` is optional (synthesized when absent).
   *
   * @return array{widget: string, payload: array, summary: string}|null
   *   The decoded envelope, or NULL when $text is not a widget envelope.
   */
  public static function decode(string $text): ?array {
    $text = trim($text);
    // Strip a leading ```lang fence and trailing ``` if present.
    if (str_starts_with($text, '```')) {
      $text = (string) preg_replace('/^```[a-zA-Z0-9_-]*\s*|\s*```$/', '', $text);
      $text = trim($text);
    }
    if ($text === '' || $text[0] !== '{') {
      return NULL;
    }
    $data = json_decode($text, TRUE);
    if (!is_array($data)) {
      return NULL;
    }
    $widget = $data['__widget__'] ?? NULL;
    $payload = $data['payload'] ?? NULL;
    if (!is_string($widget) || $widget === '' || !is_array($payload)) {
      return NULL;
    }
    $summary = $data['summary'] ?? NULL;
    return [
      'widget' => $widget,
      'payload' => $payload,
      'summary' => (is_string($summary) && $summary !== '') ? $summary : self::synthesizeSummary($payload),
    ];
  }

  /**
   * A short readable summary for a widget payload (the persisted turn text).
   *
   * Best-effort for a weather payload; falls back to a generic line so any
   * future widget without its own `summary` still leaves a sensible turn.
   */
  public static function synthesizeSummary(array $payload): string {
    $name = is_array($payload['location'] ?? NULL) ? trim((string) ($payload['location']['name'] ?? '')) : '';
    $current = is_array($payload['current'] ?? NULL) ? $payload['current'] : [];
    $temp = $current['temperature'] ?? NULL;
    if ($name !== '' && is_numeric($temp)) {
      $unit = (($payload['units']['temperature'] ?? 'celsius') === 'fahrenheit') ? '°F' : '°C';
      $cond = trim((string) ($current['conditionCode'] ?? ''));
      $cond = $cond !== '' ? ', ' . str_replace('-', ' ', $cond) : '';
      return sprintf('Weather for %s: %s%s%s.', $name, (string) round((float) $temp), $unit, $cond);
    }
    return $name !== '' ? sprintf("Here's the weather for %s.", $name) : "Here's the weather.";
  }

}
