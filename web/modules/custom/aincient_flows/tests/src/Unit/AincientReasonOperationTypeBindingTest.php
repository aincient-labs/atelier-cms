<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_flows\Unit;

use Drupal\aincient_core\Inference\AincientReasonerInterface;
use Drupal\aincient_core\Inference\AincientReasonResult;
use Drupal\aincient_flows\Plugin\FlowDropNodeProcessor\AincientReason;
use Drupal\flowdrop\DTO\Reason\ModelChoices;
use Drupal\flowdrop\DTO\Reason\ReasonRequest;
use Drupal\flowdrop\DTO\Reason\ReasonResult;
use Drupal\flowdrop\DTO\Tool\ToolCollection;
use Drupal\flowdrop_runtime\Service\ParameterResolver;
use Drupal\flowdrop_runtime\Service\Secret\SecretReferenceResolverInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Component\Yaml\Yaml;

/**
 * The model role is bindable: a wired `operation_type` beats the config value.
 *
 * The agent engine (DECISIONS 0463) is one sub-workflow shared by every studio,
 * and each studio picks its tier (`aincient_role:reasoning` for pages and brand,
 * `aincient_role:task` elsewhere) through the engine's `operation_type` input.
 * Inside the engine that input is a DATA EDGE onto the reason node, so the
 * shipped node type must mark the param connectable — ParameterResolver drops a
 * runtime value for a non-connectable param and the author's config wins, which
 * would silently run every studio on the engine's own default tier.
 *
 * Drives the real resolver over the shipped node-type config, then the real
 * node, and reads the role off the request the backend received.
 */
#[Group('aincient_flows')]
final class AincientReasonOperationTypeBindingTest extends UnitTestCase {

  /**
   * The request the fake backend last received.
   */
  private ?ReasonRequest $request = NULL;

  /**
   * Resolves the node's params from the shipped config, runs it, returns the role.
   *
   * @param array<string, mixed> $runtimeInputs
   *   Values arriving on data edges.
   */
  private function roleFor(array $runtimeInputs): string {
    $file = dirname(__DIR__, 7) . '/config/sync/flowdrop_node_type.flowdrop_node_type.aincient_reason.yml';
    $nodeType = Yaml::parseFile($file);

    $test = $this;
    $reasoner = new class($test) implements AincientReasonerInterface {

      public function __construct(private AincientReasonOperationTypeBindingTest $test) {}

      public function reasonRich(ReasonRequest $request): AincientReasonResult {
        $this->test->capture($request);
        return new AincientReasonResult('ok');
      }

      public function reason(ReasonRequest $request): ReasonResult {
        $this->reasonRich($request);
        return new ReasonResult('ok', []);
      }

      public function getModelChoices(string $operationType = 'chat'): ModelChoices {
        return new ModelChoices([], '', [
          ['value' => 'aincient_role:task', 'label' => 'Task'],
          ['value' => 'aincient_role:reasoning', 'label' => 'Reasoning'],
          ['value' => 'chat', 'label' => 'Chat'],
        ]);
      }

    };

    $node = new AincientReason([], 'aincient_flows:aincient_reason', [], $reasoner);
    $node->setTools(new ToolCollection([]));

    $secrets = $this->createMock(SecretReferenceResolverInterface::class);
    $secrets->method('substitute')->willReturnArgument(0);
    $resolver = new ParameterResolver(new NullLogger(), $secrets);

    $bag = $resolver->resolve(
      $node->getParameterSchema(),
      $nodeType['parameters'],
      // The author's stored config on the placed instance ('' = no model
      // override: resolve via the role).
      ['operation_type' => 'aincient_role:task', 'model' => ''],
      $runtimeInputs + ['messages' => [['role' => 'user', 'content' => 'go']]],
    );
    $node->process($bag);

    $this->assertNotNull($this->request);
    return $this->request->getOperationType();
  }

  /**
   * Records the request the fake backend received.
   */
  public function capture(ReasonRequest $request): void {
    $this->request = $request;
  }

  /**
   * A value on the operation_type edge wins over the instance config.
   */
  public function testBoundValueBeatsConfig(): void {
    $this->assertSame('aincient_role:reasoning', $this->roleFor(['operation_type' => 'aincient_role:reasoning']),
      'aincient_reason must declare operation_type connectable, or the engine cannot pass a studio its tier.');
  }

  /**
   * Unwired, the instance config still decides.
   */
  public function testConfigAppliesWhenUnbound(): void {
    $this->assertSame('aincient_role:task', $this->roleFor([]));
  }

}
