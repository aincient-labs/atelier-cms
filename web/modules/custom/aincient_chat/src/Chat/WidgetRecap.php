<?php

declare(strict_types=1);

namespace Drupal\aincient_chat\Chat;

/**
 * One line telling the model what a turn's cards showed the user. 0464.
 *
 * Earlier turns' tool traffic is per-turn (the scratchpad), so the model never
 * sees a past card's contents while the user still has it on screen. "Open the
 * second one" after a pages table then refers to nothing. This line is appended
 * to that turn's assistant entry in the conversation buffer so the reference
 * resolves; ContextPolicy tells the agent to re-call the tool before acting on
 * it, because a card is a snapshot.
 *
 * Names and ids only, never the payload: enough to resolve a reference, small
 * enough to sit in every later request.
 */
final class WidgetRecap {

  /**
   * Every recap starts with this. ContextPolicy names it for the model.
   */
  public const PREFIX = '[shown to the user: ';

  /**
   * Items listed per card before "+N more".
   */
  private const MAX_ITEMS = 20;

  /**
   * Console room keys → the names the user sees.
   */
  private const ROOMS = [
    'content' => 'Pages',
    'media' => 'Library',
    'design_system' => 'Design System',
    'globals' => 'Globals',
  ];

  /**
   * The recap line for a turn's widgets, or '' when there are none.
   *
   * @param array<int, array{widget: string, payload: array, summary?: string}> $widgets
   *   Envelopes as FlowDropDispatcher::harvestTurnWidgets() returns them.
   */
  public static function line(array $widgets): string {
    $parts = [];
    foreach ($widgets as $widget) {
      $part = self::describe((string) ($widget['widget'] ?? ''), (array) ($widget['payload'] ?? []), (string) ($widget['summary'] ?? ''));
      if ($part !== '') {
        $parts[] = $part;
      }
    }
    return $parts === [] ? '' : self::PREFIX . implode(' | ', $parts) . ']';
  }

  /**
   * One card, described.
   */
  private static function describe(string $kind, array $payload, string $summary): string {
    return match ($kind) {
      'data_table' => self::table($payload),
      'studio_tour' => self::tour($payload),
      'brand_preview' => self::brand($payload),
      '' => '',
      default => $summary !== '' ? $kind . ' card: ' . self::flat($summary) : $kind . ' card',
    };
  }

  /**
   * A table: its rows by first-column label, with the row id when present.
   */
  private static function table(array $payload): string {
    $rows = array_values(array_filter((array) ($payload['rows'] ?? []), 'is_array'));
    if ($rows === []) {
      return 'an empty table';
    }
    $columns = (array) ($payload['columns'] ?? []);
    $key = (string) (($columns[0]['key'] ?? NULL) ?: 'title');
    $items = [];
    foreach (array_slice($rows, 0, self::MAX_ITEMS) as $i => $row) {
      $label = self::flat((string) ($row['cells'][$key] ?? '')) ?: '(untitled)';
      $id = $row['id'] ?? NULL;
      $items[] = ($i + 1) . '. ' . $label . (is_scalar($id) && (string) $id !== '' ? ' (id ' . $id . ')' : '');
    }
    return sprintf('a table of %d row%s — %s%s', count($rows), count($rows) === 1 ? '' : 's', implode(' · ', $items), self::more(count($rows)));
  }

  /**
   * The studio map: which rooms, with their status lines.
   */
  private static function tour(array $payload): string {
    $items = [];
    foreach ((array) ($payload['rooms'] ?? []) as $room) {
      if (!is_array($room)) {
        continue;
      }
      $key = (string) ($room['key'] ?? '');
      $name = self::ROOMS[$key] ?? $key;
      $status = self::flat((string) ($room['status'] ?? ''));
      $items[] = $status !== '' ? $name . ' (' . $status . ')' : $name;
    }
    return $items === [] ? 'the studio map' : 'room cards — ' . implode(', ', $items);
  }

  /**
   * A brand preview: which tokens and fonts it staged.
   */
  private static function brand(array $payload): string {
    $tokens = [];
    $fonts = [];
    $reset = FALSE;
    foreach ((array) ($payload['commands'] ?? []) as $command) {
      $args = (array) ($command['args'] ?? []);
      switch ($command['verb'] ?? NULL) {
        case 'reset':
          $reset = TRUE;
          break;

        case 'set_tokens':
          $tokens = array_merge($tokens, array_keys((array) ($args['tokens'] ?? [])));
          break;

        case 'set_fonts':
          $fonts = array_values(array_map('strval', (array) ($args['fonts'] ?? [])));
          break;
      }
    }
    $tokens = array_values(array_unique($tokens));
    $bits = [];
    if ($reset) {
      $bits[] = 'reset to defaults';
    }
    if ($tokens !== []) {
      $bits[] = count($tokens) . ' token' . (count($tokens) === 1 ? '' : 's') . ' (' . implode(', ', array_slice($tokens, 0, self::MAX_ITEMS)) . self::more(count($tokens)) . ')';
    }
    if ($fonts !== []) {
      $bits[] = 'fonts ' . implode(', ', $fonts);
    }
    return 'a brand preview' . ($bits === [] ? '' : ' — ' . implode('; ', $bits));
  }

  /**
   * " · +N more" when a list was cut at MAX_ITEMS.
   */
  private static function more(int $count): string {
    return $count > self::MAX_ITEMS ? ' · +' . ($count - self::MAX_ITEMS) . ' more' : '';
  }

  /**
   * One line, no brackets that would end the recap early.
   */
  private static function flat(string $text): string {
    return trim((string) preg_replace('/\s+/', ' ', strtr($text, ['[' => '(', ']' => ')'])));
  }

}
