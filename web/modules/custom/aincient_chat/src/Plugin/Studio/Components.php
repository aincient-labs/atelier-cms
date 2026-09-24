<?php

declare(strict_types=1);

namespace Drupal\aincient_chat\Plugin\Studio;

use Drupal\aincient_chat\Attribute\Studio;
use Drupal\aincient_chat\Studio\StudioBase;

/**
 * The Components studio: the site-wide component-governance surface
 * (plans/byo-components.md W1b) — which components, tones and variants this
 * site REMOVES from every kind's palette.
 *
 * Narrowing-only; the admission gate stays the hard floor in code. An
 * EDITOR-ONLY studio like Settings (no chat agent); it persists through
 * /atelier/constraint/*.
 */
#[Studio(id: 'components', label: 'Components', weight: 40)]
final class Components extends StudioBase {}
