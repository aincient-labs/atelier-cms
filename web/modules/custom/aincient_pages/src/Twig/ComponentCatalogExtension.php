<?php

declare(strict_types=1);

namespace Drupal\aincient_pages\Twig;

use Drupal\aincient_pages\ComponentCatalog;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * The `component_catalog()` Twig function — the live page-composition palette.
 *
 * Emits {@see ComponentCatalog::manifest()}: the single source of truth for the
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

  /**
   * {@inheritdoc}
   */
  public function getFunctions(): array {
    return [
      new TwigFunction('component_catalog', [$this, 'manifest'], ['is_safe' => ['html']]),
    ];
  }

  /**
   * The component-catalogue manifest text.
   */
  public function manifest(): string {
    return ComponentCatalog::manifest();
  }

}
