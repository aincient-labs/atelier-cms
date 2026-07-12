<?php

declare(strict_types=1);

namespace Drupal\aincient_pages;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * The site's FOUNDATIONS: the design-token layer (the abstract visual language)
 * — read & written by both the AI brand studio and the no-AI editor.
 *
 * Tokens are stored as CSS values and injected at render time as CSS variables
 * (the Tailwind utilities reference those vars, so changing them reskins every
 * page with NO rebuild). Brand IDENTITY (name/tagline/voice/logo/footer note)
 * lives separately in {@see SiteIdentity}; this layer is tokens + web fonts only.
 */
final class BrandRepository {

  public const CONFIG = 'aincient_pages.brand';

  /**
   * The monochrome emoji typeface — the family name the font_sans/font_display
   * stacks fall back to and the self-hosted @font-face declares.
   *
   * Agent-generated content reaches for emoji freely; without a pinned font each
   * visitor's OS renders its own set (Apple/Google/Microsoft/tofu), so the same
   * page looks different — and sometimes broken — per device. The MONOCHROME
   * "Noto Emoji" has no colour of its own, so emoji inherit their element's text
   * colour and read as on-brand glyphs with zero markup. This is infrastructure,
   * NOT a brand choice — never surfaced as a tunable token or in the Web-fonts
   * list (kept invisible by design, see decisions/DECISIONS.md 0058). And because
   * the user CAN'T opt out of it (unlike the brand font, which they may point at a
   * system stack), it is SELF-HOSTED — served from our own origin via the
   * `aincient_pages/emoji-font` library, never Google Fonts, so it leaks no
   * visitor IP. Vendored under fonts/ by scripts/fetch-emoji-font.php.
   */
  public const EMOJI_FONT = 'Noto Emoji';

  /**
   * How the site's brand web fonts reach the public pages.
   *
   * - 'google'   — load from Google Fonts, CONSENT-GATED (the visitor is asked;
   *                until they accept, the page uses the system-font fallback and
   *                no request hits Google). The default, so a fresh site renders
   *                exactly as before with only a consent gate added in front.
   * - 'selfhost' — vendor the woff2 to our own origin ({@see BrandFontVendor}) so
   *                no third-party request is ever made and no banner is needed.
   */
  public const DELIVERY_GOOGLE = 'google';
  public const DELIVERY_SELFHOST = 'selfhost';

  /**
   * The brand design-intent stages — a durable, shared state the studio and the
   * brand agent both read (distinct from the editor WRITE-lock, which is a
   * single-writer fencing mutex; this is intent, not a mutex).
   *
   * - 'ideating' — diverge freely; a named theme/mood may sweep the whole palette.
   * - 'guided'   — honour supplied inputs, don't invent new directions.
   * - 'polish'   — minimal surgical changes; no cross-axis drift.
   */
  public const STAGE_IDEATING = 'ideating';
  public const STAGE_GUIDED = 'guided';
  public const STAGE_POLISH = 'polish';
  public const STAGES = [self::STAGE_IDEATING, self::STAGE_GUIDED, self::STAGE_POLISH];

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly DesignTokens $designTokens,
    private readonly BrandFontVendor $fontVendor,
  ) {}

  public function tokens(): array {
    return $this->configFactory->get(self::CONFIG)->get('tokens') ?: [];
  }

  /**
   * A compact VISUAL brief — a handful of key brand tokens rendered as plain
   * text — so an image agent can generate on-brand pictures. Complements the
   * identity brief (name/voice, {@see \Drupal\aincient_pages\SiteIdentity::promptBrief})
   * with the concrete look: lead palette, typefaces, corner language, and border
   * weight.
   *
   * Every value is resolved to a HUMAN-READABLE descriptor, never a raw CSS
   * `var()` chain: concrete colours (hex/oklch) pass through; a Tier-0 Tailwind
   * reference like `var(--color-fuchsia-300)` becomes "fuchsia 300"; font stacks
   * collapse to the lead family name; radii and border widths become words
   * ("sharp, square corners", "bold borders"). '' when nothing useful resolves
   * (a fresh, untouched brand).
   */
  public function visualBrief(): string {
    $lines = [];

    // The lead palette — the four tokens that set the overall colour feel.
    foreach ([
      'primary' => 'Primary colour',
      'accent' => 'Accent colour',
      'background' => 'Background',
      'foreground' => 'Text colour',
    ] as $token => $label) {
      $desc = $this->colorDescriptor($token);
      if ($desc !== '') {
        $lines[] = $label . ': ' . $desc;
      }
    }

    // Typefaces — the heading/brand + body families the site actually renders in
    // (system-font stacks carry no brand signal, so they're dropped). Collapsed to
    // one line when heading and body share a family.
    $heading = $this->fontName('font_family_display');
    $body = $this->fontName('font_family_base');
    if ($heading !== '' && $heading === $body) {
      $lines[] = 'Typeface: ' . $heading;
    }
    else {
      if ($heading !== '') {
        $lines[] = 'Heading font: ' . $heading;
      }
      if ($body !== '') {
        $lines[] = 'Body font: ' . $body;
      }
    }

    // Corner language (card radius is the general-purpose representative).
    $corners = $this->radiusDescriptor('card_radius');
    if ($corners !== '') {
      $lines[] = 'Corners: ' . $corners;
    }

    // Border weight (hairline vs. bold neo-brutalist outlines).
    $borders = $this->borderDescriptor();
    if ($borders !== '') {
      $lines[] = 'Borders: ' . $borders;
    }

    return $lines ? implode("\n", $lines) : '';
  }

  /**
   * The effective stored value for a token: the user override if set, else the
   * registry default.
   */
  private function effectiveValue(string $name): string {
    $stored = $this->tokens();
    if (array_key_exists($name, $stored)) {
      return trim((string) $stored[$name]);
    }
    return trim((string) ($this->designTokens->get($name)['default'] ?? ''));
  }

  /**
   * Resolve a `var(--x)` reference chain down to a concrete value where the target
   * is a known registry token; a Tier-0 Tailwind reference (`--color-*`, not in
   * the registry) is returned verbatim for the descriptors to name. Depth-capped
   * against cycles.
   */
  private function resolveValue(string $value, int $depth = 0): string {
    $value = trim($value);
    if ($depth > 8 || !preg_match('/^var\(--([a-z0-9-]+)\)$/i', $value, $m)) {
      return $value;
    }
    $target = $m[1];
    foreach ($this->designTokens->all() as $name => $def) {
      if (($def['css_var'] ?? str_replace('_', '-', $name)) === $target) {
        return $this->resolveValue($this->effectiveValue($name), $depth + 1);
      }
    }
    // Not a registry token (e.g. the Tier-0 Tailwind palette): leave as-is.
    return $value;
  }

  /**
   * A readable colour descriptor for a colour token: concrete values pass through;
   * a Tailwind palette reference like `var(--color-fuchsia-300)` becomes
   * "fuchsia 300"; anything else unresolvable yields ''.
   */
  private function colorDescriptor(string $name): string {
    $value = $this->resolveValue($this->effectiveValue($name));
    if ($value === '') {
      return '';
    }
    if (!str_starts_with($value, 'var(')) {
      return $value;
    }
    if (preg_match('/^var\(--color-([a-z]+)-(\d+)\)$/i', $value, $m)) {
      return $m[1] . ' ' . $m[2];
    }
    return '';
  }

  /**
   * The lead family name from a font-family token's resolved stack — the first
   * entry, dequoted (`"Inter Tight", …` → "Inter Tight"). Generic/system
   * fallbacks (`sans-serif`, `system-ui`, …) carry no brand signal and yield '',
   * so a site on the default system stack contributes no typeface line.
   */
  private function fontName(string $name): string {
    $stack = $this->resolveValue($this->effectiveValue($name));
    if ($stack === '') {
      return '';
    }
    $first = trim(trim(explode(',', $stack)[0]), "\"' \t");
    $generic = [
      'ui-sans-serif', 'ui-serif', 'ui-monospace', 'ui-rounded',
      'system-ui', 'sans-serif', 'serif', 'monospace', 'cursive', 'fantasy',
    ];
    return in_array(strtolower($first), $generic, TRUE) ? '' : $first;
  }

  /**
   * Describe the corner language from a radius token's resolved length: 0 → sharp
   * square corners; a huge pill radius → fully rounded; otherwise the length.
   */
  private function radiusDescriptor(string $name): string {
    $value = $this->resolveValue($this->effectiveValue($name));
    if ($value === '') {
      return '';
    }
    if (str_contains($value, '9999')) {
      return 'fully rounded (pill-shaped)';
    }
    if (preg_match('/^0(px|rem|em)?$/', $value)) {
      return 'sharp, square corners (no rounding)';
    }
    return 'rounded corners (' . $value . ')';
  }

  /**
   * Describe the border weight from the resolved `border_width`: none, thin
   * hairline, or bold/heavy (the neo-brutalist look).
   */
  private function borderDescriptor(): string {
    $value = $this->resolveValue($this->effectiveValue('border_width'));
    if ($value === '') {
      return '';
    }
    if (preg_match('/^([\d.]+)px$/', $value, $m)) {
      $px = (float) $m[1];
      if ($px <= 0) {
        return 'no borders';
      }
      return $px <= 1
        ? 'thin hairline borders (' . $value . ')'
        : 'bold, heavy borders (' . $value . ')';
    }
    return $value . ' borders';
  }

  /**
   * The current brand design-intent status.
   *
   * Defaults to `{stage: 'ideating', locked: false}` on a fresh site — a new
   * brand should be free to diverge. Always returns a well-formed pair (an
   * unknown stored stage falls back to the default) so callers never validate.
   *
   * @return array{stage: string, locked: bool}
   */
  public function status(): array {
    $stored = $this->configFactory->get(self::CONFIG)->get('status');
    $stage = is_array($stored) ? (string) ($stored['stage'] ?? '') : '';
    return [
      'stage' => in_array($stage, self::STAGES, TRUE) ? $stage : self::STAGE_IDEATING,
      'locked' => is_array($stored) && !empty($stored['locked']),
    ];
  }

  /**
   * Persist the brand design-intent status IMMEDIATELY (not via the token
   * draft→Publish cycle) — status is a MODE, so the saved config is its single
   * source of truth the moment it changes. An unknown stage is coerced to the
   * default rather than rejected.
   *
   * @return array{stage: string, locked: bool}
   *   The status as persisted.
   */
  public function setStatus(string $stage, bool $locked): array {
    $stage = in_array($stage, self::STAGES, TRUE) ? $stage : self::STAGE_IDEATING;
    $this->configFactory->getEditable(self::CONFIG)
      ->set('status', ['stage' => $stage, 'locked' => $locked])
      ->save();
    return ['stage' => $stage, 'locked' => $locked];
  }

  /**
   * Google-Font family names to load, e.g. ['Inter', 'Playfair Display'].
   *
   * Defaults to the built-in pairing. Names are strictly validated, and the
   * stylesheet URL is constructed by us (isFontName + fontLinkHref) — user
   * input never becomes a raw URL, so this can't be an injection vector.
   *
   * @return string[]
   */
  public function fontFamilies(): array {
    $list = $this->configFactory->get(self::CONFIG)->get('font_families');
    if (!is_array($list) || !$list) {
      return ['Inter', 'Inter Tight'];
    }
    $valid = array_values(array_filter($list, [self::class, 'isFontName']));
    return $valid ?: ['Inter', 'Inter Tight'];
  }

  /**
   * The Google Fonts stylesheet URL for the configured brand families (or '').
   *
   * Brand fonts only. The emoji font (EMOJI_FONT) is deliberately NOT here — it's
   * self-hosted via the aincient_pages/emoji-font library so the one font the user
   * can't opt out of never reaches Google (see EMOJI_FONT / DECISIONS 0058).
   */
  public function fontLinkHref(): string {
    $parts = [];
    foreach ($this->fontFamilies() as $name) {
      $parts[] = 'family=' . str_replace(' ', '+', trim($name)) . ':wght@400;500;600;700;800';
    }
    return $parts ? 'https://fonts.googleapis.com/css2?' . implode('&', $parts) . '&display=swap' : '';
  }

  /**
   * The configured font-delivery mode (DELIVERY_GOOGLE | DELIVERY_SELFHOST).
   *
   * Defaults to Google (consent-gated) so an un-migrated site keeps rendering as
   * it did, with only a consent gate added in front of the request.
   */
  public function fontDelivery(): string {
    $mode = (string) $this->configFactory->get(self::CONFIG)->get('font_delivery');
    return $mode === self::DELIVERY_SELFHOST ? self::DELIVERY_SELFHOST : self::DELIVERY_GOOGLE;
  }

  /**
   * How the public render paths should load the brand web fonts.
   *
   * One descriptor, consumed identically by the themed render path
   * (aincient_theme) and the raw-HTML page shell (PageSpikeController), so the
   * delivery/consent decision lives in exactly one place:
   *   - ['mode' => 'none']                 — emit nothing (system stack)
   *   - ['mode' => 'selfhost', 'href' => …]— plain <link> to our vendored CSS
   *   - ['mode' => 'google',   'href' => …]— CONSENT-GATED <link> to Google
   *
   * Self-host falls back to 'none' (system fonts) when the woff2 haven't been
   * vendored yet — it NEVER falls back to hot-linking Google.
   *
   * @return array{mode: string, href?: string}
   */
  public function webFont(): array {
    if (!$this->fontFamilies()) {
      return ['mode' => 'none'];
    }
    if ($this->fontDelivery() === self::DELIVERY_SELFHOST) {
      $href = $this->fontVendor->vendoredHref($this->fontFamilies());
      return $href !== NULL ? ['mode' => 'selfhost', 'href' => $href] : ['mode' => 'none'];
    }
    $href = $this->fontLinkHref();
    return $href !== '' ? ['mode' => 'google', 'href' => $href] : ['mode' => 'none'];
  }

  /**
   * The `:root { --token: value; … }` override for the current brand (or '').
   *
   * Emits every stored override that still validates against the registry — any
   * token, any tier, any CSS value type. Each value is re-validated here so the
   * inline <style> can never carry an unsafe string (defense in depth).
   */
  public function cssVariables(): string {
    $stored = $this->tokens();
    $decl = [];
    foreach ($this->designTokens->all() as $name => $def) {
      // Derived tokens (e.g. the synthesised shadow rungs) are emitted only to the
      // base CSS as calc() over the axis vars — never as an override.
      if (!empty($def['derived'])) {
        continue;
      }
      if (!array_key_exists($name, $stored)) {
        continue;
      }
      $value = (string) $stored[$name];
      if ($this->designTokens->validate($name, $value)) {
        $decl[] = '--' . $def['css_var'] . ':' . $this->designTokens->normalize($name, $value);
      }
    }
    return $decl ? ':root{' . implode(';', $decl) . '}' : '';
  }

  /**
   * Merge + persist Foundations changes. Token values are validated per type
   * against the registry (DesignTokens); web fonts are set only when provided.
   * Returns a human-readable list of what changed.
   *
   * @return string[]
   */
  public function update(array $tokens = [], ?array $fonts = NULL, ?string $delivery = NULL): array {
    $config = $this->configFactory->getEditable(self::CONFIG);
    $applied = [];

    if ($delivery !== NULL && in_array($delivery, [self::DELIVERY_GOOGLE, self::DELIVERY_SELFHOST], TRUE)) {
      $config->set('font_delivery', $delivery);
      $applied[] = 'font delivery → ' . $delivery;
    }

    $current = $config->get('tokens') ?: [];
    foreach ($tokens as $key => $value) {
      // Preserve the value verbatim (oklch/var/rem must survive); only known,
      // valid tokens are stored.
      if (is_string($value) && $this->designTokens->validate($key, trim($value))) {
        $current[$key] = $this->designTokens->normalize($key, $value);
        $applied[] = $key . ' → ' . $current[$key];
      }
    }
    $config->set('tokens', $current);

    if ($fonts !== NULL) {
      $valid = array_values(array_filter(array_map('trim', $fonts), [self::class, 'isFontName']));
      $config->set('font_families', $valid);
      $applied[] = 'web fonts';
    }

    $config->save();
    return $applied;
  }

  public static function isHex(mixed $value): bool {
    return is_string($value) && (bool) preg_match('/^#[0-9a-fA-F]{6}$/', $value);
  }

  /** A safe Google-Font family name (letters/digits/spaces only). */
  public static function isFontName(mixed $value): bool {
    return is_string($value) && (bool) preg_match('/^[A-Za-z0-9 ]{1,50}$/', trim($value));
  }

}
