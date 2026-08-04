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
    'aincient_core',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['aincient_core']);
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
   * An unbound role resolves to nothing — there is no outside fallback left.
   *
   * The chain used to have two more links, reading drupal/ai's operation-type
   * defaults when no role was bound, and this test used to prove they were gone
   * by seeding `ai.settings` with a provider the roles did not have. Both the
   * config and the module that owned it are now uninstalled, so the seeding step
   * has nothing to seed — but the invariant it guarded is the point and outlives
   * it: an unbound role stays unbound rather than resolving to something that
   * would fail later with a worse error.
   */
  public function testUnboundRoleResolvesToNothing(): void {
    $this->assertSame(
      ['provider_id' => '', 'model_id' => ''],
      $this->resolver()->resolve(ModelRoles::TASK),
    );
  }

  /**
   * project() no longer writes drupal/ai operation-type defaults.
   *
   * A regression guard, not a behaviour test: the write is gone, and the way it
   * would come back is someone restoring the "project the bindings onto the
   * framework" half without noticing it has had no reader since the
   * `ai_provider_*` modules went. Binding then projecting must leave no
   * `ai.settings` object behind — `getEditable()->save()` would create one even
   * with the module uninstalled, which is exactly the silent resurrection to
   * catch.
   */
  public function testProjectDoesNotWriteFrameworkDefaults(): void {
    $r = $this->resolver();
    $r->bind(ModelRoles::REASONING, 'anthropic', 'reason-model');
    $r->bind(ModelRoles::TASK, 'anthropic', 'task-model');
    $r->project();

    $this->assertNull($this->config('ai.settings')->get('default_providers'));
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

  /**
   * A direct chat-completions vendor tiers apart, rather than collapsing.
   *
   * `openai_compatible` is how every non-proxy chat-completions vendor is
   * reached, and its needles used to name only the frontier labs' families — so
   * a DeepSeek or Kimi or GLM catalogue matched nothing, every role fell to
   * first-in-pool, and the three tiers resolved to one model. What this pins is
   * that property, not the individual model ids: the roles must differ where the
   * vendor actually offers a cheaper line.
   *
   * @dataProvider directVendorCatalogues
   */
  public function testSuggestForProviderTiersDirectVendors(array $models, string $reasoning, string $task, string $fast): void {
    $sugg = $this->resolver()->suggestForProvider('openai_compatible', array_combine($models, $models));
    $this->assertSame($reasoning, $sugg[ModelRoles::REASONING]);
    $this->assertSame($task, $sugg[ModelRoles::TASK]);
    $this->assertSame($fast, $sugg[ModelRoles::FAST]);
    // The catalogue order must not be what decided it.
    $this->assertNotSame([$models[0], $models[0], $models[0]], [$reasoning, $task, $fast]);
  }

  /**
   * Catalogues as each vendor's own /models endpoint lists them.
   *
   * DeepSeek is the deliberate exception to "the roles differ": it publishes two
   * models, so the cheap role shares the chat one — the assertion that matters
   * there is that REASONING still lands on the reasoner.
   */
  public static function directVendorCatalogues(): array {
    return [
      'deepseek' => [
        ['deepseek-chat', 'deepseek-reasoner'],
        'deepseek-reasoner', 'deepseek-chat', 'deepseek-chat',
      ],
      'kimi (moonshot)' => [
        ['kimi-k3', 'kimi-k2.7-code', 'kimi-k2.6', 'kimi-k2.5'],
        'kimi-k3', 'kimi-k2.6', 'kimi-k2.5',
      ],
      'glm (z.ai)' => [
        ['glm-5.2', 'glm-4.7', 'glm-4.7-flash', 'glm-4.6', 'glm-4.5-air'],
        'glm-5.2', 'glm-4.7', 'glm-4.7-flash',
      ],
      'qwen' => [
        ['qwen3-max', 'qwen-plus', 'qwen3-coder', 'qwen-turbo', 'qwen-flash'],
        'qwen3-max', 'qwen-plus', 'qwen-turbo',
      ],
      // The proxy shape the entry was written for, unchanged by the vendor
      // families being appended behind the frontier needles.
      'litellm-style proxy' => [
        ['deepseek/deepseek-chat', 'anthropic/claude-opus-5', 'anthropic/claude-sonnet-5', 'anthropic/claude-haiku-4-5'],
        'anthropic/claude-opus-5', 'anthropic/claude-sonnet-5', 'anthropic/claude-haiku-4-5',
      ],
    ];
  }

  /**
   * The two multi-vendor ids share one needle set, not two copies.
   */
  public function testMultiVendorHintsAreShared(): void {
    $hints = ModelRoles::tierHints();
    $this->assertSame($hints['litellm'], $hints['openai_compatible']);
  }

}
