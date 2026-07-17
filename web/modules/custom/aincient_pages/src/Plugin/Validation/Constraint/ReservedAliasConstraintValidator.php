<?php

declare(strict_types=1);

namespace Drupal\aincient_pages\Plugin\Validation\Constraint;

use Drupal\aincient_pages\ReservedAliases;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates {@see ReservedAliasConstraint} against a node 'path' field item list.
 *
 * A pathauto-suffixed alias ('/aincient-0') is fine — only an exact reserved
 * leading segment ('/aincient', '/aincient/foo') trips the violation, so this
 * never rejects pathauto's own output.
 */
final class ReservedAliasConstraintValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$constraint instanceof ReservedAliasConstraint) {
      return;
    }
    // $value is the 'path' field item list; each item exposes an 'alias'.
    foreach ($value as $item) {
      $alias = (string) ($item->alias ?? '');
      if ($alias === '') {
        continue;
      }
      if ($segment = ReservedAliases::match($alias)) {
        $this->context->addViolation($constraint->message, [
          '%alias' => $alias,
          '%segment' => $segment,
        ]);
      }
    }
  }

}
