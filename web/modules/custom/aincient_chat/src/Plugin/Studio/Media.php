<?php

declare(strict_types=1);

namespace Drupal\aincient_chat\Plugin\Studio;

use Drupal\aincient_chat\Attribute\Studio;
use Drupal\aincient_chat\Studio\StudioBase;

/**
 * The Media studio: edit ONE image-media item — its non-AI editor rail (name,
 * alt text, replace file) beside a preview of the image.
 *
 * Reached by opening an item from the Library shelf, not from the top nav. The
 * non-AI rail is always the human path (plan `plans/media-studio.md`, DECISIONS
 * 0144); the chat rail beside it is now ALWAYS offered too — it used to appear
 * only once an image provider was configured, which removed a room whose
 * words-only capabilities (naming, alt text from human input) were worth having
 * on their own. What the image binding decides is the "Draw" capability chip,
 * not the room.
 */
#[Studio(id: 'media', label: 'Media', weight: 70)]
final class Media extends StudioBase {}
