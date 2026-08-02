<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Usage;

use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Turns the raw call-site tags in `context_id` into something readable.
 *
 * THE TAG IS THE HEADLINE, so it has to survive being shown. Every row Atelier
 * records carries a call-site tag ({@see UsageRecorder}) — which piece of the
 * product spent the money — and that is the axis no contrib metering UI has: its
 * dashboard groups by editor, which answers "who was at the keyboard" and never
 * "what is running up the bill". A single operator using one console produces one
 * row on that page and no information at all.
 *
 * NOTHING IS EVER HIDDEN FOR LACKING A LABEL. An unrecognised tag is shown
 * verbatim and marked as unrecognised; rows with no tag at all are collected
 * under one honest heading. A dashboard that silently dropped the rows it could
 * not caption would understate spend in exactly the way this whole surface exists
 * to stop, and the tags most worth seeing are the ones nobody predicted — a new
 * call site ships with a new tag, and the first thing you want from it is to see
 * it on this page before anyone thought to name it.
 *
 * Free of render concerns and of the database, so the labelling and the
 * proportions can be asserted without a page or a schema.
 */
final class CallSites {

  use StringTranslationTrait;

  /**
   * The tags this release writes, in the order they are worth reading.
   *
   * The keys are the LITERAL strings in the column, not an enum: rows already in
   * the table were written by earlier releases and by call sites that have since
   * moved, and a tag must keep resolving after the code that emitted it is gone.
   * Two of them are named through {@see UsageRecorder}'s constants because that
   * class owns them; the other two are passed in as literals by their callers and
   * are literals here for the same reason.
   */
  private const KNOWN = [
    UsageRecorder::TAG_AGENT_TURN,
    'aincient_chat_thread_namer',
    UsageRecorder::TAG_SIMPLE_CHAT,
    'aincient_media_studio',
  ];

  /**
   * A human name for a call-site tag.
   *
   * @param string|null $tag
   *   The `context_id` value, NULL or '' for a row that carries none.
   *
   * @return string
   *   Plain text, never markup — the CSV and JSON exports use this too.
   */
  public function label(?string $tag): string {
    $tag = (string) ($tag ?? '');
    return match ($tag) {
      UsageRecorder::TAG_AGENT_TURN => (string) $this->t('Agent turn'),
      'aincient_chat_thread_namer' => (string) $this->t('Thread naming'),
      UsageRecorder::TAG_SIMPLE_CHAT => (string) $this->t('Brand and flow specialists'),
      'aincient_media_studio' => (string) $this->t('Media studio'),
      // Not "Unknown": these are the rows written before the tag existed, and
      // "Untagged" says which fact is missing rather than implying the call is
      // mysterious.
      '' => (string) $this->t('Untagged'),
      default => $tag,
    };
  }

  /**
   * What the call site does, for the rows we can say it about.
   *
   * One line, because the point of the row is the number beside it. An
   * unrecognised tag gets the one sentence that is actually useful about it:
   * nothing in this release writes it.
   */
  public function description(?string $tag): string {
    $tag = (string) ($tag ?? '');
    return match ($tag) {
      UsageRecorder::TAG_AGENT_TURN => (string) $this->t('One reasoning step of the operator or a sub-agent. The dominant cost on most sites.'),
      'aincient_chat_thread_namer' => (string) $this->t('The short title written for a chat thread — small, frequent, and on the fast model.'),
      UsageRecorder::TAG_SIMPLE_CHAT => (string) $this->t('A one-shot chat node: the brand specialists and the plain flow chat.'),
      'aincient_media_studio' => (string) $this->t('Image generation and the alt text, names and edits around it.'),
      '' => (string) $this->t('Recorded before Atelier tagged its call sites, or by a call site that passed no tag.'),
      default => (string) $this->t('Not a call site this release writes — from an older version, or from a module outside Atelier.'),
    };
  }

  /**
   * Whether this release recognises the tag.
   *
   * Drives nothing but a mark in the table. It is worth the mark: an
   * unrecognised tag spending real money is either a call site we forgot to name
   * or one we did not know was running.
   */
  public function isKnown(?string $tag): bool {
    return in_array((string) ($tag ?? ''), self::KNOWN, TRUE);
  }

  /**
   * Decorates aggregate rows with labels and a proportion for the bar.
   *
   * THE BAR IS DRAWN ON TOKENS, NOT ON SPEND, and that is not a style choice. An
   * unpriced model is recorded at $0.00 ({@see \Drupal\aincient_core\Usage\ModelPricing}),
   * so a bar scaled to spend renders the single biggest consumer on the page as
   * an empty sliver — the opposite of the truth, drawn confidently. Tokens are
   * counted the same whether or not we have a rate, so the bar stays honest even
   * while the money column is under-reporting, which is precisely the condition
   * this page has to survive.
   *
   * @param list<array{context_id: ?string, calls: int, tokens: int, spend: float}> $rows
   *   Aggregate rows, one per distinct tag.
   *
   * @return list<array{context_id: string, label: string, description: string, known: bool, calls: int, tokens: int, spend: float, share: float}>
   *   The same rows, largest by tokens first, each with a 0–100 share.
   */
  public function decorate(array $rows): array {
    $largest = 0;
    foreach ($rows as $row) {
      $largest = max($largest, (int) $row['tokens']);
    }

    $out = [];
    foreach ($rows as $row) {
      $tag = (string) ($row['context_id'] ?? '');
      $out[] = [
        'context_id' => $tag,
        'label' => $this->label($tag),
        'description' => $this->description($tag),
        'known' => $this->isKnown($tag),
        'calls' => (int) $row['calls'],
        'tokens' => (int) $row['tokens'],
        'spend' => (float) $row['spend'],
        // Relative to the LARGEST row, not to the total: with four call sites
        // the shares of a total are all short stubs and the ranking is the thing
        // being read. Zero rows can't divide, and a share of 0 is right for them.
        // Cast: PHP's division of two evenly-divisible ints yields an int, so
        // the largest row would come back as int 100 while every other row is a
        // float — one key with two types is a trap for whatever reads it next.
        'share' => $largest > 0 ? (float) (((int) $row['tokens'] / $largest) * 100) : 0.0,
      ];
    }

    usort($out, static fn (array $a, array $b): int => $b['tokens'] <=> $a['tokens']);
    return $out;
  }

}
