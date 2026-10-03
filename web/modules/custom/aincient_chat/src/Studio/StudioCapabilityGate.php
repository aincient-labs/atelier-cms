<?php

declare(strict_types=1);

namespace Drupal\aincient_chat\Studio;

use Drupal\aincient_core\Capability\CapabilityGateInterface;
use Psr\Log\LoggerInterface;

/**
 * A switched-off studio's OWNED capabilities are refused at dispatch.
 *
 * Hiding a studio from the console is not enough: its verbs are still
 * capability plugins, and a turn in ANOTHER studio whose flow happens to wire
 * one could still call it (plans/studio-modules.md "On / off", DECISIONS 0430).
 * So ownership — the manifest's `capabilities:` list, read through
 * {@see StudioInterface::capabilities()} — is turned into a refusal the model
 * reads as the tool result.
 *
 * Only OWNED verbs are refused. A verb more than one studio uses lives in core
 * and is in no manifest (the capability ownership rule), so it is unknown here
 * and always allowed — which is also why an unowned id answers NULL rather
 * than "deny by default": this gate subtracts, it does not decide what exists.
 */
final class StudioCapabilityGate implements CapabilityGateInterface {

  /**
   * Capability id => owning studio, built once per request.
   *
   * @var array<string, \Drupal\aincient_chat\Studio\StudioInterface>|null
   */
  private ?array $owners = NULL;

  public function __construct(
    private readonly StudioManager $studios,
    private readonly StudioSwitch $switch,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function refusal(string $capabilityId): ?string {
    $owner = $this->owners()[$capabilityId] ?? NULL;
    if ($owner === NULL || $this->switch->isEnabled($owner->id())) {
      return NULL;
    }
    return sprintf(
      'The %s studio is switched off, so %s is not available. Ask the operator to switch it on in Settings.',
      $owner->label(),
      $capabilityId,
    );
  }

  /**
   * The ownership map, in studio display order.
   *
   * Two studios claiming one capability breaks the ownership rule (one verb,
   * one owner); it is warned about once and the FIRST studio wins, so the
   * answer is still deterministic. The manifest guard (M4) turns it into a
   * test failure.
   *
   * @return array<string, \Drupal\aincient_chat\Studio\StudioInterface>
   */
  private function owners(): array {
    if ($this->owners !== NULL) {
      return $this->owners;
    }
    $owners = [];
    foreach ($this->studios->studios() as $studio) {
      foreach ($studio->capabilities() as $id) {
        if (isset($owners[$id])) {
          if ($owners[$id]->id() !== $studio->id()) {
            $this->logger->warning('Capability @id is claimed by both the @first and @second studios; treating it as owned by @first.', [
              '@id' => $id,
              '@first' => $owners[$id]->id(),
              '@second' => $studio->id(),
            ]);
          }
          continue;
        }
        $owners[$id] = $studio;
      }
    }
    return $this->owners = $owners;
  }

}
