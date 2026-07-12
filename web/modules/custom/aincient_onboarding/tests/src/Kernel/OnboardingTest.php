<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_onboarding\Kernel;

use Drupal\aincient_onboarding\ProviderConnector;
use Drupal\ai\Service\FunctionCalling\FunctionCallPluginManager;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Symfony\Component\HttpFoundation\Request;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the deterministic onboarding layer: the panel widget + the key store.
 *
 * Validation against Anthropic is a live HTTP call, so it is NOT exercised
 * here (only the empty-key short-circuit is). Persistence is verified
 * end-to-end: the key lands in Drupal State, the key entity is flipped to the
 * state provider (so the secret never touches config), the chat model is
 * pinned, and the completion flag is set — together flipping needsOnboarding()
 * to FALSE.
 *
 * @group aincient
 */
#[RunTestsInSeparateProcesses]
final class OnboardingTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'key',
    'ai',
    // The default chat provider — enabled so the connector can resolve the
    // 'anthropic' plugin (usability checks) and its config schema is present.
    'ai_provider_anthropic',
    // The role layer the connector now binds through (provides the resolver).
    'aincient_core',
    'aincient_onboarding',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    // persist() creates/flips a per-provider key entity ("<provider>_default_key").
    // Pre-create the anthropic one with the env provider so persist() has a live
    // entity to flip to the state provider.
    $this->container->get('entity_type.manager')->getStorage('key')->create([
      'id' => 'anthropic_default_key',
      'label' => 'Anthropic Default Key',
      'key_type' => 'authentication',
      'key_provider' => 'env',
      'key_provider_settings' => ['env_variable' => 'AINCIENT_TEST_NO_SUCH_ENV'],
    ])->save();
    // A real (non-superuser) admin so the panel's permission check is honest.
    $this->setCurrentUser($this->createUser(['administer site configuration']));
  }

  /**
   * The onboarding key store under test.
   */
  private function store(): ProviderConnector {
    return $this->container->get('aincient_onboarding.provider_connector');
  }

  /**
   * The AI function-call plugin manager (resolves the onboarding capability).
   */
  private function manager(): FunctionCallPluginManager {
    return $this->container->get('plugin.manager.ai.function_calls');
  }

  /**
   * Run the onboarding capability and return its readable output.
   */
  private function runPanel(): string {
    /** @var \Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface $tool */
    $tool = $this->manager()->createInstance('aincient_onboarding:onboarding_panel');
    $tool->execute();
    return $tool->getReadableOutput();
  }

  /**
   * The panel capability emits a well-formed onboarding widget envelope.
   *
   * The card is provider-neutral: it carries the target provider (the first
   * installed chat provider here — Anthropic) and its auth shape, and lets the
   * role layer pick a model per role on connect, so it no longer ships a curated
   * model list.
   */
  public function testPanelEmitsWidgetEnvelope(): void {
    $out = $this->runPanel();
    $envelope = json_decode($out, TRUE);
    $this->assertIsArray($envelope);
    $this->assertSame('onboarding', $envelope['__widget__']);
    $this->assertNotEmpty($envelope['summary']);
    $payload = $envelope['payload'];
    $this->assertStringContainsString('/aincient/onboarding/save', $payload['saveUrl']);
    // Provider-neutral payload: the resolved provider + its auth shape, no
    // hardcoded model catalogue.
    $this->assertSame('anthropic', $payload['provider']);
    $this->assertNotEmpty($payload['providerLabel']);
    $this->assertSame('api_key', $payload['auth']);
    $this->assertArrayNotHasKey('models', $payload);
    // Fresh site: not configured yet.
    $this->assertFalse($payload['configured']);
  }

  /**
   * A user without site-config permission gets a refusal, not a widget.
   */
  public function testPanelRefusesWithoutPermission(): void {
    $this->setCurrentUser($this->createUser());
    $out = $this->runPanel();
    $this->assertStringStartsWith('Error:', $out);
    $this->assertStringNotContainsString('__widget__', $out);
  }

  /**
   * A fresh site (no key, no flag) is reported as needing onboarding.
   */
  public function testNeedsOnboardingReflectsKeyAndFlag(): void {
    // Fresh: env points at a missing variable + no flag → needs onboarding.
    $this->assertTrue($this->store()->needsOnboarding());
    $this->assertFalse($this->store()->isConfigured());
  }

  /**
   * Connecting rejects an empty credential without touching the network.
   */
  public function testConnectRejectsEmptyCredential(): void {
    $result = $this->store()->connect('anthropic', '   ');
    $this->assertFalse($result['ok']);
    $this->assertNotEmpty($result['message']);
  }

  /**
   * connectAndStore rejects an empty credential without touching the network.
   */
  public function testConnectAndStoreRejectsEmptyCredential(): void {
    $result = $this->store()->connectAndStore('anthropic', '  ');
    $this->assertFalse($result['ok']);
    $this->assertNotEmpty($result['message']);
    $this->assertSame(['chat' => [], 'image' => []], $result['models']);
    $this->assertSame([], $result['suggested']);
    // A failed connect leaves the site unconfigured.
    $this->assertFalse((bool) $this->container->get('state')->get(ProviderConnector::STATE_COMPLETED));
  }

  /**
   * finalizeRoles binds each role (across providers), projects, and completes.
   *
   * The multi-provider finish: chat on one provider, image on another. It needs
   * no live round-trip (credentials are stored earlier by connectAndStore), so
   * the binding + projection + completion are all verifiable here.
   */
  public function testFinalizeRolesBindsAcrossProvidersAndCompletes(): void {
    $result = $this->store()->finalizeRoles([
      'task' => ['provider_id' => 'anthropic', 'model_id' => 'claude-x'],
      'image' => ['provider_id' => 'nanobanana', 'model_id' => 'gemini-2.5-flash-image'],
      // Unknown roles + empty bindings are ignored, not fatal.
      'bogus' => ['provider_id' => 'x', 'model_id' => 'y'],
      'fast' => ['provider_id' => '', 'model_id' => ''],
    ]);
    $this->assertTrue($result['ok']);

    $this->assertTrue((bool) $this->container->get('state')->get(ProviderConnector::STATE_COMPLETED));

    // The task role drives the default chat provider through projection.
    $providers = $this->container->get('config.factory')->get('ai.settings')->get('default_providers');
    $this->assertSame('anthropic', $providers['chat']['provider_id']);
    $this->assertSame('claude-x', $providers['chat']['model_id']);

    // The image role is bound independently to the image provider.
    $roles = $this->container->get('config.factory')->get('aincient_core.model_roles')->get('roles');
    $this->assertSame('nanobanana', $roles['image']['provider_id']);
    $this->assertSame('gemini-2.5-flash-image', $roles['image']['model_id']);
    $this->assertArrayNotHasKey('bogus', $roles);

    $this->assertTrue($this->store()->isConfigured());
    $this->assertFalse($this->store()->needsOnboarding());
  }

  /**
   * finalizeRoles refuses when no valid binding is supplied — nothing completes.
   */
  public function testFinalizeRolesRejectsNoBindings(): void {
    $result = $this->store()->finalizeRoles([]);
    $this->assertFalse($result['ok']);
    $this->assertNotEmpty($result['message']);
    $this->assertFalse((bool) $this->container->get('state')->get(ProviderConnector::STATE_COMPLETED));
  }

  /**
   * The multi-connect catalog carries per-capability flags and an auth shape.
   */
  public function testCatalogProvidersCarryCapabilities(): void {
    $rows = $this->container->get('aincient_onboarding.provider_catalog')->providers();
    $byId = array_column($rows, NULL, 'id');
    $this->assertArrayHasKey('anthropic', $byId);
    $this->assertTrue($byId['anthropic']['capabilities']['chat']);
    $this->assertSame('api_key', $byId['anthropic']['auth']);
    // OpenRouter is hidden from onboarding (and not installed here anyway).
    $this->assertArrayNotHasKey('openrouter', $byId);
  }

  /**
   * Persisting a key stores it in State, flips the provider, and pins a model.
   */
  public function testPersistStoresKeyInStateAndFlipsProvider(): void {
    $this->store()->persist('anthropic', 'sk-ant-test-key', 'claude-opus-4-1-20250805');

    $state = $this->container->get('state');
    // The secret lives in State, not config.
    $this->assertSame('sk-ant-test-key', $state->get('aincient.anthropic_api_key'));
    $this->assertTrue((bool) $state->get(ProviderConnector::STATE_COMPLETED));

    // The key entity now reads from State.
    $key = $this->container->get('entity_type.manager')->getStorage('key')->load('anthropic_default_key');
    $this->assertSame('state', $key->getKeyProvider()->getPluginId());
    $this->assertSame('sk-ant-test-key', $key->getKeyValue());

    // The chosen chat model is pinned in ai.settings.
    $providers = $this->container->get('config.factory')->get('ai.settings')->get('default_providers');
    $this->assertSame('anthropic', $providers['chat']['provider_id']);
    $this->assertSame('claude-opus-4-1-20250805', $providers['chat']['model_id']);

    // With a key now resolvable + the flag set, onboarding is done.
    $this->assertTrue($this->store()->isConfigured());
    $this->assertFalse($this->store()->needsOnboarding());
  }

  /**
   * Run the console settings-alter hook with the given request on the stack.
   *
   * @return array
   *   The altered settings.
   */
  private function alterConsoleSettings(Request $request): array {
    $this->container->get('request_stack')->push($request);
    try {
      $settings = [];
      $this->container->get('module_handler')->alter('aincient_console_settings', $settings);
      return $settings;
    }
    finally {
      $this->container->get('request_stack')->pop();
    }
  }

  /**
   * A configured site does not surface the wizard — unless `?onboarding=1`.
   *
   * This is the idempotency lever: an admin can re-run the whole wizard against
   * an already-connected site (to test it) without first resetting state.
   */
  public function testForceQueryParamReshowsWizardWhenConfigured(): void {
    // Connect AI + bind a role, so the site is configured with a known binding.
    $this->store()->persist('anthropic', 'sk-ant-test-key');
    $this->container->get('aincient_core.model_role_resolver')->bind('task', 'anthropic', 'claude-x');
    $this->assertFalse($this->store()->needsOnboarding());

    // Plain request (admin, configured): the wizard is NOT shown (no `needed`),
    // but the re-entry pointer IS emitted so the user menu can offer it (Law 14).
    $plain = $this->alterConsoleSettings(Request::create('/aincient'));
    $this->assertArrayHasKey('onboarding', $plain);
    $this->assertArrayNotHasKey('needed', $plain['onboarding']);
    $this->assertTrue($plain['onboarding']['canReenter']);

    // `?onboarding=1` as an admin: the wizard is forced back on, marked as a
    // re-run, and pre-filled with the existing bindings (so a no-op finish is safe).
    $forced = $this->alterConsoleSettings(Request::create('/aincient', 'GET', ['onboarding' => '1']));
    $this->assertTrue($forced['onboarding']['needed']);
    $this->assertTrue($forced['onboarding']['forced']);
    $this->assertSame('anthropic:claude-x', $forced['onboarding']['current']['task']);

    // The override is admin-only: a user without the permission can't force it,
    // and gets no re-entry pointer either.
    $this->setCurrentUser($this->createUser());
    $denied = $this->alterConsoleSettings(Request::create('/aincient', 'GET', ['onboarding' => '1']));
    $this->assertArrayNotHasKey('onboarding', $denied);
    $plainNonAdmin = $this->alterConsoleSettings(Request::create('/aincient'));
    $this->assertArrayNotHasKey('onboarding', $plainNonAdmin);
  }

  /**
   * A re-run's finalize is a keyed MERGE — it never clobbers what it didn't touch.
   *
   * The Law-14 guard: an earlier run bound the reasoning role; a re-run that only
   * re-submits the task role must leave the reasoning binding intact.
   */
  public function testFinalizeRolesMergesAndPreservesUntouchedBindings(): void {
    $resolver = $this->container->get('aincient_core.model_role_resolver');
    // An earlier run bound reasoning.
    $resolver->bind('reasoning', 'anthropic', 'claude-reasoning');

    // A re-run re-submits only the task role.
    $result = $this->store()->finalizeRoles([
      'task' => ['provider_id' => 'anthropic', 'model_id' => 'claude-task'],
    ]);
    $this->assertTrue($result['ok']);

    $roles = $this->container->get('config.factory')->get('aincient_core.model_roles')->get('roles');
    // The re-submitted role is bound…
    $this->assertSame('claude-task', $roles['task']['model_id']);
    // …and the pre-bound reasoning role SURVIVED the merge (not wiped).
    $this->assertSame('claude-reasoning', $roles['reasoning']['model_id']);
  }

}
