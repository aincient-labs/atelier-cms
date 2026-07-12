<?php

declare(strict_types=1);

namespace Drupal\aincient_brand\Plugin\AiFunctionCall;

use Drupal\aincient_pages\BrandRepository;
use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * AIncient Command: PROPOSE a brand design-intent status change (HITL).
 *
 * The brand's design-intent status (`stage` + `locked`) governs how much freedom
 * the agent has — read at runtime by the `brand_state` node. The agent NEVER
 * writes it directly (the product invariant: "AI proposes, you approve", the
 * same seam brand tokens go through). This tool does NOT persist: it emits a
 * `{"__widget__": "brand_status_proposal", "payload": …}` envelope the dispatcher
 * harvests into a confirm/decline card. Only the human's Confirm click POSTs to
 * /aincient/brand/status — the identical endpoint the studio's manual control
 * uses — so status changes always route through one human-gated write path.
 *
 * When to propose (heuristics also stated in the orchestrator prompt): an intent
 * shift toward honouring pinned/supplied inputs → propose `guided`; a brand that
 * is settling / repeated micro-tweaks → propose `polish` and/or `locked`.
 *
 * @see \Drupal\aincient_pages\Controller\BrandController::setStatus()
 * @see \Drupal\aincient_flows\Plugin\FlowDropNodeProcessor\BrandState
 * @see \Drupal\aincient_brand\Plugin\AiFunctionCall\PreviewBrand
 */
#[FunctionCall(
  id: 'aincient_brand:propose_brand_status',
  function_name: 'aincient_propose_brand_status',
  name: 'Propose brand status',
  description: 'PROPOSE a change to the brand design-intent status (the stage the agent works at, and whether the brand is locked). This does NOT change anything — it shows the user a confirm/decline card, and only they can apply it. Use it when the working mode should shift: propose "guided" when the user wants you to honour specific supplied inputs rather than explore; propose "polish" (optionally locked) when the brand is settling and only small, surgical tweaks remain. Always give a short, plain-language rationale. Do not call this every turn — only when the mode genuinely no longer fits.',
  context_definitions: [
    'stage' => new ContextDefinition(data_type: 'string', label: new TranslatableMarkup('Stage'), description: new TranslatableMarkup('The proposed stage: "ideating" (explore freely), "guided" (honour supplied inputs, don\'t invent), or "polish" (minimal, surgical changes only).'), required: TRUE),
    'locked' => new ContextDefinition(data_type: 'boolean', label: new TranslatableMarkup('Locked'), description: new TranslatableMarkup('Whether to lock the brand (pin it to minimal, single-axis changes — no sweeping edits, whatever the stage). Defaults to the current lock state when omitted.'), required: FALSE),
    'rationale' => new ContextDefinition(data_type: 'string', label: new TranslatableMarkup('Rationale'), description: new TranslatableMarkup('A short, plain-language reason for the proposed change, shown on the confirm card (e.g. "The palette and type look settled — I\'d switch to Polish so I only make small tweaks from here.").'), required: FALSE),
  ],
)]
final class ProposeBrandStatus extends FunctionCallBase implements ExecutableFunctionCallInterface {

  /**
   * The brand repository — reads the current status for the proposal card.
   */
  protected BrandRepository $brand;

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
    $instance->brand = $container->get('aincient_pages.brand');
    $instance->currentUser = $container->get('current_user');
    return $instance;
  }

  /**
   * {@inheritdoc}
   *
   * Validates the proposed stage, folds in the current lock state when the model
   * omitted it, and emits the confirm-card envelope. Persists NOTHING — the human
   * applies it from the card. No-ops (proposing the current status) still render
   * the card so the user sees what the agent suggested.
   */
  public function execute(): void {
    // Gate on the same permission the sibling brand tools use (aincient_brand
    // stays within its own dependency cone — it must not reach for the
    // aincient_chat-minted per-studio permission). The real security boundary is
    // the confirm POST to /aincient/brand/status, which enforces
    // `use aincient studio design_system`; a user without it would see the card
    // but the write would 403.
    if (!$this->currentUser->hasPermission('administer aincient pages')) {
      $this->result = 'Error: you do not have permission to change the brand status.';
      return;
    }

    $stage = (string) $this->getContextValue('stage');
    if (!in_array($stage, BrandRepository::STAGES, TRUE)) {
      // Surface the legal set so the model can correct itself rather than the
      // user seeing a card for a stage that can never be applied.
      $this->result = sprintf(
        'Error: "%s" is not a valid stage. Use one of: %s.',
        $stage,
        implode(', ', BrandRepository::STAGES),
      );
      return;
    }

    $current = $this->brand->status();
    // `locked` is optional — default to the current lock state so a stage-only
    // proposal doesn't silently flip the lock.
    $lockedRaw = $this->getContextValue('locked');
    $locked = $lockedRaw === NULL ? (bool) $current['locked'] : (bool) $lockedRaw;
    $rationale = trim((string) ($this->getContextValue('rationale') ?? ''));

    $envelope = [
      '__widget__' => 'brand_status_proposal',
      'payload' => [
        'stage' => $stage,
        'locked' => $locked,
        'rationale' => $rationale,
        // The card shows what it would change FROM, and can disable Confirm when
        // the proposal is a no-op.
        'current' => ['stage' => $current['stage'], 'locked' => (bool) $current['locked']],
      ],
      'summary' => $rationale !== ''
        ? sprintf('Proposed brand status: %s%s — %s', $stage, $locked ? ' (locked)' : '', $rationale)
        : sprintf('Proposed brand status: %s%s.', $stage, $locked ? ' (locked)' : ''),
    ];
    $this->result = (string) json_encode($envelope);
  }

  /**
   * {@inheritdoc}
   */
  public function getReadableOutput(): string {
    return $this->result;
  }

}
