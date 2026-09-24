<?php

declare(strict_types=1);

namespace Drupal\aincient_chat\Plugin\Studio;

use Drupal\aincient_chat\Attribute\Studio;
use Drupal\aincient_chat\Studio\StudioBase;

/**
 * The Content studio: the live page composer + preview split-pane (pages, and
 * later structured content). (Was "Page".)
 */
#[Studio(id: 'content', label: 'Content', weight: 50)]
final class Content extends StudioBase {}
