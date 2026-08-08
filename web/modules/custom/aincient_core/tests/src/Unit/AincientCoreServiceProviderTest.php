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
      // The retrying call wrapper. Added to the .yml and to the constructor in
      // 0326 and NOT here, which shipped a v0.4.1 whose reasoner could not be
      // constructed at all (DECISIONS 0331).
      'aincient_core.inference.provider_call',
      // The trust-the-wire codec (Phase 4): re-reads a tool call the config
      // bridge dropped from a lying gateway's raw body. Same hazard as the
      // recorder and ProviderCall — wired here because THIS list is the live
      // agent loop, so an omission would recover in tests and nowhere else.
      'aincient_core.inference.tool_call_codec',
      // The dispatcher that announces InferenceStartedEvent. Wired here as well
      // as in the .yml because THIS list is the live agent loop: without it the
      // one call that takes ~50s would report itself starting in tests only,
      // leaving the console silent for the whole wait.
      'event_dispatcher',
    ], $ids);
  }

  /**
   * Every constructor parameter is actually supplied by the live wiring.
   *
   * The guard the list above could not be. That assertion compares the provider
   * against a hardcoded expectation, so when SymfonyAiReasoner grew a
   * ProviderCall parameter in 0326 and neither was updated, the two agreed with
   * each other at the wrong value and stayed green — while the shipped product
   * had no working chat (DECISIONS 0331). A hardcoded list can only catch an
   * argument someone edited, never one the class grew elsewhere.
   *
   * This compares against the constructor's real signature via reflection, so a
   * new parameter fails here the moment it is added, in the only place that
   * matters: the positional list the runtime container actually builds. The
   * container binds by position and any two services satisfy each other's slots
   * until PHP checks the type at construction, so nothing later will complain.
   *
   * @covers ::alter
   */
  public function testEveryConstructorParameterIsWired(): void {
    $container = $this->containerWithReasoner();

    (new AincientCoreServiceProvider())->alter($container);

    $expected = (new \ReflectionClass(SymfonyAiReasoner::class))
      ->getConstructor()
      ->getNumberOfParameters();

    $this->assertCount(
      $expected,
      $container->getDefinition('flowdrop.chat_reasoner')->getArguments(),
      sprintf(
        'SymfonyAiReasoner takes %d constructor parameters, so the live wiring in '
        . 'AincientCoreServiceProvider must pass %d arguments. A parameter added to '
        . 'the class (and to the test-only .yml definition) but not to that '
        . 'positional list is a TypeError on every agent turn — see DECISIONS 0331.',
        $expected,
        $expected,
      ),
    );
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
    $this->assertCount(10, $arguments);
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
      'aincient_core.inference.provider_call',
      'logger.channel.aincient_core',
      'event_dispatcher',
    ] as $id) {
      $container->register($id, 'stdClass');
    }
    return $container;
  }

}
