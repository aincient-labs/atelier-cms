<?php

declare(strict_types=1);

namespace Drupal\aincient_brand\Plugin\AiCapability;

use Drupal\aincient_core\Attribute\Capability;
use Drupal\aincient_core\Capability\CapabilityBase;
use Drupal\aincient_core\Capability\ExecutableCapabilityInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * AIncient capability: hand an attached-logo request off to Identity → Logo.
 *
 * The loud dead end (DECISIONS 0372, Phase 3). An operator drags a logo/brand
 * mark PNG into chat and says "use this as my logo". The image is pre-described
 * to the model then its bytes are DISCARDED ({@see \Drupal\aincient_chat\Chat\AttachmentTurnPreparer};
 * ContextStore::storeImage keeps no original), and there is NO tool that sets
 * the logo image — a chat turn cannot place a file into a media field. Left
 * alone the agent "notices" the logo and silently does nothing. This routes it:
 * a `logo_handoff` generative-UI card that says images can't be placed from chat
 * and DEEP-LINKS to Identity → Logo, where the human drops the file into the
 * logo picker (the whole logo lives in the Identity studio, DECISIONS 0372).
 *
 * Routing-ONLY, and taint-safe by construction (DECISIONS 0368): it PLACES
 * nothing. It writes no field, uploads no file, touches no media — it emits a
 * card whose only action is a client-side navigation the human then acts on. The
 * deep-link is resolved at the console (the widget owns the studio room + the
 * `identity.logo` field anchor via the shared registry), so no path is baked
 * into agent text. Reversible + internal, which is why the deferred attachment
 * tool-gate stays deferred (see CapabilityRosterTaintGuardTest — this capability
 * is recorded there as reviewed taint-safe).
 *
 * The attachment stays untrusted DATA: this is a ROUTING reply the model chooses
 * from the operator's own ask, never an instruction obeyed from inside the file.
 *
 * @see \Drupal\aincient_chat\Chat\AttachmentTurnPreparer
 */
#[Capability(
  id: 'aincient_brand:route_logo_image',
  function_name: 'aincient_route_logo_image',
  name: 'Route logo image to Identity',
  description: 'Hand the operator off to the Identity studio\'s Logo field when they ATTACHED an image this turn and want it used as the site LOGO, brand mark, wordmark, or FAVICON. Images cannot be placed from chat — you have no tool that sets a logo/favicon image, and the attachment\'s bytes are not kept — so call this INSTEAD of trying to apply it: it shows the user a card that explains this and opens Identity → Logo, where they drop the file into the picker. Pass asset="favicon" when they asked about the favicon / browser-tab icon, otherwise "logo". Use ONLY for an attached IMAGE meant as a logo/favicon; a design/token FILE goes to propose_design_tokens, and a described look with no image goes to the specialists.',
  context_definitions: [
    'asset' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Asset'),
      description: new TranslatableMarkup('Which brand image the operator wants to place: "logo" (the site logo / brand mark / wordmark) or "favicon" (the browser-tab icon). Defaults to "logo".'),
      required: FALSE,
    ),
  ],
)]
final class RouteLogoImage extends CapabilityBase implements ExecutableCapabilityInterface {

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
    $instance->currentUser = $container->get('current_user');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function execute(): void {
    if (!$this->currentUser->hasPermission('administer aincient pages')) {
      $this->result = 'Error: you do not have permission to change the site brand.';
      return;
    }

    // Normalise to the two assets we can deep-link; anything else is a logo.
    $asset = strtolower(trim((string) ($this->getContextValue('asset') ?? '')));
    $asset = $asset === 'favicon' ? 'favicon' : 'logo';

    $label = $asset === 'favicon' ? 'favicon' : 'logo';
    $summary = "I can't place an image from chat — open Identity → "
      . ucfirst($label) . " and drop the file into the picker there.";

    $this->result = (string) json_encode([
      '__widget__' => 'logo_handoff',
      'payload' => ['asset' => $asset],
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
