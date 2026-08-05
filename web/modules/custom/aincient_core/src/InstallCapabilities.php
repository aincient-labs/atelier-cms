<?php

declare(strict_types=1);

namespace Drupal\aincient_core;

use Drupal\aincient_core\Inference\AiGateway;
use Drupal\Core\Url;

/**
 * Computes what this install can DO — once, for every surface that says so.
 *
 * THE SINGLE SOURCE OF TRUTH for the three product verbs. It is computed
 * server-side and consumed twice:
 *
 *  - the console shell serialises {@see self::chips()} onto the same payload that
 *    already carries the studio catalog, and the chat rail renders it;
 *  - an agent's Prompt Template calls `install_capabilities()`
 *    ({@see Twig\InstallCapabilitiesExtension}), which is {@see self::promptLine()}.
 *
 * Both come out of one {@see CapabilitySet}, so the chips and the model cannot
 * disagree. Recomputing the same three booleans in TypeScript would reintroduce
 * exactly the drift this replaces.
 *
 * WHAT IT REPLACED. The Media room used to be REMOVED from the console catalog
 * when the image role was unbound — a capability question answered as an access
 * question, one level too coarse: a chat rail whose only capability is words is
 * still worth having (filling a title and description from human input is a
 * legitimate use), and the gate deleted a working room to protect one tool inside
 * it. Capability now decides DISPLAY only. Each tool still reports its own
 * failure, which is what makes an approximate label safe.
 */
final class InstallCapabilities {

  public function __construct(
    private readonly AiGateway $ai,
    private readonly ModelRoleResolver $roles,
  ) {}

  /**
   * The three booleans, each established rather than assumed.
   *
   * WRITE — the default chat tier can actually reach a model. The verb is "always
   * on" in product terms, and on any onboarded install it is; but a keyless site
   * cannot write either, and a chip that promises otherwise is the failure shape
   * we are removing, so it is asked rather than asserted.
   *
   * DESCRIBE — the {@see ModelRoles::VISION} role is EXPLICITLY bound AND that
   * binding is usable (an adapter claims the provider and a credential is
   * stored). The explicit binding is the load-bearing half: vision resolves with
   * a FALLBACK to the default chat tier ({@see ModelRoleResolver::resolve()}), so
   * alt text is always attempted — but nobody ever said the task model can see,
   * and no adapter reports per-model vision support. Lighting the chip off that
   * fallback would be a promise made from a guess, which is issue #8's failure
   * shape in a new room. Unbound therefore reads needs-setup even though the tool
   * would still try: the chip governs display, not access. Onboarding pins vision
   * (to the task model) as part of the wizard, so a normally set-up site is calm.
   *
   * DRAW — {@see AiGateway::imageStatus()} is READY: the image role is bound, its
   * adapter can actually draw, and a credential is stored. This is the SAME
   * question `generate_image` asks before running, so a lit chip and a working
   * tool are the same answer. (Strictly stronger than "is the image role bound",
   * which was the old gate's test and could still 401 on first use.)
   */
  public function set(): CapabilitySet {
    return new CapabilitySet(
      write: $this->ai->canText(ModelRoles::TASK),
      describe: $this->roles->visionBinding() !== NULL
        && $this->ai->roleStatus(ModelRoles::VISION) === AiGateway::STATUS_READY,
      draw: $this->ai->imageStatus() === AiGateway::STATUS_READY,
    );
  }

  /**
   * The chips for the console payload.
   *
   * @return list<array{id: string, label: string, means: string, available: bool, hint: string, setupUrl: string}>
   */
  public function chips(): array {
    return $this->set()->chips(self::connectUrl());
  }

  /**
   * The capability block for an agent's system prompt.
   */
  public function promptLine(): string {
    return $this->set()->promptLine();
  }

  /**
   * Where a needs-setup chip sends an operator — from the route table, always.
   *
   * The console's IA has already moved once (/aincient → /atelier), so no surface
   * spells a path. Same discipline as the shell's injected `basePath`.
   *
   * PUBLIC and static because it is now the ONE derivation of "where you fix your
   * models", shared by the capability chips and by the chat console's provider
   * failure card (`auth`/`model_missing` offer exactly this link). A second
   * derivation is a second thing to forget when the route moves again — and this
   * needs no state, so no consumer has to build this whole service to ask.
   */
  public static function setupUrl(): string {
    return Url::fromRoute('aincient_core.model_roles')->toString();
  }

  /**
   * Where a needs-setup CHIP sends an operator: the onboarding wizard.
   *
   * A dimmed chip is a missing *capability* — "no image provider is connected" —
   * and connecting a provider is a key, a model pick and a role binding, which is
   * exactly the wizard's job. The models form ({@see self::setupUrl()}) only
   * binds roles to providers you already have, so it is the right destination for
   * "Change model" on a provider failure and the wrong one for someone who has
   * nothing to bind yet: they arrived at a form whose section is empty.
   *
   * `?onboarding=1` on the console re-enters the wizard on a configured site —
   * and ONLY for `administer site configuration`, which is the same gate
   * aincient_onboarding_aincient_console_settings_alter() applies before it
   * honours the query. So the link is offered to exactly the people it works
   * for: anyone else following it would land on an ordinary console and be left
   * to wonder what they were promised. Returning '' is a supported state —
   * {@see CapabilitySet::chips()} still ships the chip and its hint, and the
   * console renders it as plain text rather than a link. A non-admin cannot fix
   * a missing provider anyway; what they need is the explanation, which is the
   * part that stays.
   *
   * Falls back to the models form when aincient_chat is not installed, so an
   * admin is never left without a way in.
   */
  public static function connectUrl(): string {
    if (!\Drupal::currentUser()->hasPermission('administer site configuration')) {
      return '';
    }
    try {
      return Url::fromRoute('aincient_chat.console', [], ['query' => ['onboarding' => '1']])->toString();
    }
    catch (\Throwable $e) {
      // No console (aincient_chat uninstalled) — the form is still reachable.
      return self::setupUrl();
    }
  }

}
