<?php

declare(strict_types=1);

namespace Drupal\aincient_studio_site\Engine;

/**
 * A console revoke that was not written, with a plain-words reason.
 */
final class RevokeRefused extends \RuntimeException {

  public function __construct(string $message, public readonly int $status) {
    parent::__construct($message);
  }

}
