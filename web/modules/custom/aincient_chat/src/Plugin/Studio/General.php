<?php

declare(strict_types=1);

namespace Drupal\aincient_chat\Plugin\Studio;

use Drupal\aincient_chat\Attribute\Studio;
use Drupal\aincient_chat\Studio\StudioBase;

/**
 * The default, catch-all studio: full-width chat, no editor pane.
 *
 * Holds the general-purpose agents (operator, weather, …) and is where an agent
 * that maps to no studio lands. The one OPEN studio — anyone who can open the
 * console is already in it, so it mints no permission
 * ({@see \Drupal\aincient_chat\Studio\StudioManager::DEFAULT_ID}).
 */
#[Studio(id: 'general', label: 'General', weight: 0, open: TRUE)]
final class General extends StudioBase {}
