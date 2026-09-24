<?php

declare(strict_types=1);

namespace Drupal\aincient_chat\Plugin\Studio;

use Drupal\aincient_chat\Attribute\Studio;
use Drupal\aincient_chat\Studio\StudioBase;

/**
 * The Globals studio: site-wide chrome, tabbed (Brand identity / Header /
 * Footer).
 *
 * An EDITOR-ONLY studio — it has no chat agent (deterministic rails + live
 * preview only), so it never appears in the server agent-catalog; the front-end
 * enables it from its editor components alone.
 */
#[Studio(id: 'globals', label: 'Navigation & Pages', weight: 20)]
final class Globals extends StudioBase {}
