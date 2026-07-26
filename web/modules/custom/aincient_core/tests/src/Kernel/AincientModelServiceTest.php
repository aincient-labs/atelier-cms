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
    // `ai` hard-depends on it and every provider resolves its key through it;
    // kernel tests don't install dependencies transitively.
    'key',
    'ai',
    'flowdrop',
    'flowdrop_ai_provider',
    'aincient_core',
    // Registers the `echoai` provider, which enumerates `gpt-test`/`gpt-awesome`
    // and reports itself usable unconditionally — the stand-in for a provider
    // that claims a model name without the operator having connected it.
    'ai_test',
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
   * A bound model stays on its provider even when another one claims the name.
   *
   * The regression: the parent resolves a provider by looking a bare model id up
   * in a map merged from every provider's catalogue, last writer winning. A
   * provider that enumerates without a key (OpenRouter's model list is public,
   * Anthropic's is cached) is in that merge on a proxy-only site and takes over
   * any name it shares with the proxy. The request then goes out with no
   * Authorization header at all, because the missing-key failure is logged rather
   * than thrown — the reported "Missing Authentication header", where only a bare
   * `gpt-4` (a name nothing else enumerates) appeared to work.
   *
   * `echoai` plays the claimer here: it serves `gpt-test` and is always usable.
   * Bind that model to a different provider and the binding must win.
   *
   * @covers ::getModel
   */
  public function testBoundProviderWinsOverAnotherProvidersCatalogue(): void {
    $service = $this->container->get('flowdrop_ai_provider.model_service');

    // Precondition: echoai really does claim this model, so the assertion below
    // is about precedence and not about an empty catalogue.
    // Precondition: echoai really does claim this model, so the assertion below
    // is about precedence and not about an empty catalogue.
    $this->assertSame(
      'echoai',
      $service->getModel('gpt-test', 'chat')['provider'] ?? NULL,
      'Unbound, the model resolves to the provider that enumerates it.',
    );

    $this->container->get('aincient_core.model_role_resolver')
      ->bind(ModelRoles::REASONING, 'litellm', 'gpt-test');

    $resolved = $service->getModel('gpt-test', 'chat');
    $this->assertSame('litellm', $resolved['provider'] ?? NULL);
    $this->assertSame('gpt-test', $resolved['id'] ?? NULL);
    $this->assertSame('chat', $resolved['operation_type'] ?? NULL);
  }

  /**
   * A model nobody bound still resolves the stock way.
   *
   * The override must not become a gate: an unbound model that a connected
   * provider legitimately serves has to keep resolving through the parent, or
   * a per-node model pick outside the role system would stop working.
   *
   * @covers ::getModel
   */
  public function testUnboundModelStillResolvesThroughTheParent(): void {
    $service = $this->container->get('flowdrop_ai_provider.model_service');

    $this->container->get('aincient_core.model_role_resolver')
      ->bind(ModelRoles::REASONING, 'echoai', 'gpt-test');

    // A sibling model on the same provider was never bound to any role.
    $this->assertSame(
      'echoai',
      $service->getModel('gpt-awesome', 'chat')['provider'] ?? NULL,
    );
    // And a model no provider serves is still absent, not invented.
    $this->assertNull($service->getModel('no-such-model', 'chat'));
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
