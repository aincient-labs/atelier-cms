<?php

declare(strict_types=1);

namespace Drupal\aincient_chat\Plugin\Studio;

use Drupal\aincient_chat\Attribute\Studio;
use Drupal\aincient_chat\Studio\StudioBase;

/**
 * The Design System studio: the live design-token (Foundations) editor +
 * preview split-pane.
 *
 * (Was "Brand" — renamed to its design-system layer; the brand IDENTITY —
 * logo/name/tagline — moved to the Globals studio. The admin label is
 * "Identity" because that is what the console calls the room, DECISIONS 0372.)
 */
#[Studio(id: 'design_system', label: 'Identity', weight: 10)]
final class DesignSystem extends StudioBase {}
