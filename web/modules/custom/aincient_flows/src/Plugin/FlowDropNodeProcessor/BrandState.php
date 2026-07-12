<?php

declare(strict_types=1);

namespace Drupal\aincient_flows\Plugin\FlowDropNodeProcessor;

use Drupal\aincient_pages\BrandRepository;
use Drupal\aincient_pages\SiteIdentity;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\flowdrop\Attribute\FlowDropNodeProcessor;
use Drupal\flowdrop\DTO\ParameterBagInterface;
use Drupal\flowdrop\DTO\ValidationResult;
use Drupal\flowdrop\Plugin\FlowDropNodeProcessor\AbstractFlowDropNodeProcessor;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Reads the saved brand design-intent status at RUNTIME and shapes the prompt.
 *
 * The Brand orchestrator's turn behaviour must track the brand's design-intent
 * *stage* (ideating → guided → polish, plus a `locked` overlay — see
 * {@see \Drupal\aincient_pages\BrandRepository::status()}). Rather than plumb a
 * per-turn client flag through adapter.ts/ChatController, this node reads the
 * status server-side from persisted config every turn — so it's authoritative,
 * works for non-chat triggers, and can't drift from what the studio shows.
 *
 * It sits as a pure DATA node between the workflow's `variables` input and the
 * orchestrator's system-prompt {@see prompt_template}: the external variables
 * (the studio's `live_preview_state` draft, when present) flow IN; the node
 * emits them back OUT enriched with two extra template variables the shared
 * template renders:
 *  - `stage_directive` — the rendered behaviour fragment for the effective mode
 *    (`locked ? 'locked' : stage`). Polish/locked = minimal, single-axis, never
 *    auto-complete the palette — the structural guard against the colour-drift
 *    class of bug.
 *  - `brand_brief` — a compact summary of the SAVED brand (identity + palette +
 *    fonts) so the agent knows the current brand without being told. (This
 *    incidentally closes the separate "brand agent can't read current brand
 *    state" gap.)
 *
 * The effective mode is also exposed as its own `effective_mode` port for a
 * future switch/gateway or analytics; the directive selection itself is a
 * deterministic PHP `match()` here (no switch_gateway needed — the reason node
 * takes one systemPrompt from one shared template, and routing the *directive
 * text* through a control-flow gateway would only re-duplicate what this
 * computes).
 *
 * @see \Drupal\aincient_pages\BrandRepository::status()
 * @see \Drupal\aincient_pages\SiteIdentity::promptBrief()
 */
#[FlowDropNodeProcessor(
  id: "brand_state",
  label: new TranslatableMarkup("Brand state"),
  description: "Read the saved brand design-intent status at runtime and emit the stage directive + saved-brand brief into the prompt.",
  version: "0.1.0",
)]
class BrandState extends AbstractFlowDropNodeProcessor {

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    private readonly BrandRepository $brand,
    private readonly SiteIdentity $identity,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('aincient_pages.brand'),
      $container->get('aincient_pages.site_identity'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function process(ParameterBagInterface $params): array {
    $status = $this->brand->status();
    $effective = !empty($status['locked']) ? 'locked' : (string) $status['stage'];

    $directive = $this->directive($effective);
    $brief = $this->brief();

    // Carry through the incoming template variables (the studio's
    // live_preview_state draft when present) and layer the two status-derived
    // variables on top — the shared orchestrator template renders all three.
    // `+` preserves any incoming keys; ours never collide.
    $incoming = $params->getArray('variables', []);
    $variables = $incoming + [
      'stage_directive' => $directive,
      'brand_brief' => $brief,
      'brand_status' => $effective,
    ];

    return [
      'variables' => $variables,
      'effective_mode' => $effective,
      'status_directive' => $directive,
      'brand_brief' => $brief,
    ];
  }

  /**
   * The behaviour fragment for the effective mode (locked | stage).
   */
  private function directive(string $mode): string {
    return match ($mode) {
      'locked' => "BRAND STATUS — LOCKED. The brand is settled and must not change sweepingly. "
        . "Treat every request as minimal and surgical: touch ONLY the exact token(s) the user "
        . "names, never re-sweep or auto-complete the palette, and never let a single-axis request "
        . "(e.g. a background change) drift into brand_primary/brand_accent. If the user asks for a "
        . "broad restyle or a new theme/mood, don't do it — tell them the brand is locked and they "
        . "can unlock it in the studio to make sweeping changes.",
      BrandRepository::STAGE_POLISH => "BRAND STATUS — POLISH. The look is nearly settled. Make MINIMAL, "
        . "surgical changes: touch only the exact token(s) the user names, do NOT auto-complete or "
        . "re-sweep the palette, and never let a single-axis request (e.g. a background/surface tweak) "
        . "drift into other axes like brand_primary/brand_accent unless the user explicitly asks about them.",
      BrandRepository::STAGE_GUIDED => "BRAND STATUS — GUIDED. A direction is set. Honour the inputs the "
        . "user supplies (their palette, references, chosen presets); do NOT invent new directions or "
        . "introduce unrequested colours. Change only what is asked, in the direction already established.",
      // ideating (and any unexpected value → the permissive default).
      default => "BRAND STATUS — IDEATING. The user is exploring and the brand is free to diverge. A named "
        . "theme or mood may sweep the whole palette; follow the user's lead and offer bold, coherent looks.",
    };
  }

  /**
   * A compact brief of the SAVED brand: identity + key palette + fonts (or '').
   */
  private function brief(): string {
    $parts = [];

    $identity = trim($this->identity->promptBrief());
    if ($identity !== '') {
      $parts[] = $identity;
    }

    $tokens = $this->brand->tokens();
    $palette = [];
    foreach (['brand_primary' => 'primary', 'brand_accent' => 'accent', 'neutral_surface' => 'surface', 'neutral_ink' => 'ink'] as $key => $label) {
      $value = trim((string) ($tokens[$key] ?? ''));
      if ($value !== '') {
        $palette[] = "$label $value";
      }
    }
    if ($palette !== []) {
      $parts[] = 'Current saved palette: ' . implode(', ', $palette) . '.';
    }

    $fonts = array_values(array_filter(array_map('trim', $this->brand->fontFamilies())));
    if ($fonts !== []) {
      $parts[] = 'Fonts: ' . implode(', ', $fonts) . '.';
    }

    return implode("\n", $parts);
  }

  /**
   * {@inheritdoc}
   */
  public function validateParams(array $params): ValidationResult {
    return ValidationResult::success();
  }

  /**
   * {@inheritdoc}
   */
  public function getParameterSchema(): array {
    return [
      'type' => 'object',
      'properties' => [
        'variables' => [
          'type' => 'object',
          'title' => 'Variables',
          'description' => "The incoming system-prompt template variables (e.g. the studio's live_preview_state draft). Wire the workflow's variables input here; the node returns it enriched with stage_directive + brand_brief.",
          'default' => [],
          'required' => FALSE,
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getOutputSchema(): array {
    return [
      'type' => 'object',
      'properties' => [
        'variables' => [
          'type' => 'object',
          'description' => 'The incoming template variables enriched with stage_directive, brand_brief and brand_status. Wire this into the system-prompt PromptTemplate node.',
        ],
        'effective_mode' => [
          'type' => 'string',
          'description' => "The effective design-intent mode: `locked` when locked, else the stage (ideating|guided|polish).",
        ],
        'status_directive' => [
          'type' => 'string',
          'description' => 'The rendered behaviour directive fragment for the effective mode.',
        ],
        'brand_brief' => [
          'type' => 'string',
          'description' => 'A compact summary of the saved brand (identity + palette + fonts), or empty.',
        ],
      ],
    ];
  }

}
