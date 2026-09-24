<?php

declare(strict_types=1);

namespace Drupal\aincient_chat\Plugin\Studio;

use Drupal\aincient_chat\Attribute\Studio;
use Drupal\aincient_chat\Studio\StudioBase;

/**
 * The Checks studio: read-only page health audits (SEO, meta tags, internal-link
 * integrity).
 *
 * UNLIKE the others it has no live preview — the panel shows structured
 * findings directly. Audit-only: the agent reports, never writes. Ships behind
 * the `features.checks_enabled` flag in `aincient_chat.settings`
 * ({@see \Drupal\aincient_chat\Controller\ConsoleController}).
 */
#[Studio(id: 'checks', label: 'Checks', weight: 80)]
final class Checks extends StudioBase {}
