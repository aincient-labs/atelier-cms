<?php

declare(strict_types=1);

namespace Drupal\aincient_core;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Drupal\aincient_core\Service\AincientModelService;
use Drupal\aincient_core\Service\Reasoning\AincientChatReasoner;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Swaps FlowDrop's AI services for the role-aware AIncient subclasses.
 *
 * Two re-points, both in alter() rather than Symfony `decorates:` so each
 * subclass can call parent:: for everything it doesn't override (the clean
 * pattern when the decorated service is a concrete class with no interface),
 * and both guarded by hasDefinition() so aincient_core stays installable
 * without the FlowDrop AI provider:
 *   - flowdrop_ai_provider.model_service → AincientModelService (keeping its
 *     constructor arguments) + a setter call for the model-role resolver, so
 *     the per-node select offers AIncient roles and selecting one resolves the
 *     bound model.
 *   - flowdrop.chat_reasoner → AincientChatReasoner (keeping its constructor
 *     arguments), so the native reason node's Model Role dropdown advertises
 *     those same roles as valid operation types.
 */
class AincientCoreServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container): void {
    if (!$container->hasDefinition('flowdrop_ai_provider.model_service')) {
      // flowdrop_ai_provider not installed — nothing to route.
      return;
    }

    $definition = $container->getDefinition('flowdrop_ai_provider.model_service');
    $definition->setClass(AincientModelService::class);
    $definition->addMethodCall('setRoleResolver', [
      new Reference('aincient_core.model_role_resolver'),
    ]);

    // Re-point the reasoning backend at the role-aware subclass so the native
    // reason node advertises AIncient roles. Same guard + keep-the-args pattern
    // as the model service above; the reasoner's constructor arg is the
    // (now role-aware) model_service we just re-pointed.
    if ($container->hasDefinition('flowdrop.chat_reasoner')) {
      $container->getDefinition('flowdrop.chat_reasoner')
        ->setClass(AincientChatReasoner::class);
    }
  }

}
