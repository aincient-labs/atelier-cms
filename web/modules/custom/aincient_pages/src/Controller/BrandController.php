<?php

declare(strict_types=1);

namespace Drupal\aincient_pages\Controller;

use Drupal\aincient_pages\BrandRepository;
use Drupal\aincient_pages\ColorContrast;
use Drupal\aincient_pages\DesignTokens;
use Drupal\aincient_pages\FontPairings;
use Drupal\aincient_pages\PresetCatalog;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * JSON API for the brand/design-token system (consumed by the chat console).
 */
final class BrandController implements ContainerInjectionInterface {

  public function __construct(
    private readonly DesignTokens $tokens,
    private readonly BrandRepository $brand,
    private readonly FontPairings $fontPairings,
    private readonly ColorContrast $contrast,
    private readonly PresetCatalog $presets,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('aincient_pages.design_tokens'),
      $container->get('aincient_pages.brand'),
      $container->get('aincient_pages.font_pairings'),
      $container->get('aincient_pages.color_contrast'),
      $container->get('aincient_pages.preset_catalog'),
    );
  }

  /**
   * The full token manifest: metadata + current value for every editable token.
   *
   * This is the single source the frontend brand editor renders from — one
   * control per token, grouped by tier, with the live value pre-filled. The
   * `css_var` is exactly what the preview sets via style.setProperty('--' + …).
   */
  public function manifest(): JsonResponse {
    $stored = $this->brand->tokens();
    $out = [];
    foreach ($this->tokens->all() as $name => $def) {
      // Derived tokens (the synthesised shadow rungs) are computed in CSS from the
      // axis tokens — not editable, so they never appear in the rail.
      if (!empty($def['derived'])) {
        continue;
      }
      $out[] = [
        'name' => $name,
        'css_var' => $def['css_var'],
        'type' => $def['type'] ?? 'length',
        'tier' => $def['tier'] ?? 'semantic',
        'category' => $def['category'] ?? 'other',
        'component' => $def['component'] ?? NULL,
        // `internal` tokens are tracked + published (so a preset's writes persist)
        // but never rendered as a standalone control — they are driven by a preset
        // (e.g. the shadow direction multipliers). `advanced` tucks under a fold.
        'internal' => !empty($def['internal']),
        'advanced' => !empty($def['advanced']),
        // Colour tokens flagged `raw_color` may take a raw tint below the palette
        // tier (e.g. the shadow colour) — the studio uses this to offer the raw
        // hex/OKLCH picker where the reference-only rule would otherwise block it.
        'raw_color' => !empty($def['raw_color']),
        // The paired on-colour token (surface colours only) — lets the studio
        // render the pair as one control and show its contrast ratio.
        'on' => $def['on'] ?? NULL,
        'label' => $def['label'] ?? $name,
        'description' => $def['description'] ?? '',
        'enum' => $def['enum'] ?? NULL,
        // Human-facing option names, parallel to `enum` by index (so an enum
        // token renders as a select of friendly labels, not raw CSS values).
        'enum_labels' => $def['enum_labels'] ?? NULL,
        'default' => (string) ($def['default'] ?? ''),
        // The live value: the saved override if any, else the registry default.
        'current' => (string) ($stored[$name] ?? $def['default'] ?? ''),
      ];
    }
    return new JsonResponse([
      'tokens' => $out,
      // Tier 0: the immutable Tailwind base palette the studio renders swatches
      // from (Tier 1 colour tokens pick from these).
      'palette' => $this->tokens->palette(),
      // The layered reference contract: which tiers each tier may target. The
      // studio uses this to scope every picker to tier-legal choices.
      'referable' => DesignTokens::REFERABLE,
      // A short, opinionated set of popular display+body font pairings the
      // studio offers as one-click typography presets (custom stacks stay
      // editable below them).
      'font_pairings' => $this->fontPairings->summaries(),
      // High-level PRESET groups (font pairing, roundness, depth, density, body
      // size, heading weight) the rail leads with — each option expands to a
      // coherent token map the studio applies through the same override store.
      // Same vocabulary the brand agent picks from via preview_brand.
      'presets' => $this->presets->groups(),
      // The currently-saved web fonts — the baseline the studio diffs staged
      // font changes against to know whether fonts are dirty.
      'fonts' => $this->brand->fontFamilies(),
      // WCAG contrast for every surface/on pair at the saved values — the studio
      // shows a per-pair ratio badge so a weak pairing is visible.
      'contrast' => $this->contrast->pairReport($stored),
      // Advisory accent legibility: brand-accent text (primary) on the neutral
      // surfaces it lands on (background/muted/card). Reported alongside — but
      // distinct from — the hard surface/on contract above.
      'accent_contrast' => $this->contrast->legibilityReport($stored),
      // The current design-intent status (stage + lock) — the studio seeds its
      // status control from this, and the badge reflects it. Persisted out-of-band
      // from tokens (see setStatus), so it's always the live value.
      'status' => $this->brand->status(),
    ]);
  }

  /**
   * Persist the brand design-intent status (stage + lock) IMMEDIATELY.
   *
   * Separate from {@see self::save} on purpose: status is a MODE, not draft
   * content, so it does NOT ride the token draft→Publish cycle — toggling the
   * studio control (or confirming an agent proposal) writes here at once, making
   * the persisted config the single source of truth the brand agent reads next
   * turn. POST `{ "stage": "ideating|guided|polish", "locked": bool }`.
   */
  public function setStatus(Request $request): JsonResponse {
    $data = json_decode((string) $request->getContent(), TRUE);
    if (!is_array($data)) {
      return new JsonResponse(['error' => 'Expected { "stage": …, "locked": bool }.'], 400);
    }
    // Default to the current values so a partial POST (only stage, or only lock)
    // is a valid targeted change rather than a reset.
    $current = $this->brand->status();
    $stage = isset($data['stage']) && is_string($data['stage']) ? $data['stage'] : $current['stage'];
    $locked = array_key_exists('locked', $data) ? (bool) $data['locked'] : $current['locked'];

    if (!in_array($stage, BrandRepository::STAGES, TRUE)) {
      return new JsonResponse([
        'error' => 'Unknown stage.',
        'allowed' => BrandRepository::STAGES,
      ], 422);
    }

    return new JsonResponse(['status' => $this->brand->setStatus($stage, $locked)]);
  }

  /**
   * Publish the brand studio's working token diff — the console's one global
   * write.
   *
   * The studio (and the quick picker that seeds it) edit a preview-only draft;
   * nothing reaches the live site until the user clicks Publish, which POSTs
   * `{ "tokens": { <name>: <value>, … }, "fonts"?: [<family>, …] }` here. Every
   * token is validated against the registry and only valid ones are written;
   * persistence goes through the single BrandRepository::update path the no-AI
   * form also uses, so the change reskins every page live and the config-save
   * subscriber records an attributed, reversible revision (no writer is
   * special-cased). This Publish is the ONLY way the brand AGENT's work reaches
   * the live site — the agent itself only ever previews (see PreviewBrand);
   * there is no server-side "set brand" tool.
   */
  public function save(Request $request): JsonResponse {
    $data = json_decode((string) $request->getContent(), TRUE);
    if (!is_array($data) || !isset($data['tokens']) || !is_array($data['tokens'])) {
      return new JsonResponse(['error' => 'Expected { "tokens": { name: value, … } }.'], 400);
    }

    $valid = [];
    $rejected = [];
    foreach ($data['tokens'] as $name => $value) {
      if (!is_string($name) || !is_string($value) || trim($value) === '') {
        $rejected[] = (string) $name;
        continue;
      }
      if ($this->tokens->validate($name, trim($value))) {
        $valid[$name] = trim($value);
      }
      else {
        $rejected[] = $name;
      }
    }

    // Web fonts are optional and ride along when a preset seeded the draft.
    $fonts = NULL;
    if (isset($data['fonts']) && is_array($data['fonts'])) {
      $fonts = array_values(array_filter(array_map(
        static fn ($f) => is_string($f) ? trim($f) : '',
        $data['fonts'],
      )));
    }

    if ($valid === [] && $fonts === NULL) {
      return new JsonResponse([
        'error' => 'No valid token changes to publish.',
        'rejected' => $rejected,
      ], 422);
    }

    $applied = $this->brand->update($valid, $fonts);

    // Advisory contrast check on the published result (saved tokens after the
    // write). We do NOT block — pairing is enforced by name + the studio's
    // warning; this returns any AA failures so the UI can flag them.
    $saved = $this->brand->tokens();

    return new JsonResponse([
      'applied' => $applied,
      'rejected' => $rejected,
      'tokens' => $saved,
      'contrast_warnings' => $this->contrast->failures($saved),
      // Advisory: accent text (primary) that fails AA on a neutral surface the
      // brand has darkened. Like contrast_warnings, surfaced not blocked.
      'accent_warnings' => $this->contrast->legibilityFailures($saved),
    ]);
  }

}
