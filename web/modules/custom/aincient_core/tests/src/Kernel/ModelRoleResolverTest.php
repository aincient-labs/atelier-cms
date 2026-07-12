<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_core\Kernel;

use Drupal\aincient_core\ModelRoleResolver;
use Drupal\aincient_core\ModelRoles;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests the provider-neutral model-role layer.
 *
 * The role registry is AIncient's seam over drupal/ai: an operator binds the
 * semantic roles (reasoning/task/fast) to concrete `provider:model` pairs, and
 * the resolver projects them onto drupal/ai's operation-type defaults so stock
 * FlowDrop inherits them. These tests pin that contract: neutral-by-default,
 * the resolve() fallback chain, project() mapping, and per-provider suggestions.
 *
 * @group aincient
 * @coversDefaultClass \Drupal\aincient_core\ModelRoleResolver
 */
final class ModelRoleResolverTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    // Provides the ai.provider service + ai.settings config schema that
    // project() writes operation-type defaults into.
    'ai',
    'aincient_core',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['ai', 'aincient_core']);
  }

  private function resolver(): ModelRoleResolver {
    return $this->container->get('aincient_core.model_role_resolver');
  }

  /**
   * A fresh install ships the taxonomy but binds nothing (fully neutral).
   */
  public function testNeutralByDefault(): void {
    $roles = $this->resolver()->roles();
    // All three roles are defined, with labels, and none is bound.
    $this->assertSame(
      [ModelRoles::REASONING, ModelRoles::TASK, ModelRoles::FAST],
      array_keys($roles),
    );
    foreach ($roles as $row) {
      $this->assertNotEmpty($row['label']);
      $this->assertSame('', $row['provider_id']);
      $this->assertSame('', $row['model_id']);
    }
    $this->assertSame(ModelRoles::TASK, $this->resolver()->defaultRole());
    $this->assertTrue($roles[ModelRoles::TASK]['is_default']);
    // Nothing bound + no provider defaults ⇒ resolve yields empty (neutral).
    $this->assertSame(
      ['provider_id' => '', 'model_id' => ''],
      $this->resolver()->resolve(ModelRoles::REASONING),
    );
  }

  /**
   * resolve() returns a role's own binding, and falls back to the default role.
   */
  public function testBindAndResolveFallbackChain(): void {
    $r = $this->resolver();
    $r->bind(ModelRoles::REASONING, 'anthropic', 'big-model');
    $r->bind(ModelRoles::TASK, 'openai', 'small-model');

    // 1. Own binding.
    $this->assertSame(
      ['provider_id' => 'anthropic', 'model_id' => 'big-model'],
      $r->resolve(ModelRoles::REASONING),
    );
    // 2. Unbound role (fast) → falls back to the default role (task).
    $this->assertSame(
      ['provider_id' => 'openai', 'model_id' => 'small-model'],
      $r->resolve(ModelRoles::FAST),
    );
    // Unknown role string is treated as the default role too.
    $this->assertSame(
      ['provider_id' => 'openai', 'model_id' => 'small-model'],
      $r->resolve('nonsense'),
    );
  }

  /**
   * project() writes each role's binding onto its operation-type defaults.
   */
  public function testProjectWritesOperationDefaults(): void {
    $r = $this->resolver();
    $r->bind(ModelRoles::REASONING, 'anthropic', 'reason-model');
    $r->bind(ModelRoles::TASK, 'anthropic', 'task-model');
    $r->project();

    $defaults = $this->config('ai.settings')->get('default_providers');
    // task → chat + image vision.
    $this->assertSame(['provider_id' => 'anthropic', 'model_id' => 'task-model'], $defaults['chat']);
    $this->assertSame(['provider_id' => 'anthropic', 'model_id' => 'task-model'], $defaults['chat_with_image_vision']);
    // reasoning → complex/structured/tools.
    $this->assertSame(['provider_id' => 'anthropic', 'model_id' => 'reason-model'], $defaults['chat_with_complex_json']);
    $this->assertSame(['provider_id' => 'anthropic', 'model_id' => 'reason-model'], $defaults['chat_with_structured_response']);
    $this->assertSame(['provider_id' => 'anthropic', 'model_id' => 'reason-model'], $defaults['chat_with_tools']);
  }

  /**
   * suggestForProvider() picks per-role models from the tier hints, else first.
   */
  public function testSuggestForProviderUsesTierHints(): void {
    $models = [
      'claude-opus-4-5' => 'Opus',
      'claude-sonnet-4-5' => 'Sonnet',
      'claude-haiku-4-5' => 'Haiku',
    ];
    $sugg = $this->resolver()->suggestForProvider('anthropic', $models);
    $this->assertSame('claude-opus-4-5', $sugg[ModelRoles::REASONING]);
    $this->assertSame('claude-sonnet-4-5', $sugg[ModelRoles::TASK]);
    $this->assertSame('claude-haiku-4-5', $sugg[ModelRoles::FAST]);

    // An unknown provider has no hints ⇒ every role gets the first model.
    $sugg2 = $this->resolver()->suggestForProvider('mystery', $models);
    $this->assertSame('claude-opus-4-5', $sugg2[ModelRoles::TASK]);
  }

}
