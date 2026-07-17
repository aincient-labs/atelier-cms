<?php

declare(strict_types=1);

namespace Drupal\aincient_pages\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Rejects a page URL alias whose leading segment is a reserved namespace.
 *
 * @see \Drupal\aincient_pages\ReservedAliases
 */
#[Constraint(
  id: 'AincientReservedAlias',
  label: new TranslatableMarkup('Reserved URL alias namespace', [], ['context' => 'Validation'])
)]
final class ReservedAliasConstraint extends SymfonyConstraint {

  /**
   * Violation message. %alias = the full alias, %segment = the reserved word.
   */
  public string $message = 'The URL alias %alias starts with "%segment", which is reserved. Choose a different path.';

}
