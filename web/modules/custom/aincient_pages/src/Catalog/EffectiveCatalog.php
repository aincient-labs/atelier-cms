<?php

declare(strict_types=1);

namespace Drupal\aincient_pages\Catalog;

use Drupal\aincient_pages\ComponentCatalog;

/**
 * The compiled, immutable component palette ONE page kind sees.
 *
 * `discovered ∩ site-constraint ∩ kind` (plans/byo-components.md decisions
 * #2/#3): the compiler ({@see CatalogCompiler}) resolves discovery, the
 * site-wide constraint layer and the kind's narrowing into this dumb value
 * object, so the nine consumers (validator, renderer, studio manifest, agent
 * prompt, linter…) never re-derive policy. Serializable (plain arrays only) so
 * the catalog service can cache one per kind.
 *
 * Each def: ['provider','tier','order','icon','use','props'] where props is
 * the ordered author-visible hint map (prop => '' bare | 'a|b' enum |
 * '[{…}]' repeatable shape) — the same notation the old ComponentCatalog
 * consts carried, so the manifest/signature output is byte-compatible.
 */
final class EffectiveCatalog {

  /**
   * @param string $kind
   *   The page kind id this palette was compiled for.
   * @param string $mode
   *   composition | recipe.
   * @param array<string, array> $sections
   *   Ordered section defs (name => def).
   * @param array<string, array> $layout
   *   Ordered placeable layout-container defs.
   * @param array<string, array> $reference
   *   Ordered reference-placeable defs.
   * @param array<string, array> $chrome
   *   Chrome component defs (site wrapper; never placeable).
   * @param array<string, array> $contentAtoms
   *   Content-atom defs (recipe/listing internals; never placeable).
   * @param string[] $tones
   *   The effective tone enum.
   * @param array<string, list<string>> $variants
   *   Per-component variant enums (first value = clamp default), derived from
   *   each def's `variant` prop hint after narrowing.
   * @param string|null $opener
   *   The kind's required first section, or NULL.
   * @param array<string, int> $limits
   *   Per-component placement limits.
   * @param bool $collectionSource
   *   Whether a collection may list pages of this kind.
   * @param string $hint
   *   The kind's one-line picker/agent hint.
   * @param string[] $warnings
   *   Never-fatal degradations collected at compile (unknown component in a
   *   kind, typo'd variant, missing opener…) — surfaced, never thrown.
   */
  public function __construct(
    private readonly string $kind,
    private readonly string $mode,
    private readonly array $sections,
    private readonly array $layout,
    private readonly array $reference,
    private readonly array $chrome,
    private readonly array $contentAtoms,
    private readonly array $tones,
    private readonly array $variants,
    private readonly ?string $opener,
    private readonly array $limits,
    private readonly bool $collectionSource,
    private readonly string $hint,
    private readonly array $warnings,
  ) {}

  /**
   * The page kind id this palette belongs to.
   */
  public function kind(): string {
    return $this->kind;
  }

  /**
   * composition | recipe.
   */
  public function mode(): string {
    return $this->mode;
  }

  /**
   * TRUE when pages of this kind compose a section stack.
   */
  public function isComposition(): bool {
    return $this->mode !== 'recipe';
  }

  /**
   * Ordered section defs (name => def).
   */
  public function sections(): array {
    return $this->sections;
  }

  /**
   * Ordered placeable layout-container defs (name => def).
   */
  public function layout(): array {
    return $this->layout;
  }

  /**
   * Ordered reference-placeable defs (name => def).
   */
  public function reference(): array {
    return $this->reference;
  }

  /**
   * The section allow-list (names only).
   */
  public function sectionNames(): array {
    return array_keys($this->sections);
  }

  /**
   * The placeable layout-container names.
   */
  public function layoutNames(): array {
    return array_keys($this->layout);
  }

  /**
   * The reference placeable names.
   */
  public function referenceNames(): array {
    return array_keys($this->reference);
  }

  /**
   * Every component the page agent may PLACE as a top-level block.
   */
  public function placeableNames(): array {
    return array_merge($this->sectionNames(), $this->layoutNames(), $this->referenceNames());
  }

  /**
   * The def for any placeable name, or NULL.
   */
  public function placeable(string $name): ?array {
    return $this->sections[$name] ?? $this->layout[$name] ?? $this->reference[$name] ?? NULL;
  }

  /**
   * The SDC plugin id for a placeable ("provider:name"), or NULL for a
   * virtual placeable (e.g. `block`, which the renderer expands itself).
   */
  public function pluginId(string $name): ?string {
    $def = $this->def($name);
    if ($def === NULL || ($def['provider'] ?? '') === '') {
      return NULL;
    }
    return $def['provider'] . ':' . $name;
  }

  /**
   * Chrome component names (site wrapper on every page).
   */
  public function chrome(): array {
    return array_keys($this->chrome);
  }

  /**
   * Content-atom names (composed inside recipes/listings, never placed).
   */
  public function contentAtoms(): array {
    return array_keys($this->contentAtoms);
  }

  /**
   * The def for ANY discovered component across all five tiers — what the
   * RENDERER resolves against (it also builds chrome and content atoms), where
   * {@see placeable()} is the author/agent-facing allow-list.
   */
  public function def(string $name): ?array {
    return $this->placeable($name) ?? $this->chrome[$name] ?? $this->contentAtoms[$name] ?? NULL;
  }

  /**
   * The effective tone enum.
   */
  public function tones(): array {
    return $this->tones;
  }

  /**
   * Per-component variant enums (first value = clamp default).
   */
  public function variants(): array {
    return $this->variants;
  }

  /**
   * The variant enum for one component, or NULL when it has none.
   */
  public function variantsFor(string $name): ?array {
    return $this->variants[$name] ?? NULL;
  }

  /**
   * The tone enum for one component — the kind's per-component subset when it
   * declares one, the effective site-wide enum otherwise.
   */
  public function tonesFor(string $name): array {
    return $this->placeable($name)['tones'] ?? $this->tones;
  }

  /**
   * The kind's required first section, or NULL.
   */
  public function opener(): ?string {
    return $this->opener;
  }

  /**
   * Per-component placement limits (name => max). Empty = unlimited.
   */
  public function limits(): array {
    return $this->limits;
  }

  /**
   * Whether a collection may list pages of this kind.
   */
  public function isCollectionSource(): bool {
    return $this->collectionSource;
  }

  /**
   * The kind's one-line picker/agent hint.
   */
  public function hint(): string {
    return $this->hint;
  }

  /**
   * Never-fatal compile degradations (decision #4) — for logs and the console.
   */
  public function warnings(): array {
    return $this->warnings;
  }

  /**
   * Every emitted identifier across all tiers — the uniqueness-lint set and
   * the reserved words the grammar must not collide with.
   */
  public function reservedNames(): array {
    return array_values(array_unique(array_merge(
      $this->sectionNames(),
      $this->referenceNames(),
      $this->chrome(),
      $this->contentAtoms(),
      ComponentCatalog::LAYOUT_RESERVED,
    )));
  }

  /**
   * The catalog-declared PACK stylesheets: unique (provider, path) pairs
   * across every tier, ordered by provider then path — the shell, the studio
   * preview and the static export link these AFTER ours, so a pack's
   * pre-compiled CSS reaches every render path with no code hook (W5). Our
   * own components declare none (their styles ride the module bundle).
   *
   * @return list<array{provider: string, path: string}>
   */
  public function stylesheets(): array {
    $seen = [];
    foreach ([$this->sections, $this->layout, $this->reference, $this->chrome, $this->contentAtoms] as $tier) {
      foreach ($tier as $def) {
        $provider = (string) ($def['provider'] ?? '');
        $path = (string) ($def['stylesheet'] ?? '');
        if ($provider === '' || $path === '') {
          continue;
        }
        $seen[$provider . ':' . $path] = ['provider' => $provider, 'path' => $path];
      }
    }
    ksort($seen);
    return array_values($seen);
  }

  /**
   * The studio icon glyph for a component, or NULL (the studio falls back).
   */
  public function icon(string $name): ?string {
    $icon = $this->placeable($name)['icon'] ?? '';
    return $icon === '' ? NULL : $icon;
  }

  /**
   * The AI-facing component listing for this kind's system prompt — same
   * contract as the old ComponentCatalog::manifest(): the menu only; grammar
   * and taste rules stay in the prompt.
   */
  public function manifest(): string {
    // A recipe kind has no placeable sections — section ops are inert on it —
    // so its prompt drops the whole menu (Phase 5: the blog turn stops paying
    // ~7k chars for a palette it cannot use).
    if (!$this->isComposition()) {
      return sprintf(
        '%s is a LOCKED RECIPE — a fixed layout with NO placeable sections (section ops are inert). Write it with set_meta {type,title} + set_content.',
        mb_strtoupper($this->kind),
      );
    }
    $lines = [sprintf('%s sections (compose 3–6, in this rough order):', mb_strtoupper($this->kind))];
    foreach ($this->sections as $name => $def) {
      $lines[] = sprintf('- %s — %s', $name, $def['use']);
      $lines[] = '    props: ' . $this->describeProps($def['props']);
      if (($fewShot = $this->fewShot($name, $def)) !== NULL) {
        $lines[] = $fewShot;
      }
    }
    $lines[] = '';
    $lines[] = 'LAYOUT containers (use only when no named section fits; never nest):';
    foreach ($this->layout as $name => $def) {
      $lines[] = sprintf('- %s — %s', $name, $def['use']);
      $lines[] = '    props: ' . $this->describeProps($def['props']);
      if (($fewShot = $this->fewShot($name, $def)) !== NULL) {
        $lines[] = $fewShot;
      }
    }
    $lines[] = '';
    $lines[] = 'REFERENCE placeables (point at real content instead of typing it):';
    foreach ($this->reference as $name => $def) {
      $lines[] = sprintf('- %s — %s', $name, $def['use']);
      $lines[] = '    props: ' . $this->describeProps($def['props']);
      if (($fewShot = $this->fewShot($name, $def)) !== NULL) {
        $lines[] = $fewShot;
      }
    }
    $lines[] = '';
    $lines[] = ComponentCatalog::anchorNote();
    $lines[] = '';
    // The link note stays the closing block (tests pin it as the manifest's end).
    $lines[] = ComponentCatalog::linkTargetNote();
    return implode("\n", $lines);
  }

  /**
   * The compact prop signature for one placeable ('' for an unknown name) —
   * used by the schema linter to tell the agent which props a component takes.
   */
  public function signature(string $name): string {
    $def = $this->placeable($name);
    return $def ? $this->describeProps($def['props']) : '';
  }

  /**
   * The few-shot fragment for a PACK component, or NULL (Phase 5).
   *
   * A client's `spotlight` carries no model prior the way our `hero` does, so
   * its first declared example (the gallery fixture) is inlined as a concrete
   * add_section shape. Built-ins (provider `aincient_pages`) and virtual defs
   * (provider '') stay bare — priors exist and the prompt budget stays flat.
   */
  private function fewShot(string $name, array $def): ?string {
    $provider = (string) ($def['provider'] ?? '');
    if ($provider === '' || $provider === 'aincient_pages') {
      return NULL;
    }
    $props = ($def['examples'] ?? [])[0]['props'] ?? NULL;
    if (!is_array($props) || $props === []) {
      return NULL;
    }
    $json = json_encode(['component' => $name, 'props' => $props], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return is_string($json) ? '    e.g. ' . $json : NULL;
  }

  /**
   * Format a def's props into the compact prompt notation: bare name, an enum
   * `tone(a|b|c)`, or a repeatable `items:[{…}]` shape.
   */
  private function describeProps(array $props): string {
    $out = [];
    foreach ($props as $prop => $hint) {
      if ($hint === '') {
        $out[] = $prop === 'tone' ? 'tone(' . implode('|', $this->tones) . ')' : $prop;
      }
      elseif (str_starts_with($hint, '[')) {
        $out[] = $prop . ':' . $hint;
      }
      else {
        $out[] = $prop . '(' . $hint . ')';
      }
    }
    return implode(', ', $out);
  }

}
