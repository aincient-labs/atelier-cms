<?php

declare(strict_types=1);

namespace Drupal\aincient_chat\Plugin\Studio;

use Drupal\aincient_chat\Attribute\Studio;
use Drupal\aincient_chat\Studio\StudioBase;

/**
 * The Settings studio: site-wide operator settings that belong to neither the
 * brand IDENTITY nor the NAVIGATION surface — site email + privacy/consent
 * (font delivery).
 *
 * An EDITOR-ONLY studio like Globals (no chat agent); it persists through the
 * shared chrome endpoints (the `mail` + `font_delivery` slices). Split out of
 * the old Globals studio (DECISIONS 0372).
 */
#[Studio(id: 'settings', label: 'Settings', weight: 30)]
final class Settings extends StudioBase {}
