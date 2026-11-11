<?php

declare(strict_types=1);

namespace Aincient\Inference;

/**
 * The card-recap note: written by the console, read as context, never spoken.
 *
 * DECISIONS 0464 stores one `[shown to the user: …]` line per turn on that
 * turn's assistant entry in the conversation buffer (aincient_chat's
 * WidgetRecap / TurnRecapRecorder). Replayed there, verbatim, it reads to the
 * model as ITS OWN words — so it learned to write the line itself (leaking it
 * into the visible reply) and, seeing earlier turns where an image "appeared"
 * with no tool call, to answer a fresh request with "Done" instead of calling
 * the tool (B2, image agent).
 *
 * So the line has two rules, both enforced here at the inference seam:
 * - On replay, {@see \Drupal\aincient_core\Inference\MessageMapper} and
 *   {@see ChatMessageMapper} lifts it off the assistant turn and puts it
 *   at the top of the next user turn, as a note — context, not the model's
 *   own speech.
 * - On the way out, {@see \Drupal\aincient_core\Inference\SymfonyAiReasoner} strips any such line the model
 *   writes anyway, so it never reaches the visible thread or the buffer.
 */
final class RecapNote {

  /**
   * Every recap starts with this. WidgetRecap writes it; ContextPolicy names it.
   */
  public const PREFIX = '[shown to the user: ';

  /**
   * Splits recap lines out of a text.
   *
   * @param string $text
   *   An assistant turn's text.
   *
   * @return array{0: string, 1: list<string>}
   *   The text without recap lines (blank-line runs collapsed, trimmed), and
   *   the recap lines in order.
   */
  public static function split(string $text): array {
    if (stripos($text, rtrim(self::PREFIX)) === FALSE) {
      return [$text, []];
    }
    $kept = [];
    $notes = [];
    foreach (preg_split('/\R/u', $text) ?: [] as $line) {
      if (self::isNote($line)) {
        $notes[] = trim($line);
        continue;
      }
      $kept[] = $line;
    }
    $clean = trim((string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $kept)));
    return [$clean, $notes];
  }

  /**
   * The text with every recap line removed.
   */
  public static function strip(string $text): string {
    return self::split($text)[0];
  }

  /**
   * A user turn with the preceding reply's recaps on top.
   *
   * The notes lead because they describe what the user was looking at when
   * they wrote the message. Should an assistant turn's text be ONLY a recap,
   * the assistant turn drops and two user turns meet — a shape the buffer
   * already has after any failed turn (stop-and-report appends no assistant
   * entry), which every provider accepts.
   *
   * @param list<string> $notes
   *   Recap lines, in order.
   * @param string $content
   *   The user's own text.
   */
  public static function prepend(array $notes, string $content): string {
    $head = implode("\n", $notes);
    return $content === '' ? $head : $head . "\n\n" . $content;
  }

  /**
   * Whether a line is a recap note (case-insensitive, leading space allowed).
   */
  private static function isNote(string $line): bool {
    return stripos(ltrim($line), rtrim(self::PREFIX)) === 0;
  }

}
