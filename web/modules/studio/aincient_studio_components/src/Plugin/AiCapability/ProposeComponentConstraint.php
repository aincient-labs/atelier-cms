<?php

declare(strict_types=1);

namespace Drupal\aincient_studio_components\Plugin\AiCapability;

use Drupal\aincient_core\Attribute\Capability;
use Drupal\aincient_core\Capability\CapabilityBase;
use Drupal\aincient_core\Capability\ExecutableCapabilityInterface;
use Drupal\aincient_studio_components\ComponentProposalApplier;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * AIncient capability: stage a component-governance change in the studio.
 *
 * The Components agent's ONLY edit tool (DECISIONS 0455, P2). Like its chrome
 * and brand siblings it writes NOTHING: it emits a `components_proposal` widget
 * envelope the Components studio merges into the staged draft of one scope
 * (everywhere, or one page type), and the human publishes it — after the
 * impact list — with the studio's Publish button. It never writes a pack and
 * treats pack and built-in components alike (0368).
 *
 * @see \Drupal\aincient_studio_components\ComponentProposalApplier
 */
#[Capability(
  id: 'aincient_studio_components:propose_component_constraint',
  function_name: 'aincient_propose_component_constraint',
  name: 'Propose component changes',
  description: 'Stage a change to which components, variants and tones this site offers, in the user\'s Components studio. It does NOT publish: the change appears as unsaved in the studio and the user reviews the affected pages and publishes it. scope is "site" (everywhere) or a page type id from COMPONENTS STATE. changes_json is a JSON object of operations: turn_off / turn_on (lists of component names), variants_off / variants_on and tones_off / tones_on (objects of component name => list; in the site scope tones_off {"*": [...]} turns a tone off for every component), and for a page type only: include_new (true = new pack components are allowed automatically), opener (a component name, or "" for none) and limits (component name => max per page, 0 = no limit). Use only names listed in COMPONENTS STATE. Existing sections are always kept on their pages; turning something off only stops new placements.',
  context_definitions: [
    'scope' => new ContextDefinition(data_type: 'string', label: new TranslatableMarkup('Scope'), description: new TranslatableMarkup('"site" for everywhere, or a page type id (e.g. "landing", "block").'), required: FALSE),
    'changes_json' => new ContextDefinition(data_type: 'string', label: new TranslatableMarkup('Changes'), description: new TranslatableMarkup('A JSON object of operations, e.g. {"turn_off":["pricing","newsletter"],"tones_off":{"stats":["inverted"]}}.'), required: TRUE),
    'reason' => new ContextDefinition(data_type: 'string', label: new TranslatableMarkup('Reason'), description: new TranslatableMarkup('One short sentence the user sees on the proposal card: why this change.'), required: FALSE),
  ],
)]
final class ProposeComponentConstraint extends CapabilityBase implements ExecutableCapabilityInterface {

  /**
   * The proposal applier (all the validation + envelope work).
   */
  protected ComponentProposalApplier $applier;

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
    $instance->applier = $container->get('aincient_studio_components.proposal_applier');
    $instance->currentUser = $container->get('current_user');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function execute(): void {
    if (!$this->currentUser->hasPermission('use aincient studio components')) {
      $this->result = 'Error: you do not have permission to change which components this site offers.';
      return;
    }
    $envelope = $this->applier->apply([
      'scope' => $this->getContextValue('scope'),
      'changes_json' => $this->getContextValue('changes_json'),
      'reason' => $this->getContextValue('reason'),
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
