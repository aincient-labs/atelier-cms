<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_core\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\aincient_core\AincientCoreServiceProvider;
use Drupal\aincient_core\Inference\SymfonyAiReasoner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Tests the container-time wiring of the inference backend.
 *
 * `flowdrop.chat_reasoner` is where the agent loop's reasoning comes from, and
 * this provider is what binds it. The binding happens at container-build time,
 * so nothing at runtime can correct a wrong wiring: every case below is a way an
 * install could boot with a reasoner that cannot work.
 *
 * @coversDefaultClass \Drupal\aincient_core\AincientCoreServiceProvider
 * @group aincient_core
 */
final class AincientCoreServiceProviderTest extends TestCase {

  /**
   * The symfony/ai reasoner is wired, with our services, in our order.
   *
   * Prevents: a site booting on FlowDrop core's NullChatReasoner, which answers
   * nothing. Also prevents a half-wired SymfonyAiReasoner: it takes OUR
   * constructor arguments and the stock definition's are somebody else's, so
   * they must be REPLACED. A wrong id or a wrong order here is a container fatal
   * or, worse, a service quietly injected into the wrong parameter.
   *
   * The list is exhaustive on purpose. This provider — not the .yml definition of
   * the same class — is what wires the LIVE agent loop, so an argument the class
   * grew and this list did not is a silently unwired dependency; the metering
   * recorder was added under exactly that hazard.
   *
   * @covers ::alter
   */
  public function testBindsSymfonyAiReasonerWithOurArguments(): void {
    $container = $this->containerWithReasoner();

    (new AincientCoreServiceProvider())->alter($container);

    $definition = $container->getDefinition('flowdrop.chat_reasoner');
    $this->assertSame(SymfonyAiReasoner::class, $definition->getClass());

    $ids = array_map(
      static fn ($argument): string => $argument instanceof Reference ? (string) $argument : '<not a reference>',
      $definition->getArguments(),
    );
    $this->assertSame([
      'aincient_core.inference.registry',
      'aincient_core.inference.model_targets',
      'aincient_core.inference.message_mapper',
      'aincient_core.inference.tool_schema',
      'aincient_core.inference.result_unpacker',
      'aincient_core.usage_recorder',
      'logger.channel.aincient_core',
    ], $ids);
  }

  /**
   * Whatever the stock definition carried is discarded, not merged.
   *
   * Prevents the failure mode that a leftover argument in position 0 would cause:
   * SymfonyAiReasoner would be handed a stranger's service as its registry and
   * fatal on the first turn.
   *
   * @covers ::alter
   */
  public function testStockArgumentsAreReplaced(): void {
    $container = $this->containerWithReasoner('Drupal\flowdrop\NullChatReasoner', [
      new Reference('some.other.service'),
    ]);

    (new AincientCoreServiceProvider())->alter($container);

    $arguments = $container->getDefinition('flowdrop.chat_reasoner')->getArguments();
    $this->assertNotContains(new Reference('some.other.service'), $arguments);
    $this->assertCount(7, $arguments);
  }

  /**
   * With no reasoner service at all, alter() is a no-op.
   *
   * Prevents aincient_core becoming uninstallable-adjacent: FlowDrop absent (or
   * a build where that service is gone) must not throw a
   * ServiceNotFoundException out of container compilation, which takes the whole
   * site down rather than degrading one feature.
   *
   * @covers ::alter
   */
  public function testMissingReasonerDefinitionIsIgnored(): void {
    $container = new ContainerBuilder();

    (new AincientCoreServiceProvider())->alter($container);

    $this->assertFalse($container->hasDefinition('flowdrop.chat_reasoner'));
  }

  /**
   * A container holding the services the provider looks for.
   *
   * @param string $reasonerClass
   *   Class to register for `flowdrop.chat_reasoner`.
   * @param array $reasonerArguments
   *   Its arguments before the alter.
   */
  private function containerWithReasoner(string $reasonerClass = 'Drupal\flowdrop\NullChatReasoner', array $reasonerArguments = []): ContainerBuilder {
    $container = new ContainerBuilder();
    $container->register('flowdrop.chat_reasoner', $reasonerClass)
      ->setArguments($reasonerArguments);
    foreach ([
      'aincient_core.inference.registry',
      'aincient_core.inference.model_targets',
      'aincient_core.inference.message_mapper',
      'aincient_core.inference.tool_schema',
      'logger.channel.aincient_core',
    ] as $id) {
      $container->register($id, 'stdClass');
    }
    return $container;
  }

}
