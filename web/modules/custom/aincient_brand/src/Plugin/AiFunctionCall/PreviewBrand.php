<?php

declare(strict_types=1);

namespace Drupal\aincient_brand\Plugin\AiFunctionCall;

use Drupal\aincient_pages\BrandPreviewApplier;
use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * AIncient Command: preview brand design tokens in the user's LIVE preview.
 *
 * The branding agent's ONLY edit tool — and it writes NOTHING to the live site.
 * Brand direction can only ever be changed server-side through the Brand studio
 * (there is no "set brand" tool); the agent's job is to drive that studio's live
 * preview. This emits a small declarative brand DSL as a generative-UI widget
 * envelope (`{"__widget__": "brand_preview", "payload": …}`). The dispatcher
 * harvests it out of the agent's tool results; the `brand_preview` widget applies
 * the tokens to the SAME unsaved-draft store the brand-studio sliders write, so
 * the user's live preview reskins instantly and the change shows as an unsaved
 * edit in the studio. The one deliberate global write stays the studio's Publish
 * button — so the agent can iterate freely ("make it neon" → "now a hotter
 * accent") without ever touching the running site.
 *
 * Token values are validated per type by {@see DesignTokens::validate} (the same
 * registry gate the studio's Publish endpoint uses), so only tier-legal CSS
 * reaches the client. The token names + types are listed in the agent's prompt.
 *
 * @see \Drupal\aincient_brand\Plugin\AiFunctionCall\BrandPicker
 * @see \Drupal\aincient_pages\Controller\BrandController::save()
 */
#[FunctionCall(
  id: 'aincient_brand:preview_brand',
  function_name: 'aincient_preview_brand',
  name: 'Preview brand',
  description: 'Update the user\'s LIVE brand preview by writing design tokens (colours, typography, spacing, radius, shadow, per-component knobs). This applies INSTANTLY to the preview the user is watching and stages it as an unsaved draft — it does NOT publish to the live site (the user does that with the Publish button). Use this for every look/feel change so the user sees it happen. Token names + accepted value types are listed in the system prompt; values may be any valid CSS of the token type (oklch()/hex, rem lengths, var(--other-token)). Set reset=true to clear the draft back to the saved brand.',
  context_definitions: [
    'presets_json' => new ContextDefinition(data_type: 'string', label: new TranslatableMarkup('Presets'), description: new TranslatableMarkup('A JSON object choosing high-level PRESETS by group, e.g. {"pairing":"editorial","roundness":"soft","direction":"bottom"}. PREFER these over raw tokens — each expands to a coherent, contrast-safe token set. Groups + their option ids are listed in the system prompt. Drop to tokens_json only for fine control a preset cannot express.'), required: FALSE),
    'tokens_json' => new ContextDefinition(data_type: 'string', label: new TranslatableMarkup('Design tokens'), description: new TranslatableMarkup('A JSON object mapping token names to CSS values, e.g. {"neutral_surface":"oklch(0.15 0.02 270)","brand_primary":"oklch(0.75 0.25 180)"}. Token names + types are listed in the system prompt. Layered ON TOP of any presets_json (an explicit token wins over a preset that also sets it).'), required: FALSE),
    'fonts' => new ContextDefinition(data_type: 'string', label: new TranslatableMarkup('Web fonts'), description: new TranslatableMarkup('Comma-separated Google Font family names to LOAD in the preview, e.g. "Poppins, DM Sans". Reference a loaded font in the font_family_base/font_family_display token.'), required: FALSE),
    'reset' => new ContextDefinition(data_type: 'boolean', label: new TranslatableMarkup('Reset'), description: new TranslatableMarkup('Clear the whole preview draft back to the saved brand.'), required: FALSE),
  ],
)]
final class PreviewBrand extends FunctionCallBase implements ExecutableFunctionCallInterface {

  /**
   * The shared brand-preview applier (all the validate/contrast/envelope work).
   */
  protected BrandPreviewApplier $applier;

  /**
   * The current user.
   */
  protected AccountInterface $currentUser;

  /**
   * The readable output (the widget envelope, or an error).
   */
  protected string $result = '';

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->applier = $container->get('aincient_pages.preview_applier');
    $instance->currentUser = $container->get('current_user');
    return $instance;
  }

  /**
   * {@inheritdoc}
   *
   * A thin permission-gated wrapper around {@see BrandPreviewApplier::apply()}:
   * gather the raw context values, hand them to the shared applier, and encode
   * its envelope (or surface its error) as the readable output the model reads.
   */
  public function execute(): void {
    if (!$this->currentUser->hasPermission('administer aincient pages')) {
      $this->result = 'Error: you do not have permission to change the site brand.';
      return;
    }

    $envelope = $this->applier->apply([
      'presets_json' => $this->getContextValue('presets_json'),
      'tokens_json' => $this->getContextValue('tokens_json'),
      'fonts' => $this->getContextValue('fonts'),
      'reset' => $this->getContextValue('reset'),
    ]);

    $this->result = isset($envelope['error'])
      ? (string) $envelope['error']
      : (string) json_encode($envelope);
  }

  /**
   * {@inheritdoc}
   */
  public function getReadableOutput(): string {
    return $this->result;
  }

}
