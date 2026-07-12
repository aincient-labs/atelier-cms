<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_core\Kernel;

use Drupal\aincient_core\ModelRoles;
use Drupal\aincient_core\Service\AincientModelService;
use Drupal\aincient_core\Service\Reasoning\AincientChatReasoner;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests the role-aware decorator over flowdrop_ai_provider.model_service.
 *
 * AincientCoreServiceProvider swaps FlowDrop's model service for
 * AincientModelService so a chat/agent node's "operation type" select offers
 * ONLY AIncient model roles (reasoning / task / fast) and resolves each through
 * the model-role bindings. This pins the swap + the editor-option contract; the
 * full resolveModel() chain (which needs a registered AI provider to return a
 * model config) is exercised live and via the resolver's own kernel test.
 *
 * @group aincient
 * @coversDefaultClass \Drupal\aincient_core\Service\AincientModelService
 */
final class AincientModelServiceTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'ai',
    'flowdrop',
    'flowdrop_ai_provider',
    'aincient_core',
  ];

  /**
   * The service provider swaps the FlowDrop model service for our subclass.
   */
  public function testServiceIsDecorated(): void {
    $service = $this->container->get('flowdrop_ai_provider.model_service');
    $this->assertInstanceOf(AincientModelService::class, $service);
  }

  /**
   * The per-node operation-type select offers exactly the AIncient roles.
   */
  public function testOperationTypeOptionsAreRolesOnly(): void {
    $service = $this->container->get('flowdrop_ai_provider.model_service');
    $options = $service->getOperationTypeOptions('chat');

    $values = array_column($options, 'value');
    $this->assertSame([
      AincientModelService::ROLE_PREFIX . ModelRoles::REASONING,
      AincientModelService::ROLE_PREFIX . ModelRoles::TASK,
      AincientModelService::ROLE_PREFIX . ModelRoles::FAST,
    ], $values);

    // Every option carries the role's human label.
    $labels = array_column($options, 'label');
    foreach ($labels as $label) {
      $this->assertNotEmpty($label);
    }
  }

  /**
   * getDefaultModelForOperationType() resolves an AIncient role's bound model.
   *
   * This is the seam the native reason node's backend resolves a node's model
   * through when its Model field is empty (see AincientChatReasoner). It must
   * treat an `aincient_role:*` operation type like resolveModel() does.
   *
   * @covers ::getDefaultModelForOperationType
   */
  public function testDefaultModelForOperationTypeResolvesRole(): void {
    $this->container->get('aincient_core.model_role_resolver')
      ->bind(ModelRoles::REASONING, 'anthropic', 'reason-model');

    $service = $this->container->get('flowdrop_ai_provider.model_service');
    $this->assertSame(
      'reason-model',
      $service->getDefaultModelForOperationType(AincientModelService::ROLE_PREFIX . ModelRoles::REASONING),
    );
  }

  /**
   * The reasoning backend is swapped for the role-aware subclass.
   */
  public function testChatReasonerIsSwapped(): void {
    $reasoner = $this->container->get('flowdrop.chat_reasoner');
    $this->assertInstanceOf(AincientChatReasoner::class, $reasoner);
  }

  /**
   * The reason node's Model Role options are exactly the AIncient roles.
   *
   * Without this widening the native reason node's operation-type enum is just
   * `chat`, so a node stored with `aincient_role:task` would carry a value the
   * ParameterResolver rejects before the node runs.
   *
   * @covers \Drupal\aincient_core\Service\Reasoning\AincientChatReasoner::getModelChoices
   */
  public function testReasonerModelChoicesAdvertiseRoles(): void {
    $choices = $this->container->get('flowdrop.chat_reasoner')->getModelChoices('chat');

    $values = array_column($choices->getOperationTypeOptions(), 'value');
    $this->assertSame([
      AincientModelService::ROLE_PREFIX . ModelRoles::REASONING,
      AincientModelService::ROLE_PREFIX . ModelRoles::TASK,
      AincientModelService::ROLE_PREFIX . ModelRoles::FAST,
    ], $values);
  }

}
