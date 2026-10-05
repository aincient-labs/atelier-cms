<?php

declare(strict_types=1);

namespace Drupal\aincient_pages;

/**
 * Builds a validated brand-preview widget envelope from raw preview args.
 *
 * The single source of truth for turning a `preview_brand`-shaped slice
 * (`presets_json` / `tokens_json` / `fonts` / `reset`) into the client-ready
 * `brand_preview` widget envelope the studio's draft store applies. ALL the
 * apply logic — preset expansion, token validation + css_var mapping, font
 * validation, WCAG contrast advisories, the envelope shape — lives here once,
 * so every caller produces an identical, contrast-checked payload:
 *
 *  1. {@see \Drupal\aincient_brand\Plugin\AiCapability\PreviewBrand} — the
 *     legacy LLM tool (kept for any non-rice agent still wired to it).
 *  2. the Brand orchestrator's deterministic merge node
 *     (`aincient_flows:brand_apply_slices`) — end-of-turn, merged slices.
 *  3. the live slice streamer (`BrandSliceStreamSubscriber`) — mid-turn, per
 *     specialist slice, transient frame.
 *
 * Brand DOMAIN logic lives in aincient_pages (per the module contract), and
 * every dependency here is an aincient_pages service — which is also why this
 * is the dependency-clean home for the shared applier (aincient_brand and
 * aincient_flows both already depend on aincient_pages).
 *
 * The `payload` this returns carries the validated change as the Identity
 * studio's typed command batch (`commands`, plans/studio-commands.md P1,
 * DECISIONS 0447) — the exact `{verb, args}` shape chat-ui's
 * `brand-preview-ops.ts#brandPreviewCommands` executes as ONE undoable batch:
 * `reset` first, then `set_tokens` (css_var → value), then `set_fonts` (family
 * names) — each only when it has something to say. NOT the raw `*_json` arg
 * shape, and no longer the legacy `{tokens, fonts, reset}` maps (the client
 * still reads those for stored cards until 0.18). Server-side readers that
 * need the maps back use {@see self::changes()}.
 */
final class BrandPreviewApplier {

  public function __construct(
    private readonly DesignTokens $designTokens,
    private readonly ColorContrast $colorContrast,
    private readonly BrandRepository $brand,
    private readonly PresetCatalog $presets,
  ) {}

  /**
   * The turn's unsaved studio draft (token name => value), contrast baseline.
   *
   * @var array<string, string>
   */
  private array $draftBaseline = [];

  /**
   * Stage the studio's unsaved draft as this request's contrast baseline.
   *
   * Without it, an incremental preview (one or two tokens) is contrast-graded
   * against the SAVED brand — so the advisory warns about pairings from a
   * palette the user already previewed away, and stays silent about the draft
   * they are actually looking at. The chat controller stages the same
   * `brand_context` draft it injects into the prompt; per-request turn state
   * only, never persisted. Keys may be css_var names (as the client sends
   * them) or token names — both are mapped to token names here.
   *
   * @param array<string, string> $overrides
   *   css_var (or token name) => CSS value.
   */
  public function setDraftBaseline(array $overrides): void {
    $byCssVar = [];
    foreach ($this->designTokens->all() as $name => $def) {
      $byCssVar[$def['css_var']] = $name;
    }
    $baseline = [];
    foreach ($overrides as $key => $value) {
      if (!is_string($value) || trim($value) === '') {
        continue;
      }
      $name = $byCssVar[$key] ?? (isset($byCssVar[ltrim((string) $key, '-')]) ? $byCssVar[ltrim((string) $key, '-')] : (string) $key);
      $baseline[$name] = trim($value);
    }
    $this->draftBaseline = $baseline;
  }

  /**
   * Build a brand-preview widget envelope from raw preview args.
   *
   * @param array{presets_json?: string|null, tokens_json?: string|null, fonts?: string|null, reset?: bool|string|null} $args
   *   Raw preview args (string values keep parity with FunctionCall
   *   getContextValue / node-param reads): a JSON object of {group: option} for
   *   `presets_json`, a JSON object of {token: css} for `tokens_json`, a
   *   comma-separated font list for `fonts`, and a truthy `reset`.
   *
   * @return array
   *   On success, a widget envelope:
   *   `['__widget__' => 'brand_preview', 'payload' => ['commands' => […],
   *   'rejected' => […], …], 'summary' => '…']` (see {@see self::commands()}).
   *   On a hard input error (malformed JSON, or nothing valid to apply), a
   *   single-key `['error' => '…']` — callers that can't surface prose (the
   *   merge node, the streamer) treat this as a no-op and emit no widget.
   */
  public function apply(array $args): array {
    $reset = $this->truthy($args['reset'] ?? FALSE);

    // ── 1. Expand any high-level PRESET choices to a base token map ──────────
    $presetTokens = [];
    $presetFonts = [];
    $badPresets = [];
    $presetsRaw = trim((string) ($args['presets_json'] ?? ''));
    if ($presetsRaw !== '') {
      $decoded = json_decode($presetsRaw, TRUE);
      if (!is_array($decoded)) {
        return ['error' => 'Error: presets must be a JSON object of {group: option_id}.'];
      }
      foreach ($decoded as $group => $option) {
        $expanded = is_string($option) ? $this->presets->expand((string) $group, $option) : NULL;
        if ($expanded === NULL) {
          $badPresets[] = (string) $group . ':' . (is_string($option) ? $option : '?');
          continue;
        }
        $presetTokens = $expanded['tokens'] + $presetTokens;
        $presetFonts = array_merge($presetFonts, $expanded['fonts']);
      }
    }

    // ── 2. Parse the explicit token map; it LAYERS OVER the presets ──────────
    $explicit = [];
    $uncastable = [];
    $raw = trim((string) ($args['tokens_json'] ?? ''));
    if ($raw !== '') {
      $decoded = json_decode($raw, TRUE);
      if (!is_array($decoded)) {
        return ['error' => 'Error: tokens must be a JSON object of {token_name: css_value}.'];
      }
      foreach ($decoded as $name => $value) {
        $css = self::scalarToCss($value);
        // An object/array value can't be a CSS token value at all. Name it in
        // `rejected` rather than dropping it silently — a dropped token the
        // agent believes it set is the failure mode this whole path guards.
        if ($css === NULL) {
          $uncastable[] = (string) $name;
          continue;
        }
        $explicit[(string) $name] = $css;
      }
    }

    // ── 3. Merge (explicit over presets) + validate every token ──────────────
    $tokens = [];
    $byName = [];
    $rejected = $uncastable;
    foreach ($explicit + $presetTokens as $name => $value) {
      if ($this->designTokens->validate((string) $name, $value)) {
        $value = $this->designTokens->normalize((string) $name, $value);
        $tokens[$this->designTokens->cssVar((string) $name)] = $value;
        $byName[(string) $name] = $value;
      }
      else {
        $rejected[] = (string) $name;
      }
    }

    // ── 4. Web fonts: explicit + any the presets stage, validated + deduped ──
    $fontsRaw = trim((string) ($args['fonts'] ?? ''));
    $explicitFonts = $fontsRaw !== '' ? array_map('trim', explode(',', $fontsRaw)) : [];
    $fonts = array_values(array_filter(
      array_unique(array_merge($explicitFonts, $presetFonts)),
      [BrandRepository::class, 'isFontName'],
    ));

    if (!$reset && !$tokens && !$fonts) {
      return ['error' => 'Error: provide at least one valid design token, preset, or web font to preview, or set reset=true.'
        . ($rejected ? ' Rejected tokens (unknown name or invalid value for its type): ' . implode(', ', $rejected) . '.' : '')
        . ($badPresets ? ' Unknown presets (group:option — check the preset list in the prompt): ' . implode(', ', $badPresets) . '.' : '')];
    }

    $count = count($tokens) + count($fonts);
    $summary = $reset
      ? 'Reverted the preview to the saved brand.'
      : 'Previewing ' . $count . ' brand ' . ($count === 1 ? 'change' : 'changes') . ' — watch the live preview, then Publish to apply.';
    if ($rejected) {
      $summary .= ' (Skipped invalid: ' . implode(', ', $rejected) . '.)';
    }
    if ($badPresets) {
      $summary .= ' (Unknown presets: ' . implode(', ', $badPresets) . '.)';
    }

    // Advisory contrast feedback on the draft: this call's tokens layered over
    // the studio's unsaved draft (when the turn staged one), then the saved
    // brand. A warning baked into the summary, not a block.
    $contrast = [];
    $accent = [];
    if (!$reset && $byName) {
      $draft = $byName + $this->contrastBaseline();
      foreach ($this->colorContrast->failures($draft) as $f) {
        $contrast[] = [
          'surface' => $f['surface'],
          'on' => $f['on'],
          'ratio' => $f['ratio'],
        ];
      }
      foreach ($this->colorContrast->legibilityFailures($draft) as $f) {
        $accent[] = [
          'text' => $f['text'],
          'surface' => $f['surface'],
          'ratio' => $f['ratio'],
        ];
      }
      $parts = [];
      foreach ($contrast as $f) {
        $parts[] = sprintf('%s/%s %.1f:1', $f['surface'], $f['on'], $f['ratio']);
      }
      foreach ($accent as $f) {
        $parts[] = sprintf('%s-as-text on %s %.1f:1', $f['text'], $f['surface'], $f['ratio']);
      }
      if ($parts) {
        // No "do not darken brand_primary" here: this text is read on turns
        // where darkening the primary is exactly what the user asked for. The
        // fix that never fights the ask is the on-colour, so name that.
        $summary .= ' ⚠ Low contrast (needs 4.5:1 for AA): ' . implode(', ', $parts)
          . ' — adjust the surface or its on-colour so text stays legible.'
          . ' (For a fill such as brand_primary, switching its on-colour between'
          . ' light and dark ink usually fixes the pair without touching the fill.)';
      }
    }

    return [
      '__widget__' => 'brand_preview',
      'payload' => [
        'commands' => self::commands($reset, $tokens, $fonts),
        'rejected' => $rejected,
        'rejected_presets' => $badPresets,
        'contrast_warnings' => $contrast,
        'accent_warnings' => $accent,
      ],
      'summary' => $summary,
    ];
  }

  /**
   * The contrast verdict for every pair a token change MOVES, as facts.
   *
   * The same grading {@see self::apply()} bakes into its advisory (this call's
   * tokens layered over the staged studio draft, then the saved brand, through
   * {@see ColorContrast} — which follows var() references), but reported per
   * pair, passes AND fails, and only for the pairs the change touches: a pair
   * whose surface or on-colour is in the change, or whose ratio the change
   * moved (brand_primary moves primary/primary_foreground through its var()
   * reference). Legibility combos are reported only when moved AND failing.
   *
   * Why it exists: the Brand orchestrator writes its final reply BEFORE the
   * end-of-turn apply computes `contrast_warnings`, so it used to claim "kept
   * contrast" for a 3.4:1 pair it had never seen graded. The specialist's
   * slice validator calls this and puts the lines into the tool result the
   * orchestrator reads before it replies (aincient_flows ValidateSlice).
   *
   * @param array<string, mixed> $tokens
   *   Token name => value (a specialist slice's `tokens_json`). Invalid
   *   entries are skipped, exactly as apply() would drop them.
   * @param array<string, mixed> $presets
   *   Optional {group: option} preset choices; expanded under the tokens.
   *
   * @return list<array{surface: string, on: string, ratio: float, passes: bool, line: string}>
   *   One entry per moved pair; `on` is the text token (the on-colour, or the
   *   legibility combo's text token), `line` the agent-facing sentence.
   */
  public function pairVerdicts(array $tokens, array $presets = []): array {
    $merged = [];
    foreach ($presets as $group => $option) {
      $expanded = is_string($option) ? $this->presets->expand((string) $group, $option) : NULL;
      if ($expanded !== NULL) {
        $merged = $expanded['tokens'] + $merged;
      }
    }
    $merged = $tokens + $merged;

    $byName = [];
    foreach ($merged as $name => $value) {
      $css = self::scalarToCss($value);
      if ($css !== NULL && $this->designTokens->validate((string) $name, $css)) {
        $byName[(string) $name] = $this->designTokens->normalize((string) $name, $css);
      }
    }
    if ($byName === []) {
      return [];
    }

    $baseline = $this->contrastBaseline();
    $after = $byName + $baseline;
    $moved = static fn (?float $was, ?float $now): bool => $was === NULL || abs($was - (float) $now) >= 0.005;

    $before = [];
    foreach ($this->colorContrast->pairReport($baseline) as $pair) {
      $before[$pair['surface'] . '|' . $pair['on']] = $pair['ratio'];
    }
    $out = [];
    foreach ($this->colorContrast->pairReport($after) as $pair) {
      if ($pair['ratio'] === NULL) {
        continue;
      }
      $touched = isset($byName[$pair['surface']]) || isset($byName[$pair['on']])
        || $moved($before[$pair['surface'] . '|' . $pair['on']] ?? NULL, $pair['ratio']);
      if ($touched) {
        $out[] = self::verdict($pair['surface'], $pair['on'], (float) $pair['ratio'], $pair['on'] . ' on ' . $pair['surface']);
      }
    }

    $beforeText = [];
    foreach ($this->colorContrast->legibilityReport($baseline) as $combo) {
      $beforeText[$combo['text'] . '|' . $combo['surface']] = $combo['ratio'];
    }
    foreach ($this->colorContrast->legibilityFailures($after) as $combo) {
      if ($moved($beforeText[$combo['text'] . '|' . $combo['surface']] ?? NULL, $combo['ratio'])) {
        $out[] = self::verdict($combo['surface'], $combo['text'], (float) $combo['ratio'], $combo['text'] . ' as text on ' . $combo['surface']);
      }
    }
    return $out;
  }

  /**
   * One graded pair, with the sentence an agent reads.
   *
   * @return array{surface: string, on: string, ratio: float, passes: bool, line: string}
   *   The pair, its ratio and AA verdict, and the agent-facing line.
   */
  private static function verdict(string $surface, string $on, float $ratio, string $label): array {
    $passes = $ratio >= ColorContrast::AA_NORMAL;
    $shown = rtrim(rtrim(sprintf('%.2f', $ratio), '0'), '.');
    return [
      'surface' => $surface,
      'on' => $on,
      'ratio' => $ratio,
      'passes' => $passes,
      'line' => $passes
        ? sprintf('%s: %s:1 — passes WCAG AA for body text (needs 4.5:1)', $label, $shown)
        : sprintf('%s: %s:1 — FAILS WCAG AA for body text (needs 4.5:1)', $label, $shown),
    ];
  }

  /**
   * What a change is contrast-graded against: the staged draft over saved.
   *
   * @return array<string, string>
   *   Token name => value.
   */
  private function contrastBaseline(): array {
    return $this->draftBaseline + $this->brand->tokens();
  }

  /**
   * The validated change as the Identity studio's command batch.
   *
   * Order is the client's contract (brand-preview-ops.ts): `reset` first, then
   * `set_tokens`, then `set_fonts` — fonts ONLY when there are some, so a
   * token-only preview doesn't wipe fonts a previous preview (or the studio)
   * staged. `reset`'s args are an empty OBJECT: the client validates them
   * against `{type: object}`, and a PHP `[]` would encode as a JSON array.
   *
   * @param bool $reset
   *   Clear the draft back to the saved brand first.
   * @param array<string, string> $tokens
   *   Validated css_var => CSS value.
   * @param list<string> $fonts
   *   Validated Google family names.
   *
   * @return list<array{verb: string, args: object|array<string, mixed>}>
   *   The batch.
   */
  public static function commands(bool $reset, array $tokens, array $fonts): array {
    $commands = [];
    if ($reset) {
      $commands[] = ['verb' => 'reset', 'args' => new \stdClass()];
    }
    if ($tokens !== []) {
      $commands[] = ['verb' => 'set_tokens', 'args' => ['tokens' => $tokens]];
    }
    if ($fonts !== []) {
      $commands[] = ['verb' => 'set_fonts', 'args' => ['fonts' => array_values($fonts)]];
    }
    return $commands;
  }

  /**
   * Read a `brand_preview` payload's command batch back as plain maps.
   *
   * For server-side readers that count or inspect what a preview staged (the
   * merge node's `applied`, the design-file proposal card, the brand eval
   * runner) — so none of them re-derives the command shape on its own. Later
   * `set_tokens` win per css_var; `step_token` is client-only (it needs the
   * palette the studio loads) and is not produced server-side.
   *
   * @param array $payload
   *   A `brand_preview` payload (`['commands' => […], …]`).
   *
   * @return array{tokens: array<string, string>, fonts: list<string>, reset: bool}
   *   The css_var => value overrides, the staged fonts, and whether it resets.
   */
  public static function changes(array $payload): array {
    $out = ['tokens' => [], 'fonts' => [], 'reset' => FALSE];
    foreach ((array) ($payload['commands'] ?? []) as $command) {
      if (!is_array($command)) {
        continue;
      }
      $args = (array) ($command['args'] ?? []);
      switch ($command['verb'] ?? NULL) {
        case 'reset':
          $out = ['tokens' => [], 'fonts' => [], 'reset' => TRUE];
          break;

        case 'set_tokens':
          $out['tokens'] = (array) ($args['tokens'] ?? []) + $out['tokens'];
          break;

        case 'set_fonts':
          $out['fonts'] = array_values((array) ($args['fonts'] ?? []));
          break;
      }
    }
    return $out;
  }

  /**
   * Decode a specialist slice into its `preview_brand` arg keys, or NULL.
   *
   * Both the deterministic merge node (reading a tool-result message's
   * `content`) and the live streamer (reading an executor job's `output_data`)
   * see the SAME shape — the workflow-executor envelope
   * `{"slice": "```json\n{…}```", "status": "success"}` — so they share this one
   * decoder. It unwraps the `slice` field, strips a ```code fence```, and keeps
   * only the keys the applier understands (`tokens_json` / `presets_json` /
   * `fonts`). Returns NULL for anything that isn't a slice (a brand_picker
   * `__widget__` envelope, an error string, the empty buffer) so callers can
   * cheaply skip non-specialist results.
   *
   * @param string $content
   *   A tool-result message content or executor job slice (fenced or bare).
   *
   * @return array<string, mixed>|null
   *   The slice keyed by `tokens_json`/`presets_json`/`fonts`, or NULL.
   */
  public function decodeSlice(string $content): ?array {
    $content = trim($content);
    if ($content === '') {
      return NULL;
    }
    // No fence stripping here, deliberately. This used to strip a ```lang fence
    // with a regex anchored at both ends, which silently failed whenever a
    // specialist put its rationale AFTER the closing fence — the decode failed,
    // the slice was dropped, and the agent reported a change that never applied.
    // Parsing model output is now the graph's job: each specialist runs the
    // engine's tolerant `json_to_data` before its validator, so what crosses the
    // tool boundary is always well-formed JSON. A fenced payload arriving here
    // means a producer skipped that node, and failing to decode it is the signal
    // that says so. See the ValidateSlice docblock.
    if ($content[0] !== '{') {
      return NULL;
    }
    $data = json_decode($content, TRUE);
    if (!is_array($data)) {
      return NULL;
    }
    // Unwrap the workflow-executor envelope: {slice: "<fenced json>", status}.
    if (isset($data['slice']) && is_string($data['slice'])) {
      return $this->decodeSlice($data['slice']);
    }
    // A bare slice object: keep only the keys apply() understands.
    $slice = array_intersect_key(self::normalizeSliceShape($data), array_flip(['tokens_json', 'presets_json', 'fonts']));
    return $slice !== [] ? $slice : NULL;
  }

  /**
   * Un-stringify a slice's `tokens_json`/`presets_json` if a model nested them.
   *
   * Both keys are OBJECTS in a slice, but the tool-arg contract they're named
   * after declares them as JSON *strings* ({@see \Drupal\aincient_brand\Plugin\AiCapability\PreviewBrand}'s
   * ContextDefinitions), so a specialist stringifies the nested object often
   * enough to matter. Every consumer gates on is_array() — the merge node, the
   * live streamer, and the specialist's own ValidateSlice — so a stringified
   * one was dropped exactly as silently as an unquoted number was
   * ({@see self::scalarToCss}). This is the one place that shape is repaired;
   * ValidateSlice calls it too, since it parses the raw slice BEFORE either of
   * the other two see it and would otherwise strip the key first.
   *
   * @param array<string, mixed> $data
   *   A decoded slice object.
   *
   * @return array<string, mixed>
   *   The same slice with either key re-decoded to an array where it was a
   *   JSON-object string. Anything else is returned untouched.
   */
  public static function normalizeSliceShape(array $data): array {
    foreach (['tokens_json', 'presets_json'] as $key) {
      if (is_string($data[$key] ?? NULL)) {
        $inner = json_decode(trim($data[$key]), TRUE);
        if (is_array($inner)) {
          $data[$key] = $inner;
        }
      }
    }
    return $data;
  }

  /**
   * Coerce a decoded JSON token value to its CSS string, or NULL if it can't be.
   *
   * Token values ARE strings by contract, but the writers are language models
   * and JSON has a number type: a `number`-typed token (shadow_strength,
   * density, the weight/leading scales) is regularly emitted unquoted — `0.9`
   * rather than `"0.9"`. Every stage upstream accepts that (the specialist's
   * ValidateSlice coerces to string to validate, then keeps the raw value; the
   * merge node re-encodes it as a number), so a strict is_string() gate here
   * dropped the token silently — and when it was the turn's only token, apply()
   * returned an error, the merge emitted no widget, and the agent reported a
   * change the preview never made. Numbers are the CSS value they print as, so
   * coerce them; bools become "true"/"false" so they fail validation loudly in
   * `rejected` instead of vanishing.
   */
  private static function scalarToCss(mixed $value): ?string {
    if (is_string($value)) {
      return trim($value);
    }
    if (is_int($value) || is_float($value)) {
      // PHP 8 casts floats locale-independently, so this is always "0.25".
      return (string) $value;
    }
    if (is_bool($value)) {
      return $value ? 'true' : 'false';
    }
    return NULL;
  }

  /**
   * Coerce a raw reset value (bool or "true"/"1" string) to bool.
   */
  private function truthy(mixed $value): bool {
    if (is_bool($value)) {
      return $value;
    }
    if (is_string($value)) {
      return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], TRUE);
    }
    return (bool) $value;
  }

}
