<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_flows\Kernel;

use Drupal\aincient_core\Inference\AincientReasonResult;
use Drupal\aincient_core\Inference\AincientReasonerInterface;
use Drupal\flowdrop\DTO\Reason\ReasonRequest;
use Drupal\flowdrop\DTO\Reason\ReasonResult;
use Drupal\flowdrop\DTO\Reason\ModelChoices;

/**
 * Test-only reasoner that replays a fixed script, one entry per call.
 *
 * Replaces `aincient_core.inference.reasoner` in a kernel container so the
 * shipped agent loop runs with no provider key and no network. State is static
 * because the container may rebuild the service per resolution.
 */
final class ScriptedReasoner implements AincientReasonerInterface {

  /**
   * Remaining results, consumed front to back.
   *
   * @var array<int, \Drupal\aincient_core\Inference\AincientReasonResult>
   */
  public static array $script = [];

  /**
   * Every request the node sent, in call order.
   *
   * @var array<int, \Drupal\flowdrop\DTO\Reason\ReasonRequest>
   */
  public static array $requests = [];

  /**
   * {@inheritdoc}
   */
  public function reasonRich(ReasonRequest $request): AincientReasonResult {
    self::$requests[] = $request;
    if (self::$script === []) {
      throw new \LogicException('ScriptedReasoner script exhausted: the loop reasoned more times than scripted.');
    }
    return array_shift(self::$script);
  }

  /**
   * {@inheritdoc}
   */
  public function reason(ReasonRequest $request): ReasonResult {
    $rich = $this->reasonRich($request);
    return new ReasonResult($rich->getText(), $rich->getToolCalls());
  }

  /**
   * {@inheritdoc}
   */
  public function getModelChoices(string $operationType = 'chat'): ModelChoices {
    return new ModelChoices([], '', [
      ['value' => 'aincient_role:task', 'label' => 'Task'],
      ['value' => 'aincient_role:reasoning', 'label' => 'Reasoning'],
      ['value' => 'chat', 'label' => 'Chat'],
    ]);
  }

}
