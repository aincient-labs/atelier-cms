<?php

declare(strict_types=1);

namespace Drupal\aincient_pages\Twig;

use Drupal\aincient_pages\Catalog\ComponentCatalogInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * The `component_catalog()` Twig function — the live page-composition palette.
 *
 * Emits the compiled EffectiveCatalog manifest: the single source of truth for the
 * page agent's component menu (sections, layout containers, reference
 * placeables and their props). A page-agent Prompt Template node calls
 * `{{ component_catalog() }}` so the manifest is inlined into the system prompt
 * at render time — the prompt can never list a component the renderer/validator
 * no longer accept, and always lists a newly added one.
 *
 * This replaces the old approach where the manifest was substituted inside the
 * (now retired) hand-rolled `aincient_flows:reason` node: with that node offloaded
 * to FlowDrop's provider-neutral native `reason`, the substitution moves upstream
 * into the Twig render that already builds the prompt. The Prompt Template node
 * renders through Drupal's `twig` service, so this tagged `twig.extension` is
 * available to it.
 *
 * `is_safe: html` marks the return unescaped: the manifest is inlined VERBATIM
 * into an LLM system prompt (never emitted as page HTML), so Twig's default
 * autoescape must not mangle it — and there is no XSS surface. This matches the
 * raw, unescaped substitution the old node performed.
 */
final class ComponentCatalogExtension extends AbstractExtension {

  public function __construct(
    private readonly ComponentCatalogInterface $catalog,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFunctions(): array {
    return [
      new TwigFunction('component_catalog', [$this, 'manifest'], ['is_safe' => ['html']]),
      new TwigFunction('page_kinds', [$this, 'kinds'], ['is_safe' => ['html']]),
    ];
  }

  /**
   * The component-catalogue manifest text — the palette the given kind sees.
   *
   * Phase 5: the Prompt Template passes the LIVE draft's kind
   * (`component_catalog(page_kind|default('landing'))`), so a blog prompt
   * stops carrying landing sections and a client kind ships only its own
   * palette. 'landing' stays the bare-call default for a fresh page.
   */
  public function manifest(string $kind = 'landing'): string {
    return $this->catalog->for($kind)->manifest();
  }

  /**
   * The PAGE KINDS block for the system prompt — generated from the kind
   * registry (Phase 5), so the agent stops believing exactly two kinds exist.
   *
   * One line per kind: id, label, regime prose derived from `mode`
   * (composition = section ops; recipe = locked layout, set_content), and the
   * kind's own hint.
   */
  public function kinds(): string {
    $lines = ["PAGE KINDS (a page's `type` — pick one with set_meta {type} at creation; FIXED once saved):"];
    foreach ($this->catalog->kinds() as $id => $kind) {
      $regime = $kind['mode'] === 'recipe'
        ? 'a LOCKED recipe — fixed layout, NO sections; write it with set_content (section ops are inert)'
        : 'open composition — build it from the section list with add_section/update_section ops';
      $hint = trim($kind['hint']);
      $lines[] = sprintf('- %s (%s) — %s.%s', $id, $kind['label'], $regime, $hint === '' ? '' : ' ' . rtrim($hint, '.') . '.');
    }
    return implode("\n", $lines);
  }

}
