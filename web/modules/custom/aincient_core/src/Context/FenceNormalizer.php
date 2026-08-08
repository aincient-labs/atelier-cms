<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Context;

/**
 * Wraps untrusted attachment text in an inert, clearly-labelled fence.
 *
 * WHAT THIS IS. A CONTAINMENT control, not a detection one (DECISIONS 0347). It
 * makes a block of attacker-influenced text — a vision model's description of an
 * image an operator attached — impossible to confuse with our own instructions
 * by wrapping it in delimiters the payload itself cannot forge, and by
 * neutralising any occurrence of those delimiters (or the label tokens) inside
 * the payload so it cannot close our fence early or open a fake one. The point
 * is that the model receives the description as DATA with a visible boundary.
 *
 * WHAT THIS IS NOT. It does not detect, score, or reject prompt injection, and
 * it must not be relied on to. The real injection boundary is the tool gate:
 * whatever the model is talked into "wanting", it can still only call the
 * capabilities it is granted, with the access checks those capabilities run.
 * Fencing lowers the odds the model is fooled; the gate is what makes being
 * fooled non-catastrophic. Treat this class as defence in depth, never as the
 * defence.
 */
final class FenceNormalizer {

  /**
   * The opening delimiter, minus the label — literal, and forged by nobody.
   *
   * Chosen to be a sequence that does not occur in natural text or code, and
   * that {@see self::neutralize()} strips from any payload before it is fenced.
   */
  private const OPEN_PREFIX = '<<<UNTRUSTED_ATTACHMENT';

  /**
   * The closing delimiter — the exact string that ends our fence.
   */
  private const CLOSE = '<<<END_UNTRUSTED_ATTACHMENT>>>';

  /**
   * Fences untrusted text under a caller-controlled label.
   *
   * @param string $untrusted
   *   The attacker-influenced text (e.g. a vision description). Neutralised so
   *   it cannot contain the fence delimiters or the label tokens.
   * @param string $label
   *   A human hint at what the block is (a filename). ALSO caller-controlled and
   *   ALSO untrusted — a crafted filename must not be able to break the fence —
   *   so it is sanitised the same way, plus newline stripping.
   *
   * @return string
   *   The untrusted text wrapped between exactly one opening and one closing
   *   delimiter that are ours.
   */
  public static function fence(string $untrusted, string $label): string {
    $safeLabel = self::sanitizeLabel($label);
    $body = self::neutralize($untrusted);

    return self::OPEN_PREFIX . ' label="' . $safeLabel . '">>>' . "\n"
      . $body . "\n"
      . self::CLOSE;
  }

  /**
   * Strips the fence delimiters and label tokens from a payload.
   *
   * The delimiters are replaced with visually-similar Unicode guillemets that
   * carry NO ASCII angle bracket, so a replacement can never recombine into a
   * fresh `<<<`/`>>>` run — including the runaway case (`<<<<<<`), which
   * str_replace consumes left-to-right leaving no literal triple behind. Also
   * neutralises whitespace-obfuscated variants (`< < <`) cheaply, and the label
   * word-tokens so a bare `END_UNTRUSTED_ATTACHMENT` can't masquerade as a
   * boundary either. Case-insensitive throughout.
   */
  private static function neutralize(string $text): string {
    // Whitespace-obfuscated angle runs first, so `< < <` collapses before the
    // literal-triple pass would miss it.
    $text = (string) preg_replace('/<(?:\s*<){2,}/u', "\u{2039}\u{2039}\u{2039}", $text);
    $text = (string) preg_replace('/>(?:\s*>){2,}/u', "\u{203A}\u{203A}\u{203A}", $text);

    // Literal triples (and longer runs). str_replace is non-overlapping, and the
    // replacement contains no ASCII angle bracket, so no `<<<` survives.
    $text = str_replace('<<<', "\u{2039}\u{2039}\u{2039}", $text);
    $text = str_replace('>>>', "\u{203A}\u{203A}\u{203A}", $text);

    // The label word-tokens, longest first so END_… is defaced as a whole.
    $text = (string) preg_replace('/END_UNTRUSTED_ATTACHMENT/i', "END_UNTRUSTED\u{200B}_ATTACHMENT", $text);
    $text = (string) preg_replace('/UNTRUSTED_ATTACHMENT/i', "UNTRUSTED\u{200B}_ATTACHMENT", $text);

    return $text;
  }

  /**
   * Sanitises a caller-supplied label for the opening line.
   *
   * A label is untrusted text on a single line inside `label="…"`, so it gets
   * the same delimiter/token neutralisation as the body, plus newline removal
   * (a newline could let the payload jump out of the opening line) and quote
   * stripping (so it cannot end the attribute early). Blank labels degrade to a
   * neutral placeholder rather than an empty attribute.
   */
  private static function sanitizeLabel(string $label): string {
    $label = str_replace(["\r", "\n", '"'], ['', '', ''], $label);
    $label = self::neutralize($label);
    $label = trim($label);
    return $label === '' ? 'attachment' : $label;
  }

}
