<?php

declare(strict_types=1);

namespace Drupal\aincient_pages;

use Drupal\Core\Extension\ModuleExtensionList;
use Symfony\Component\Yaml\Yaml;

/**
 * Loads the design-token registry (design-tokens.yml) — the single source of
 * truth that drives validation, the editor, the AI tool, and CSS generation.
 *
 * Tokens are tiered palette → semantic → component (Tier 0 — the Tailwind base
 * palette — lives in build/tw-palette.generated.css, not here); defaults live
 * here, while user OVERRIDES live in the aincient_pages.brand config. A value
 * starting with `var(--x)` references another token's css_var (how tiers
 * compose); references may only point DOWN a tier (see TokenValue / REFERABLE).
 */
final class DesignTokens {

  public const TIERS = ['palette', 'semantic', 'component'];

  /**
   * Which tiers a given tier's tokens may reference via var(). References point
   * DOWN only. 'tailwind' is the synthetic Tier 0 (the --color-* base palette,
   * not in this registry) that palette tokens may reference.
   */
  public const REFERABLE = [
    'palette' => ['tailwind'],
    'semantic' => ['palette'],
    'component' => ['palette', 'semantic'],
  ];
  public const CATEGORIES = ['color', 'typography', 'space', 'radius', 'shadow', 'border', 'other'];

  /** @var array<string, array>|null Parsed manifest, keyed by token name. */
  private ?array $tokens = NULL;

  /** @var array<int, array>|null Tier 0 Tailwind palette groups (lazy). */
  private ?array $palette = NULL;

  public function __construct(private readonly ModuleExtensionList $moduleList) {}

  /** @return array<string, array> Every token, keyed by name (declaration order). */
  public function all(): array {
    if ($this->tokens === NULL) {
      $path = $this->moduleList->getPath('aincient_pages') . '/design-tokens.yml';
      $parsed = Yaml::parseFile($path)['tokens'] ?? [];
      foreach ($parsed as $name => &$def) {
        // css_var defaults to the name with underscores → hyphens.
        $def['css_var'] ??= str_replace('_', '-', $name);
      }
      $this->tokens = $parsed;
    }
    return $this->tokens;
  }

  public function get(string $name): ?array {
    return $this->all()[$name] ?? NULL;
  }

  /** @return array<string, array> */
  public function byTier(string $tier): array {
    return array_filter($this->all(), static fn($d) => ($d['tier'] ?? '') === $tier);
  }

  /** @return array<string, array> */
  public function byComponent(string $component): array {
    return array_filter($this->all(), static fn($d) => ($d['component'] ?? '') === $component);
  }

  /** The CSS custom-property name for a token, e.g. "primary-foreground". */
  public function cssVar(string $name): string {
    return $this->get($name)['css_var'] ?? str_replace('_', '-', $name);
  }

  /** @return string[] All css_var names (targets a var() reference may use). */
  public function knownVars(): array {
    return array_map(static fn($d) => $d['css_var'], $this->all());
  }

  /**
   * Tier 0: the Tailwind base palette groups, read from the generated JSON
   * (scripts/build-tokens.php → build/tw-palette.json). The studio renders
   * swatches from this; validation uses it as the legal targets for palette
   * tokens. Empty until the asset build has run.
   *
   * @return array<int, array>
   */
  public function palette(): array {
    if ($this->palette === NULL) {
      $path = $this->moduleList->getPath('aincient_pages') . '/build/tw-palette.json';
      $json = is_file($path) ? json_decode((string) file_get_contents($path), TRUE) : NULL;
      $this->palette = is_array($json['groups'] ?? NULL) ? $json['groups'] : [];
    }
    return $this->palette;
  }

  /**
   * The declared surface→on-colour pairs (tokens carrying an `on:` key). Each
   * pair is contrast-checked; see {@see \Drupal\aincient_pages\ColorContrast}.
   *
   * @return list<array{surface: string, on: string}>
   */
  public function pairs(): array {
    $out = [];
    foreach ($this->all() as $name => $def) {
      if (!empty($def['on'])) {
        $out[] = ['surface' => $name, 'on' => (string) $def['on']];
      }
    }
    return $out;
  }

  /**
   * Accent text × surface combinations templates render that are NOT a
   * surface's own on-colour — declared per text token via
   * `legible_on: [<surface>, …]` (e.g. brand `primary` used for links/accent text
   * on the neutral background/muted/card surfaces). Advisory: the `on:` pairs
   * ({@see pairs}) are the hard contract; these catch a brand whose accent is
   * unreadable on its own neutral surfaces once it darkens them.
   *
   * @return list<array{text: string, surface: string}>
   */
  public function legiblePairs(): array {
    $out = [];
    foreach ($this->all() as $name => $def) {
      foreach ((array) ($def['legible_on'] ?? []) as $surface) {
        $out[] = ['text' => $name, 'surface' => (string) $surface];
      }
    }
    return $out;
  }

  /** @return array<string, string> Tier 0 css_var => colour value (e.g. oklch()). */
  public function tailwindValues(): array {
    $vals = [];
    foreach ($this->palette() as $group) {
      foreach ($group['swatches'] ?? [] as $s) {
        if (!empty($s['css_var'])) {
          $vals[$s['css_var']] = (string) ($s['value'] ?? '');
        }
      }
    }
    return $vals;
  }

  /** @return string[] Tier 0 css_var names (the --color-* base swatches). */
  public function tailwindVars(): array {
    $vars = [];
    foreach ($this->palette() as $group) {
      foreach ($group['swatches'] ?? [] as $s) {
        if (!empty($s['css_var'])) {
          $vars[] = $s['css_var'];
        }
      }
    }
    return $vars;
  }

  /**
   * The css_vars a token in $tier may legally reference (the tiers below it,
   * per REFERABLE; 'tailwind' resolves to the Tier 0 base palette). Passed to
   * TokenValue as the var() allow-list, so an upward/sideways reference simply
   * isn't a known target and fails validation.
   *
   * @return string[]
   */
  public function referableVars(string $tier): array {
    $allowed = self::REFERABLE[$tier] ?? [];
    $vars = in_array('tailwind', $allowed, TRUE) ? $this->tailwindVars() : [];
    foreach ($this->all() as $def) {
      if (in_array($def['tier'] ?? '', $allowed, TRUE)) {
        $vars[] = $def['css_var'];
      }
    }
    return $vars;
  }

  /** @return array<string, string> name => default value. */
  public function defaults(): array {
    return array_map(static fn($d) => (string) ($d['default'] ?? ''), $this->all());
  }

  /**
   * Validate a value for a token by name — the gate every write path uses.
   *
   * Enforces the layered reference contract: a var() reference may only target
   * a tier the token is allowed to reference (referableVars), and a colour
   * token below the palette tier MUST be a reference (raw colours enter the
   * system only at Tier 1, the one place a colour is chosen).
   */
  public function validate(string $name, string $value): bool {
    $def = $this->get($name);
    if ($def === NULL) {
      return FALSE;
    }
    // Derived tokens are synthesised from other tokens (their default is a calc()
    // expression in the base CSS); they are never written directly.
    if (!empty($def['derived'])) {
      return FALSE;
    }
    $value = trim($value);
    $tier = (string) ($def['tier'] ?? 'semantic');
    $isReference = str_starts_with(strtolower($value), 'var(');
    // A colour-TYPED token below Tier 1 is reference-only — no raw colours past
    // the palette (keyed on type, not category, so a colour grouped elsewhere —
    // e.g. the shadow tint under category: shadow — keeps the same guarantee).
    // The lone exception is a token flagged `raw_color: true` (shadow_color — a
    // decorative theme_skip tint with no palette slot of its own; see the
    // registry note), which may take a raw colour OR a reference.
    if (($def['type'] ?? '') === 'color' && $tier !== 'palette' && !$isReference && empty($def['raw_color'])) {
      return FALSE;
    }
    return TokenValue::isValid(
      (string) ($def['type'] ?? ''),
      $value,
      $this->referableVars($tier),
      (array) ($def['enum'] ?? []),
    );
  }

  /**
   * Canonicalise a value for a token by name before it is emitted/stored — the
   * companion to {@see validate}. Resolves the token's type and delegates to
   * {@see TokenValue::normalize} (e.g. a length `0` → `0px`, so a hard shadow
   * survives the derived rungs' calc()). Unknown names pass through trimmed.
   */
  public function normalize(string $name, string $value): string {
    $def = $this->get($name);
    return TokenValue::normalize((string) ($def['type'] ?? ''), $value);
  }

  /**
   * Validate a value for a token and return a precise, agent-facing reason when
   * it fails — the structured counterpart to {@see validate} used by the
   * specialist's slice-validation node so a rejected token comes back to the
   * orchestrator with WHY + how to fix, not just a name. Returns NULL when the
   * value is valid.
   */
  public function rejectionReason(string $name, string $value): ?string {
    $def = $this->get($name);
    if ($def === NULL) {
      return "'$name' is not a design token (unknown name). Use an existing token name.";
    }
    if (!empty($def['derived'])) {
      return "'$name' is a derived/computed token and is never written directly.";
    }
    if ($this->validate($name, $value)) {
      return NULL;
    }
    $value = trim($value);
    $tier = (string) ($def['tier'] ?? 'semantic');
    $type = (string) ($def['type'] ?? '');
    $isReference = str_starts_with(strtolower($value), 'var(');
    // The most common cheap-model mistakes, named explicitly but concisely — the
    // full valid-name/target lists live in the specialist prompt, so we don't
    // re-dump them into every rejection (that bloats the orchestrator's context).
    if ($type === 'color' && $tier !== 'palette' && !$isReference && empty($def['raw_color'])) {
      return "'$name' is a colour below the palette tier — it must REFERENCE a palette "
        . "colour (e.g. var(--brand-primary), var(--neutral-ink)), not a raw value like "
        . "'$value'. To use a new colour, set a palette colour token to it first.";
    }
    if ($isReference) {
      return "'$value' references a token that doesn't exist — reference an existing token only.";
    }
    return "'$value' is not a valid $type value for '$name'.";
  }

  /**
   * The token names an agent/specialist is ALLOWED to set, in declaration order
   * — every token minus the ones it must not (derived rungs) or cannot
   * (internal preset-driven multipliers) write. The authoritative allow-list for
   * the slice validator's error text and the specialist prompts (so "valid
   * names" never drifts from the registry).
   *
   * @return string[]
   */
  public function settableNames(): array {
    $names = [];
    foreach ($this->all() as $name => $def) {
      if (empty($def['derived']) && empty($def['internal'])) {
        $names[] = $name;
      }
    }
    return $names;
  }

  /**
   * A one-line description of what each tier is FOR and what it may reference —
   * the naming convention, stated so the agent reasons about WHICH tier to edit
   * rather than memorising names. The tier lives in the manifest STRUCTURE (and
   * the name family), never in a tier prefix, so names stay shadcn/Tailwind-
   * idiomatic. Keep in sync with design-tokens.yml's NAMING CONVENTION header.
   */
  private const TIER_GUIDE = [
    'palette' => 'raw ingredients, the ONE place a colour/scale value is chosen. Names are family-prefixed (brand_*, neutral_*, radius_*, shadow_*, size_*, weight_*, leading_*, tracking_*, font_family_*). May reference the Tailwind base palette only. Set THESE to rebrand.',
    'semantic' => 'purpose/role tokens using the shadcn vocabulary (background, foreground, primary, primary_foreground, muted, accent, card, border, …). Bare role word ⇒ semantic. These are what Tailwind utilities map to (bg-primary, text-foreground). May reference PALETTE only. Override for fine control.',
    'component' => 'per-component knobs named {component}_{property} with a locked property vocab (_bg _fg _border _radius _shadow _height + the typography knobs _weight _tracking _leading, e.g. hero_heading_weight, stats_value_tracking, prose_leading) — predictable: knowing a component, you can guess its knobs. May reference PALETTE or SEMANTIC.',
  ];

  /**
   * A compact, AI-facing listing of every editable token (name, type, default,
   * description), grouped by tier — injected into the assistant's system prompt
   * so it can reason about what to change when rebranding. Each tier is prefaced
   * with its purpose + reference rule (TIER_GUIDE) so the convention is explicit.
   */
  public function manifestSummary(): string {
    $lines = [];
    foreach (self::TIERS as $tier) {
      $group = $this->byTier($tier);
      if (!$group) {
        continue;
      }
      $guide = self::TIER_GUIDE[$tier] ?? '';
      $lines[] = strtoupper($tier) . ' tokens — ' . $guide;
      foreach ($group as $name => $def) {
        // Hide tokens the agent must not (derived rungs) or need not (internal
        // multipliers driven by a preset; advanced fine-knobs) write directly.
        if (!empty($def['derived']) || !empty($def['internal']) || !empty($def['advanced'])) {
          continue;
        }
        $desc = !empty($def['description']) ? ' — ' . $def['description'] : '';
        // An enum is a CLOSED set — list its allowed values so the agent picks
        // one rather than inventing a raw value the validator will reject.
        $choices = ($def['type'] ?? '') === 'enum' && !empty($def['enum'])
          ? ', one of: ' . implode(' | ', (array) $def['enum'])
          : '';
        $lines[] = sprintf('  %s (%s, default %s%s)%s', $name, $def['type'] ?? '?', $def['default'] ?? '?', $choices, $desc);
      }
    }
    return implode("\n", $lines);
  }

}
