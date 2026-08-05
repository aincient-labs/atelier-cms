<?php

declare(strict_types=1);

namespace Drupal\aincient_core;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Drupal\aincient_core\Inference\SymfonyAiReasoner;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Points FlowDrop's reasoning seam at Atelier's own inference backend.
 *
 * The re-point happens in alter() rather than via Symfony `decorates:` so the
 * replacement can bring its own constructor arguments (the clean pattern when
 * the decorated service is a concrete class), and it is guarded by
 * hasDefinition() so aincient_core stays installable — and unit-testable —
 * without FlowDrop present at all.
 */
class AincientCoreServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container): void {
    $this->bindReasoner($container);
  }

  /**
   * Binds `flowdrop.chat_reasoner` to {@see SymfonyAiReasoner}.
   *
   * FlowDrop core documents `ChatReasonerInterface` as `@api` and its `reason`
   * node depends only on that plus core's own neutral DTOs, so the concrete
   * binding belongs to whoever owns the AI dependency. This is where the agent
   * loop's reasoning actually comes from.
   *
   * FlowDrop core defines the service as a zero-argument NullChatReasoner, so
   * the arguments are REPLACED, not kept.
   */
  private function bindReasoner(ContainerBuilder $container): void {
    if (!$container->hasDefinition('flowdrop.chat_reasoner')) {
      return;
    }

    $container->getDefinition('flowdrop.chat_reasoner')
      ->setClass(SymfonyAiReasoner::class)
      ->setArguments([
        new Reference('aincient_core.inference.registry'),
        new Reference('aincient_core.inference.model_targets'),
        new Reference('aincient_core.inference.message_mapper'),
        new Reference('aincient_core.inference.tool_schema'),
        new Reference('aincient_core.inference.result_unpacker'),
        // The metering recorder. This list is the LIVE agent-turn wiring — the
        // `aincient_core.inference.reasoner` definition in the .yml is only ever
        // instantiated directly by tests — so a constructor argument added there
        // and not here is an agent loop that records nothing, which is the exact
        // regression this argument repairs.
        new Reference('aincient_core.usage_recorder'),
        new Reference('logger.channel.aincient_core'),
        // The retrying call wrapper. Same rule again, and this time the omission
        // actually shipped: added to the .yml definition and not here, it made
        // every live agent turn a TypeError — the dispatcher below landed in the
        // ProviderCall slot — while the tests, which build from the .yml, stayed
        // green. Positional arguments cannot be trusted to fail loudly here.
        new Reference('aincient_core.inference.provider_call'),
        // The event dispatcher, for InferenceStartedEvent. Same rule as the
        // recorder above: THIS list is the live agent-turn wiring, so leaving it
        // out here would mean the one call that takes ~50s announces itself in
        // tests and nowhere else — a console silent for the whole wait.
        new Reference('event_dispatcher'),
      ]);
  }

}
