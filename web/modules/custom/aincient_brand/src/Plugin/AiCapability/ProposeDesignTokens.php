<?php

declare(strict_types=1);

namespace Drupal\aincient_brand\Plugin\AiCapability;

use Drupal\aincient_pages\BrandPreviewApplier;
use Drupal\aincient_core\Attribute\Capability;
use Drupal\aincient_core\Capability\CapabilityBase;
use Drupal\aincient_core\Capability\ExecutableCapabilityInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * AIncient Command: propose design tokens extracted from an attached file.
 *
 * The admission tool for a Tier A design document (DECISIONS 0350). When the
 * operator attaches a `design.md` (or similar) the turn preparer folds its text
 * into the turn as fenced DATA; the design agent reads that text, maps it to the
 * brand's design tokens, and calls THIS to surface a confirm card — "N tokens
 * found — [Preview] [Apply to brand]" — instead of applying anything itself.
 *
 * Proposal-ONLY, and deliberately so (DECISIONS 0368): a design file's content
 * is inherently instruction-shaped, so nothing here may take an autonomous
 * action. The card's ONLY action is Preview, which drives the studio's unsaved
 * draft; there is no Apply/Publish on the card, because the site has exactly one
 * global write — the studio's Publish button — and this must not become a second
 * (DECISIONS 0369). The agent never writes the brand (there is no set-brand
 * tool). This keeps the capability reversible + internal, i.e. taint-safe, which
 * is why the deferred attachment tool-gate stays deferred (see
 * CapabilityRosterTaintGuardTest).
 *
 * Validation/normalization/contrast all reuse {@see BrandPreviewApplier} — the
 * SAME registry gate `preview_brand` and the studio's Publish endpoint use — so
 * only tier-legal tokens reach the client and the count is honest. This differs
 * from `preview_brand` only in the widget it emits: a `design_token_admission`
 * card framed around the source file, not a bare `brand_preview`.
 *
 * @see \Drupal\aincient_brand\Plugin\AiCapability\PreviewBrand
 * @see \Drupal\aincient_pages\BrandPreviewApplier
 */
#[Capability(
  id: 'aincient_brand:propose_design_tokens',
  function_name: 'aincient_propose_design_tokens',
  name: 'Propose design tokens',
  description: 'Propose design tokens you extracted from an attached design file (e.g. design.md), surfaced to the user as a confirm card ("N tokens found") with a Preview button. Use this — NOT preview_brand — when the tokens come from a file the operator attached this turn. It applies NOTHING and there is no publish button on the card: Preview lets the user see the tokens live as the studio draft, and the user then Publishes from the studio (the one global write). Pass tokens_json (a JSON object of {token_name: css_value}) and/or presets_json, plus the source filename. Token names + accepted value types are listed in the system prompt.',
  context_definitions: [
    'tokens_json' => new ContextDefinition(data_type: 'string', label: new TranslatableMarkup('Design tokens'), description: new TranslatableMarkup('A JSON object mapping token names to CSS values, e.g. {"brand_primary":"oklch(0.75 0.25 180)","radius_md":"0.5rem"}, mapped from the attached file. Token names + types are listed in the system prompt.'), required: FALSE),
    'presets_json' => new ContextDefinition(data_type: 'string', label: new TranslatableMarkup('Presets'), description: new TranslatableMarkup('An optional JSON object choosing high-level PRESETS by group, e.g. {"pairing":"editorial","roundness":"soft"}. Layered UNDER explicit tokens.'), required: FALSE),
    'fonts' => new ContextDefinition(data_type: 'string', label: new TranslatableMarkup('Web fonts'), description: new TranslatableMarkup('Comma-separated Google Font family names named in the file, e.g. "Poppins, DM Sans".'), required: FALSE),
    'source_filename' => new ContextDefinition(data_type: 'string', label: new TranslatableMarkup('Source file'), description: new TranslatableMarkup('The name of the attached file these tokens came from, e.g. "design.md", for the confirm card.'), required: FALSE),
  ],
)]
final class ProposeDesignTokens extends CapabilityBase implements ExecutableCapabilityInterface {

  /**
   * The shared brand-preview applier (validate/contrast/envelope work).
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
   * Validate the proposed tokens with the shared applier, then re-frame its
   * result as a `design_token_admission` card around the source file. On a hard
   * input error the applier's prose is surfaced to the model unchanged.
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
      'reset' => FALSE,
    ]);

    if (isset($envelope['error'])) {
      $this->result = (string) $envelope['error'];
      return;
    }

    $payload = $envelope['payload'];
    $count = count($payload['tokens'] ?? []) + count($payload['fonts'] ?? []);
    $filename = trim((string) $this->getContextValue('source_filename'));
    $payload['source_filename'] = $filename !== '' ? $filename : 'the attached file';
    $payload['count'] = $count;

    $summary = 'Design token file — ' . $count . ' token' . ($count === 1 ? '' : 's')
      . ' found. Preview to see them live in the studio, then Publish there to save.';
    if (!empty($payload['rejected'])) {
      $summary .= ' (Skipped invalid: ' . implode(', ', $payload['rejected']) . '.)';
    }

    $this->result = (string) json_encode([
      '__widget__' => 'design_token_admission',
      'payload' => $payload,
      'summary' => $summary,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getReadableOutput(): string {
    return $this->result;
  }

}
