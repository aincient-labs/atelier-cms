<?php

declare(strict_types=1);

namespace Drupal\aincient_chat\Studio;

/**
 * The studio a `<module>.studios.yml` manifest instantiates when it names no
 * `class:` — which is every studio that needs no PHP of its own.
 *
 * Deliberately empty: a studio's server-side facts live in its manifest and
 * {@see StudioBase} derives everything from them. A studio only names a class
 * when it has behaviour to add, and then it extends StudioBase itself.
 */
final class Studio extends StudioBase {
}
