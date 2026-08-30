<?php

declare(strict_types=1);

namespace Drupal\aincient_flows\Plugin\FlowDropNodeProcessor;

use Drupal\aincient_pages\BrandRepository;
use Drupal\aincient_pages\ColorContrast;
use Drupal\aincient_pages\SiteIdentity;
use Drupal\aincient_pages\TokenGrounding;
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
 *  - `shape_brief` / `type_brief` — the same for the axes the SHAPE and
 *    TYPOGRAPHY specialists own (corners, border weight, shadow axes, density;
 *    families, size, leading, weight, tracking). `brand_brief` covers only the
 *    palette and the typefaces, so those two specialists used to receive a
 *    "CURRENT LOOK" with nothing in it about the axis they were being asked to
 *    move, and a relative request ("rounder", "heavier") had to be guessed at.
 *
 * Every value in all three briefs is rendered through
 * {@see \Drupal\aincient_pages\TokenGrounding}: roughly half the registry's
 * tokens hold a `var()` reference, and a reference is not a value a model can
 * step from (DECISIONS 0408).
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
    private readonly ColorContrast $contrast,
    private readonly TokenGrounding $grounding,
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
      $container->get('aincient_pages.color_contrast'),
      $container->get('aincient_pages.token_grounding'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function process(ParameterBagInterface $params): array {
    $status = $this->brand->status();
    $effective = !empty($status['locked']) ? 'locked' : (string) $status['stage'];

    $incoming = $params->getArray('variables', []);
    // Which tokens the open draft overrides (ChatController::brandVariables), so
    // the saved brief can mark its own entry for them superseded rather than
    // state a second, competing "current" value for the same token.
    $drafted = array_filter(array_map(
      'trim',
      explode(',', (string) ($incoming['draft_tokens'] ?? '')),
    ));

    $directive = $this->directive($effective);
    $brief = $this->brief($drafted);
    // Per-axis baselines. `brand_brief` carries the palette and the typefaces,
    // which is all the COLOUR specialist needs — but the shape and typography
    // specialists own axes it never mentioned, so a relative request ("rounder",
    // "heavier") reaching them had nothing to step from and the model invented a
    // value. Same failure DECISIONS 0236 fixed for colour, on the other two axes.
    $shape = $this->axisBrief(self::SHAPE_AXIS);
    $type = $this->axisBrief(self::TYPE_AXIS);

    // Carry through the incoming template variables (the studio's
    // live_preview_state draft when present) and layer the two status-derived
    // variables on top — the shared orchestrator template renders all three.
    // `+` preserves any incoming keys; ours never collide.
    $variables = $incoming + array_filter([
      'stage_directive' => $directive,
      'brand_brief' => $brief,
      'brand_status' => $effective,
      'shape_brief' => $shape,
      'type_brief' => $type,
    ], static fn (string $v) => $v !== '');

    return [
      'variables' => $variables,
      'effective_mode' => $effective,
      'status_directive' => $directive,
      'brand_brief' => $brief,
      'shape_brief' => $shape,
      'type_brief' => $type,
    ];
  }

  /**
   * What "surgical" does and does NOT constrain — appended to the two restrictive
   * modes (locked, polish).
   *
   * Both carve-outs are things the restrictive wording got wrong in practice, on
   * a Locked brand asked to "make primary darker" over a pale yellow draft:
   *
   * 1. The orchestrator read "touch ONLY the token(s) named" as forbidding the
   *    paired on-colour, and framed the specialist's ask as "…or any other
   *    token". The pair went stale and the edit landed a 2.94:1 WCAG Fail that
   *    the reply then described as "nothing else touched". An on-colour is not
   *    an independent axis — it is the contrast partner declared by `on:` in
   *    design-tokens.yml and graded by ColorContrast::pairReport(). Re-deriving
   *    it IS the surgical edit, not a second one. Locked must be stricter about
   *    accessibility than Ideating, never looser.
   * 2. The orchestrator generalised "don't change other TOKENS" into "don't
   *    change the other AXES of this token" and asked for "lower lightness only,
   *    don't change hue/chroma". In oklch those axes are coupled: a near-white
   *    tint carries a low chroma BECAUSE it is near-white, so darkening it with
   *    chroma pinned yields mud — oklch(0.973 0.071 103) became
   *    oklch(0.65 0.071 103), a khaki #97915E from a yellow. Hue is the axis that
   *    carries identity; chroma has to follow lightness to preserve it.
   *
   * @see https://github.com/aincient-labs/cms/issues/41
   * @see https://github.com/aincient-labs/cms/issues/42
   */
  private const SURGICAL_MEANS = "\n"
    . "What SURGICAL constrains — and what it does not:\n"
    . "- It limits WHICH TOKENS you touch. It does NOT freeze the other axes of the token you are "
    . "changing. For a relative colour change, hold the HUE — that is what carries the colour's "
    . "identity — and let chroma follow lightness. A pale tint's low chroma is only meaningful at "
    . "high lightness; carrying it down unchanged produces a muddy, desaturated colour nobody chose. "
    . "So never frame a specialist's ask as \"lower lightness only\" or \"don't change chroma\". "
    . "And when the token sits on a Tailwind swatch (CURRENT LOOK prints its ramp), the specialist "
    . "steps along that ramp and writes the swatch back — do not ask it to hold the hue.\n"
    . "- PASS THE USER'S OWN WORDS THROUGH. Write the ask as the user said it — \"make brand_primary "
    . "darker\", \"make brand_primary blue\" — plus the scope. Do NOT translate \"darker\" into "
    . "\"step down its ramp\" (the specialist then moves one invisible rung) and do NOT translate "
    . "\"blue\" into \"a blue hue\" (the specialist then hand-computes an oklch() literal instead of "
    . "landing on the blue-600 swatch, and its pair fails AA). The specialist owns HOW; the ask "
    . "carries WHAT and the scope.\n"
    . "- A surface's PAIRED ON-COLOUR travels with it. Changing a surface colour and re-deriving its "
    . "on-colour to keep the pair ≥4.5:1 (WCAG AA) is ONE surgical edit, not a sweep — the pair is a "
    . "single decision. Never tell a specialist it may not touch the on-colour of the token it is "
    . "changing, and never leave a pair failing AA because the brand is locked. If a change would "
    . "break a pair you cannot fix within scope, say so plainly instead of shipping the failure.";

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
        . "can unlock it in the studio to make sweeping changes."
        . self::SURGICAL_MEANS,
      BrandRepository::STAGE_POLISH => "BRAND STATUS — POLISH. The look is nearly settled. Make MINIMAL, "
        . "surgical changes: touch only the exact token(s) the user names, do NOT auto-complete or "
        . "re-sweep the palette, and never let a single-axis request (e.g. a background/surface tweak) "
        . "drift into other axes like brand_primary/brand_accent unless the user explicitly asks about them."
        . self::SURGICAL_MEANS,
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
   *
   * @param string[] $drafted
   *   css_var names the open studio draft overrides. Their saved value is still
   *   shown — the brand's identity is worth knowing — but marked superseded, so
   *   the prompt never asserts two different "current" values for one token.
   */
  private function brief(array $drafted = []): string {
    $parts = [];

    $identity = trim($this->identity->promptBrief());
    if ($identity !== '') {
      $parts[] = $identity;
    }

    $palette = [];
    // The saved overrides, so a token whose value is a var() reference resolves
    // against the saved brand rather than the registry defaults.
    $saved = $this->brand->tokens();
    foreach (['brand_primary' => 'primary', 'brand_accent' => 'accent', 'neutral_surface' => 'surface', 'neutral_ink' => 'ink'] as $key => $label) {
      // Effective value = saved override if set, else the registry default — the
      // same resolution the studio swatch (BrandController) and the visual brief
      // already use. Reading raw tokens() here omitted primary/accent whenever
      // they sat at their default (the shipped config overrides only neutral_*),
      // so a relative request like "make primary lighter" had NO current primary
      // to anchor on and the model fabricated one — a lighter blue off the only
      // hue in the brief (neutral_ink) instead of a lighter Cinnabar (0236).
      $value = $this->brand->effectiveValue($key);
      if ($value !== '') {
        // Grounding echo: the model reads a tint in hex far better than in
        // oklch(), and a var() reference carries no colour at all until it is
        // followed. Same renderer as the live-draft lines (ChatController::
        // brandContext) and the two axis briefs, so none of them can drift.
        $entry = "$label $value" . $this->grounding->echoFor($key, $value, $saved);
        // A token the open draft overrides has TWO values in this prompt. Say
        // which one is stale, at the value itself — a header further up saying
        // the draft "wins" is a claim the model has to remember and apply,
        // where this is impossible to read past.
        $palette[] = in_array($this->grounding->cssVarFor($key), $drafted, TRUE)
          ? $entry . ' [SUPERSEDED — the preview edit below is the live value]'
          : $entry;
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
   * The SHAPE specialist's dials, in the vocabulary its own prompt uses:
   * corner scale, per-component corners, border weight, the shadow axes, and
   * density. Token name => the label the brief prints.
   */
  private const SHAPE_AXIS = [
    // The WHOLE radius scale, not one representative rung. The specialist's own
    // prompt invites it to reference a rung ("a radius reference like
    // var(--radius-lg)"), so it has to know what each rung is worth — on a
    // deliberately square brand every rung is 0px, and "make the corners
    // rounder" answered with var(--radius-sm) is a silent no-op the agent then
    // reports as done.
    'radius_sm' => 'radius scale: sm',
    'radius_md' => 'md',
    'radius_lg' => 'lg',
    'radius_xl' => 'xl',
    'radius_2xl' => '2xl',
    'radius_full' => 'full',
    'card_radius' => 'card corners',
    'button_radius' => 'button corners',
    'input_radius' => 'input corners',
    'border_width' => 'border width',
    'shadow_distance' => 'shadow distance',
    'shadow_blur' => 'shadow blur',
    'shadow_strength' => 'shadow strength',
    'shadow_color' => 'shadow colour',
    'density' => 'density',
  ];

  /**
   * The TYPOGRAPHY specialist's dials: the two families plus the size, leading,
   * weight and tracking scale it is asked to move one step at a time.
   */
  private const TYPE_AXIS = [
    'font_family_display' => 'display family',
    'font_family_base' => 'body family',
    'body_size' => 'body size',
    'body_leading' => 'body leading',
    'display_weight' => 'display weight',
    'heading_weight' => 'heading weight',
    'heading_tracking' => 'heading tracking',
  ];

  /**
   * One axis of the saved brand, grounded — or '' when nothing resolves.
   *
   * Every value goes through {@see TokenGrounding} for the same reason the
   * palette line does: half of these tokens hold a `var()` reference by default
   * (`card_radius` is `var(--radius-2xl)`), and a reference is not a value a
   * model can step from.
   *
   * @param array<string, string> $axis
   *   Token name => label.
   */
  private function axisBrief(array $axis): string {
    $saved = $this->brand->tokens();
    $parts = [];
    foreach ($axis as $name => $label) {
      $value = $this->brand->effectiveValue($name);
      if ($value !== '') {
        $parts[] = $label . ' ' . $this->grounding->describe($name, $value, $saved);
      }
    }
    // Semicolons, not commas: a font stack and a shadow layer both contain
    // commas of their own.
    return $parts === [] ? '' : implode('; ', $parts) . '.';
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
        'shape_brief' => [
          'type' => 'string',
          'description' => "The saved SHAPE baseline (corners, border weight, shadow axes, density) — what the shape specialist steps a relative request from. Empty when nothing resolves.",
        ],
        'type_brief' => [
          'type' => 'string',
          'description' => "The saved TYPOGRAPHY baseline (families, size, leading, weight, tracking) — what the typography specialist steps a relative request from. Empty when nothing resolves.",
        ],
      ],
    ];
  }

}
