<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Capability;

/**
 * One reason a capability may not run at all right now.
 *
 * Implemented by any module that can switch a verb off — today the studio
 * on/off switch (a disabled studio's OWNED capabilities are refused,
 * plans/studio-modules.md "On / off", DECISIONS 0430). Collected by
 * {@see CapabilityGates} and consulted once per call at dispatch.
 *
 * The answer is a sentence, not a boolean, because it is read by the MODEL: a
 * refused tool call returns this text as the tool result, so the agent can
 * tell the user what to do instead of retrying or hallucinating success.
 */
interface CapabilityGateInterface {

  /**
   * Why a capability is refused, or NULL when this gate has no objection.
   *
   * @param string $capabilityId
   *   The capability plugin id (`<provider>:<slug>`).
   *
   * @return string|null
   *   NULL = allowed by this gate; a string = the readable refusal the model
   *   sees as the tool result.
   */
  public function refusal(string $capabilityId): ?string;

}
