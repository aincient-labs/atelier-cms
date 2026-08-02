<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Capability;

use Drupal\Core\Executable\ExecutableInterface;

/**
 * A capability that can actually be run, and report back in words.
 *
 * The full contract of the calling convention every Atelier capability follows:
 * set the declared context values, `execute()`, read
 * `getReadableOutput()`. That is the whole protocol —
 * {@see \Drupal\aincient_flows\Plugin\FlowDropNodeProcessor\CapabilityTool}
 * performs exactly those three steps and nothing else.
 *
 * OUTPUT IS A STRING ON PURPOSE. A capability's result goes back to a language
 * model, so it is prose the model reads and reasons about — including its own
 * failures. Capabilities return a graceful `Error: you do not have permission
 * to edit pages.` instead of throwing, because a model can recover from a
 * sentence and cannot recover from an exception. Some capabilities return a
 * widget envelope (`{"__widget__": …}`) in this same string channel; that is
 * still just text as far as this contract is concerned, harvested downstream by
 * the chat dispatcher.
 *
 * `setOutput()` is part of the interface because the caller — not only the
 * plugin — may need to substitute the readable result (e.g. wrapping or
 * redacting it) without reaching into plugin internals.
 */
interface ExecutableCapabilityInterface extends CapabilityInterface, ExecutableInterface {

  /**
   * The capability's result, as text a language model can read.
   *
   * @return string
   *   The readable output. Empty string before `execute()` has run.
   */
  public function getReadableOutput(): string;

  /**
   * Overrides the readable result.
   *
   * @param string $output
   *   The text to report as this capability's result.
   */
  public function setOutput(string $output): void;

}
