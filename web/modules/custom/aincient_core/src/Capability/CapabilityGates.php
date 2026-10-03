<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Capability;

/**
 * Every capability gate, asked in turn: the dispatch-path seam of 0430.
 *
 * A disabled studio must have its OWN capabilities refused server-side —
 * hiding them from its own studio is not enough, because a turn in ANOTHER
 * studio could still call them (plans/studio-modules.md "On / off"). The one
 * place every model-issued call crosses is
 * {@see \Drupal\aincient_flows\Plugin\FlowDropNodeProcessor\CapabilityTool::process()},
 * so the refusal lives there, and this collector is how core asks without
 * knowing which modules have an opinion (aincient_chat's studio gate is the
 * first; core itself owns none).
 *
 * This is NOT the 0368 taint + tool gate, which does not exist yet. A gate
 * here answers "may this verb run at all right now" — a property of the
 * install's switches, the same for every turn. Whether a turn is TAINTED by an
 * attachment, and so may not spend an irreversible verb, is a per-turn
 * question with a different home; do not grow it into this seam.
 *
 * With zero gates (core alone, or a kernel test without aincient_chat) every
 * capability is allowed, so the seam costs nothing where nothing switches.
 */
final class CapabilityGates implements CapabilityGateInterface {

  /**
   * @var list<\Drupal\aincient_core\Capability\CapabilityGateInterface>
   */
  private array $gates = [];

  /**
   * Adds a gate (service_collector `call`).
   */
  public function addGate(CapabilityGateInterface $gate): void {
    $this->gates[] = $gate;
  }

  /**
   * {@inheritdoc}
   *
   * The first gate's refusal wins: one reason is enough for the model, and
   * collector order (tag priority) decides which one it reads.
   */
  public function refusal(string $capabilityId): ?string {
    foreach ($this->gates as $gate) {
      $refusal = $gate->refusal($capabilityId);
      if ($refusal !== NULL) {
        return $refusal;
      }
    }
    return NULL;
  }

}
