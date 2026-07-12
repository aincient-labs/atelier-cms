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
 *  1. {@see \Drupal\aincient_brand\Plugin\AiFunctionCall\PreviewBrand} — the
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
 * The `payload` this returns is the client-ready shape
 * (`tokens` = css_var → value map, `fonts` = family names) consumed by
 * `brand-preview-tool.tsx#applyOps` — NOT the raw `*_json` arg shape.
 */
final class BrandPreviewApplier {

  public function __construct(
    private readonly DesignTokens $designTokens,
    private readonly ColorContrast $colorContrast,
    private readonly BrandRepository $brand,
    private readonly PresetCatalog $presets,
  ) {}

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
   *   `['__widget__' => 'brand_preview', 'payload' => […], 'summary' => '…']`.
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
    $raw = trim((string) ($args['tokens_json'] ?? ''));
    if ($raw !== '') {
      $decoded = json_decode($raw, TRUE);
      if (!is_array($decoded)) {
        return ['error' => 'Error: tokens must be a JSON object of {token_name: css_value}.'];
      }
      foreach ($decoded as $name => $value) {
        if (is_string($value)) {
          $explicit[(string) $name] = trim($value);
        }
      }
    }

    // ── 3. Merge (explicit over presets) + validate every token ──────────────
    $tokens = [];
    $byName = [];
    $rejected = [];
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

    // Advisory contrast feedback on the draft (this call's tokens layered over
    // the saved brand). A warning baked into the summary, not a block.
    $contrast = [];
    $accent = [];
    if (!$reset && $byName) {
      $draft = $byName + $this->brand->tokens();
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
        $summary .= ' ⚠ Low contrast (needs 4.5:1 for AA): ' . implode(', ', $parts)
          . ' — adjust the surface or its on-colour so text stays legible.'
          . ' (Brand-coloured text uses the derived primary_on_surface token, so'
          . ' brand_primary itself stays free to be vivid — do not darken it.)';
      }
    }

    return [
      '__widget__' => 'brand_preview',
      'payload' => [
        'tokens' => $tokens,
        'fonts' => $fonts,
        'reset' => $reset,
        'rejected' => $rejected,
        'rejected_presets' => $badPresets,
        'contrast_warnings' => $contrast,
        'accent_warnings' => $accent,
      ],
      'summary' => $summary,
    ];
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
    // Strip a leading ```lang fence and trailing ``` (same idiom as
    // WidgetEnvelope) — specialists emit fenced JSON about half the time.
    if (str_starts_with($content, '```')) {
      $content = trim((string) preg_replace('/^```[a-zA-Z0-9_-]*\s*|\s*```$/', '', $content));
    }
    if ($content === '' || $content[0] !== '{') {
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
    $slice = array_intersect_key($data, array_flip(['tokens_json', 'presets_json', 'fonts']));
    return $slice !== [] ? $slice : NULL;
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
