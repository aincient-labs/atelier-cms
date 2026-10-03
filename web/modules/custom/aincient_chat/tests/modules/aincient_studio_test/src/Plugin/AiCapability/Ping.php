<?php

declare(strict_types=1);

namespace Drupal\aincient_studio_test\Plugin\AiCapability;

use Drupal\aincient_core\Attribute\Capability;
use Drupal\aincient_core\Capability\CapabilityBase;

/**
 * The fixture studio's one OWNED capability (`capabilities: [ping]`).
 *
 * Read-only and internal — taint-safe by construction. It is not in the roster
 * guard's glob (tests/modules is not a tier), and it only exists while the
 * test module is enabled.
 */
#[Capability(
  id: 'aincient_studio_test:ping',
  function_name: 'aincient_studio_test_ping',
  name: 'Ping',
  description: 'Replies pong. A fixture for the studio on/off tests.',
)]
final class Ping extends CapabilityBase {

  public function execute(): void {
    $this->setOutput('pong');
  }

}
