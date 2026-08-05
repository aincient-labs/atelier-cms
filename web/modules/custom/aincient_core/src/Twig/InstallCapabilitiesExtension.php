<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Twig;

use Drupal\aincient_core\InstallCapabilities;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * The `install_capabilities()` Twig function — this install's verbs, for a prompt.
 *
 * The agent-facing half of {@see InstallCapabilities}: an agent's Prompt Template
 * node calls it and gets the same three booleans the console's capability chips
 * render, spelled as instructions. Available to every agent prompt; add the call
 * where an agent could otherwise offer something this install cannot do.
 *
 * `is_safe: html` for the same reason as `component_catalog()` and
 * `site_destinations()`: the text is inlined verbatim into an LLM system prompt,
 * never emitted as page HTML, so Twig's autoescape must not mangle it.
 */
final class InstallCapabilitiesExtension extends AbstractExtension {

  public function __construct(
    private readonly InstallCapabilities $capabilities,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFunctions(): array {
    return [
      new TwigFunction('install_capabilities', [$this, 'capabilities'], ['is_safe' => ['html']]),
    ];
  }

  /**
   * The generated capability block.
   */
  public function capabilities(): string {
    return $this->capabilities->promptLine();
  }

}
