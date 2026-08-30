<?php

declare(strict_types=1);

namespace Drupal\aincient_chat\Chat;

use Drupal\aincient_pages\BrandPreviewApplier;
use Drupal\aincient_pages\TokenGrounding;

/**
 * Turns the brand studio's `brand_context` payload into the brand agent's
 * per-turn template variables (`live_preview_state`, `draft_tokens`).
 *
 * ONE renderer for the studio (ChatController) and for the live-turn eval
 * (`drush aincient:brand-eval`). It used to be two private controller methods;
 * the eval needs the exact same lines the studio sends — a hand-rolled copy is
 * how the "two contradictory currents" defect (DECISIONS 0408) would come back
 * with no symptom in the eval.
 *
 * Payload shape: `{overrides: {css_var: value}, fonts: [name]}`. Rendered as
 * readable `- token = value (grounding)` lines rather than raw JSON so the
 * model parses it easily. NULL when there is nothing to send (no draft → no
 * injection).
 */
final class BrandTurnContext {

  public function __construct(
    private readonly ?TokenGrounding $grounding,
    private readonly ?BrandPreviewApplier $previewApplier,
  ) {}

  /**
   * The template variables for one brand-studio turn, or NULL for no draft.
   *
   * @param mixed $brandContext
   *   The decoded `brand_context` payload, or NULL.
   *
   * @return array<string, string>|null
   */
  public function variables(mixed $brandContext): ?array {
    $draft = $this->render($brandContext);
    if ($draft === NULL) {
      return NULL;
    }
    $vars = ['live_preview_state' => $draft];
    // Which tokens the draft overrides, so the SAVED-brand brief can mark its
    // own entry for them superseded instead of stating a second, competing
    // "current" value for the same token. Two contradictory currents in one
    // prompt is a data defect, not a prompt-wording problem — and the stale one
    // reads more assertively ("Current saved palette: primary …"), so it wins
    // arguments it should not (DECISIONS 0408).
    $overrides = $brandContext['overrides'] ?? NULL;
    if (is_array($overrides)) {
      $names = array_keys(array_filter(
        $overrides,
        static fn ($v) => is_string($v) && trim($v) !== '',
      ));
      if ($names !== []) {
        $vars['draft_tokens'] = implode(',', $names);
      }
    }
    return $vars;
  }

  /**
   * Compact the live preview draft into the `live_preview_state` string.
   *
   * @param mixed $brandContext
   *   The decoded `brand_context` payload, or NULL.
   */
  public function render(mixed $brandContext): ?string {
    if (!is_array($brandContext)) {
      return NULL;
    }
    $lines = [];
    $overrides = $brandContext['overrides'] ?? NULL;
    if (is_array($overrides)) {
      // Grounding: annotate every value with what it actually IS. The model
      // reads oklch(0.98 0.01 0) as "white" but SEES #FFF6F8 as pink, reads
      // 0.75rem as a fraction but SEES 12px — and a var() reference
      // (var(--color-yellow-100), var(--radius-2xl)) carries no value at all
      // until it is followed. Echoing references bare made a relative edit
      // ("make primary darker") anchor on the saved palette instead of the
      // draft on screen (DECISIONS 0408). TokenGrounding is the ONE renderer
      // both this and the saved baseline in BrandState use, for every token
      // type — not just colour.
      //
      // Two passes: collect the whole draft first, so a token referencing
      // another DRAFTED token resolves against the draft, not the defaults.
      $draft = [];
      foreach ($overrides as $cssVar => $value) {
        if (is_string($value) && trim($value) !== '') {
          $draft[(string) $cssVar] = trim($value);
        }
      }
      foreach ($draft as $cssVar => $value) {
        $lines[] = '- ' . $cssVar . ' = ' . $value . ($this->grounding?->echoFor($cssVar, $value, $draft) ?? '');
      }
      // Stage the draft as this turn's contrast baseline: the preview applier
      // grades its WCAG advisories against draft-over-saved instead of just
      // saved, so an incremental token update isn't warned against a stale
      // published palette the user is no longer looking at.
      if ($draft !== []) {
        $this->previewApplier?->setDraftBaseline($draft);
      }
    }
    $fonts = $brandContext['fonts'] ?? NULL;
    if (is_array($fonts) && $fonts !== []) {
      $names = array_filter(array_map(static fn($f) => is_string($f) ? trim($f) : '', $fonts));
      if ($names !== []) {
        $lines[] = '- web fonts loaded: ' . implode(', ', $names);
      }
    }
    return $lines === [] ? NULL : implode("\n", $lines);
  }

}
