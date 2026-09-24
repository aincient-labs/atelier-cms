<?php

declare(strict_types=1);

namespace Drupal\aincient_chat\Plugin\Studio;

use Drupal\aincient_chat\Attribute\Studio;
use Drupal\aincient_chat\Studio\StudioBase;

/**
 * The Library studio: the reusable-ingredient shelf (media + global blocks, one
 * `block` media substrate).
 *
 * An EDITOR-ONLY studio like Globals — no chat agent — and unlike every other
 * it has no live preview either: its editor IS a full-width browse canvas over
 * the unified reference catalog. Ingredients are authored elsewhere (uploads
 * here; blocks in the Content studio).
 */
#[Studio(id: 'library', label: 'Library', weight: 60)]
final class Library extends StudioBase {}
