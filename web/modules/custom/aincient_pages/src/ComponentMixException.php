<?php

declare(strict_types=1);

namespace Drupal\aincient_pages;

/**
 * A page save that would hold two components under one short name (0539).
 *
 * A page holds at most one component per short name, so the agent and the
 * owner can keep speaking short names. The save is refused, never repaired:
 * picking one would silently change a section.
 */
final class ComponentMixException extends \InvalidArgumentException {

  /**
   * @param array<string, list<string>> $mixes
   *   short name => the full ids the page would hold.
   */
  public function __construct(public readonly array $mixes) {
    $parts = [];
    foreach ($mixes as $name => $ids) {
      $parts[] = sprintf('"%s" (%s)', $name, implode(' and ', $ids));
    }
    parent::__construct(sprintf(
      'This page would hold two different components with the same name: %s. A page can use only one of them — switch the existing sections to the new one first, or remove one.',
      implode(', ', $parts),
    ));
  }

}
