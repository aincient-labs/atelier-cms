<?php

declare(strict_types=1);

namespace Drupal\aincient_core;

/**
 * What this install can DO, in three verbs — and every rendering of that answer.
 *
 * A pure value object: three booleans plus the ONE table that turns them into
 * both surfaces the product shows them on — the console's capability chips
 * ({@see self::chips()}) and the line appended to an agent's system prompt
 * ({@see self::promptLine()}). Both are generated from the same row, which is the
 * whole point: a chip that lights up and a model that says it cannot draw are the
 * same bug one level apart, and they can only disagree if two places write the
 * words. Generated prose is blunter than hand-written; it also cannot go stale.
 *
 * VERBS, NOT MODALITIES. The product never says "vision" or "text-to-image" to a
 * site owner. It says what they get: Write, Describe, Draw.
 *
 * EXACTLY TWO STATES per verb: available, or dim-with-an-explanation. A lit chip
 * is a promise, so anything we cannot establish reads as needs-setup with an
 * explanation — never a hedged third state ("probably works"), which is how a
 * capability claim becomes a failure the user meets mid-turn instead of up front.
 *
 * A chip can be dim for TWO INDEPENDENT REASONS, and they carry different copy
 * (and different remedies), so each row ships both:
 *  - `hint` — the INSTALL cannot: no provider/model behind the verb. Fixable, so
 *    it links an admin at the wizard.
 *  - `unusedHint` — the install can, but THIS ROOM's assistant has no tool that
 *    spends the verb ({@see CapabilityVerbs}). Nothing to fix; it is a fact about
 *    the room, so it links nowhere.
 * Which verbs a room shows at all is its STUDIO's business — the console filters
 * this row down to the studio's verbs before rendering.
 *
 * NOT a gate. Nothing here decides ACCESS — a room whose only capability is words
 * is still worth having, and every tool reports its own failure at call time.
 * Being approximately right about a label is therefore safe; being confidently
 * wrong is not.
 *
 * @see \Drupal\aincient_core\InstallCapabilities
 */
final class CapabilitySet {

  /**
   * Words in, words out — titles, descriptions, copy.
   */
  public const WRITE = 'write';

  /**
   * Reads a picture the user gives it (alt text, captions, names).
   */
  public const DESCRIBE = 'describe';

  /**
   * Makes a picture that did not exist.
   */
  public const DRAW = 'draw';

  public function __construct(
    public readonly bool $write,
    public readonly bool $describe,
    public readonly bool $draw,
  ) {}

  /**
   * The three verbs, in display order — the one enumeration of the set.
   *
   * Read by {@see CapabilityVerbs} so a room's chip row orders the same way the
   * install-wide row does.
   *
   * @return list<string>
   */
  public static function verbs(): array {
    return array_keys(self::rows());
  }

  /**
   * Whether a verb is available here.
   */
  public function has(string $verb): bool {
    return match ($verb) {
      self::WRITE => $this->write,
      self::DESCRIBE => $this->describe,
      self::DRAW => $this->draw,
      default => FALSE,
    };
  }

  /**
   * The chips the console renders above the composer, in display order.
   *
   * Present in every room, always — not only when something is missing. A user
   * builds the "rooms have capabilities" model on a HEALTHY install, so that a
   * dimmed chip later reads as information rather than alarm.
   *
   * @param string $setupUrl
   *   Where an operator fixes a needs-setup verb (the onboarding wizard; see
   *   {@see InstallCapabilities::connectUrl()}). Passed in rather than derived
   *   here so this stays a pure value object — the URL comes from Drupal's route
   *   table, never a literal in copy.
   *
   * @return list<array{id: string, label: string, means: string, available: bool, hint: string, unusedHint: string, setupUrl: string}>
   */
  public function chips(string $setupUrl): array {
    $out = [];
    foreach (self::rows() as $id => $row) {
      $available = $this->has($id);
      $out[] = [
        'id' => $id,
        'label' => $row['label'],
        'means' => $row['means'],
        'available' => $available,
        // Empty when available: there is nothing to explain about a promise the
        // install keeps.
        'hint' => $available ? '' : $row['hint'],
        // The OTHER reason a chip can be dim: the install can do this, but the
        // room's own assistant has no tool for it. Always shipped (it does not
        // depend on install state) — the console picks between the two.
        'unusedHint' => $row['unused'],
        'setupUrl' => $available ? '' : $setupUrl,
      ];
    }
    return $out;
  }

  /**
   * The capability block appended to an agent's system prompt.
   *
   * One clause per verb, picked by the same boolean the chip reads. Deliberately
   * flat and repetitive: the alternative is a hand-written variant per state,
   * which is eight strings to keep in agreement with three booleans.
   */
  public function promptLine(): string {
    $lines = [];
    foreach (self::rows() as $id => $row) {
      $lines[] = '- ' . ($this->has($id) ? $row['can'] : $row['cannot']);
    }
    return "CAPABILITIES OF THIS INSTALL — offer only what is listed as available here:\n"
      . implode("\n", $lines);
  }

  /**
   * The one table behind both renderings: label, meaning, hint, prompt clauses.
   *
   * @return array<string, array{label: string, means: string, hint: string, unused: string, can: string, cannot: string}>
   */
  private static function rows(): array {
    return [
      self::WRITE => [
        'label' => 'Write',
        'means' => 'Words in, words out — titles, descriptions and copy.',
        'hint' => 'needs a connected model',
        'unused' => 'not part of this chat',
        'can' => 'You can write and rewrite words — titles, descriptions, copy.',
        'cannot' => 'You cannot write anything in this install: no chat model is connected. Say so plainly and point the user at the Atelier models page.',
      ],
      self::DESCRIBE => [
        'label' => 'Describe',
        'means' => 'Reads a picture you give it — alt text, captions, names.',
        'hint' => 'needs a model that can read images',
        'unused' => 'this chat doesn’t read images',
        'can' => 'You can read an image the user gives you and describe it (alt text, captions, names).',
        'cannot' => 'You cannot reliably read images in this install: no model is pinned for image description. Do not promise alt text or captions; say it needs a vision-capable model on the Atelier models page.',
      ],
      self::DRAW => [
        'label' => 'Draw',
        'means' => 'Makes a picture that did not exist.',
        'hint' => 'needs an image provider',
        'unused' => 'this chat doesn’t make images',
        'can' => 'You can generate and edit images.',
        'cannot' => 'You cannot generate images in this install: no image provider is connected. Do not offer to make or edit pictures; say it needs an image provider on the Atelier models page.',
      ],
    ];
  }

}
