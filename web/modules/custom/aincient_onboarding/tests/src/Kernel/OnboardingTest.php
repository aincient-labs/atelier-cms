<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_onboarding\Kernel;

use Drupal\aincient_onboarding\ProviderConnector;
use Drupal\aincient_core\Capability\CapabilityManager;
use Drupal\aincient_core\ModelRoles;
use Drupal\aincient_inference_test\ScriptedAdapter;
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
    // The role layer the connector binds through, and the adapter set the
    // inventory enumerates.
    'aincient_core',
    // A real registered adapter whose credential answers are scripted, so the
    // validate path can be exercised without a live vendor.
    'aincient_inference_test',
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
  private function manager(): CapabilityManager {
    return $this->container->get('plugin.manager.aincient.capabilities');
  }

  /**
   * Run the onboarding capability and return its readable output.
   */
  private function runPanel(): string {
    /** @var \Drupal\aincient_core\Capability\ExecutableCapabilityInterface $tool */
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
    $this->assertStringContainsString('/atelier/onboarding/save', $payload['saveUrl']);
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

    // The task role carries the everyday choice. This used to be asserted one
    // hop away, on the `ai.settings` chat default the role was projected onto;
    // the binding is the thing itself.
    $roles = $this->container->get('config.factory')->get('aincient_core.model_roles')->get('roles');
    $this->assertSame('anthropic', $roles['task']['provider_id']);
    $this->assertSame('claude-x', $roles['task']['model_id']);

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
   * Every provider the wizard offers can be connected AND then served.
   *
   * The picker is a promise. It used to list every installed `drupal/ai` provider,
   * including four with no inference adapter — so an operator could enter a working
   * Mistral key, be told "Connected", and get a console that could not answer. One
   * rule keeps the offer honest and is asserted here rather than assumed: nothing
   * without an adapter appears.
   *
   * There used to be a second rule — nothing whose credential the step cannot
   * COLLECT — which excluded the key-plus-base-URL shape for as long as the step
   * rendered one field. It renders two now, so the rule has nothing left to
   * exclude and the assertion is gone rather than left passing vacuously.
   */
  public function testWizardOffersOnlyProvidersItCanConnectAndServe(): void {
    $inventory = $this->container->get('aincient_core.inference.provider_inventory');
    $rows = $this->container->get('aincient_onboarding.provider_catalog')->providers();

    foreach ($rows as $row) {
      $this->assertTrue(
        $inventory->has($row['id']) || isset(ProviderConnector::KEY_GROUPS[$row['id']]),
        sprintf('The wizard offers "%s", which this site cannot serve.', $row['id']),
      );
    }

    $offered = array_column($rows, 'id');
    // Still out, and for the one reason that remains: no adapter, so nothing on
    // this site can serve it whatever the operator types.
    $this->assertNotContains('openrouter', $offered);
    // `ollama` is the case the rule has to let through rather than trip over: its
    // credential is one field that happens to be a URL and not a secret, so a rule
    // written as "must have an API key" would exclude it. `openai_compatible` is
    // the other end — two fields, both required.
    foreach (['ollama', 'openai', 'mistral', 'openai_compatible'] as $id) {
      $this->assertContains($id, $offered);
    }
  }

  /**
   * OpenAI and Mistral ask for a key, and neither of them claims to draw.
   *
   * These two are the ids `ProviderInventory`'s docblock names as the reason the
   * inventory was rewritten: the old picker offered them because their modules
   * were installed, and binding one threw on the next turn. Now they are offered
   * because an adapter can serve them — so what is asserted is that the row
   * describes them truthfully, credential shape included.
   */
  public function testTheKeyProvidersThatCameBackAskForAKey(): void {
    $byId = array_column(
      $this->container->get('aincient_onboarding.provider_catalog')->providers(),
      NULL,
      'id',
    );

    foreach (['openai', 'mistral'] as $id) {
      $this->assertArrayHasKey($id, $byId);
      $this->assertSame('api_key', $byId[$id]['auth'], sprintf('%s must ask for a key.', $id));
      $this->assertSame('api_key', $this->store()->authType($id));
      $this->assertTrue($byId[$id]['capabilities']['chat']);
      // Neither draws — image capability is a type, and claiming it by accident
      // is what filled the image picker with models that cannot make a picture.
      $this->assertFalse($byId[$id]['capabilities']['image']);
    }
  }

  /**
   * The keyless provider is offered as a host, with no secret asked for.
   *
   * The whole of Ollama's credential is where the server is. If this row ever came
   * back as `api_key` the wizard would render a password field and store the URL
   * as a secret in a Key entity — the shape mismatch that kept Ollama out of the
   * picker in the first place, arriving from the other direction.
   */
  public function testOllamaIsOfferedAsAKeylessHostProvider(): void {
    $rows = $this->container->get('aincient_onboarding.provider_catalog')->providers();
    $byId = array_column($rows, NULL, 'id');

    $this->assertArrayHasKey('ollama', $byId);
    $this->assertSame('host', $this->store()->authType('ollama'));
    $this->assertSame('host', $byId['ollama']['auth']);
    $this->assertTrue($byId['ollama']['capabilities']['chat']);
    $this->assertFalse($byId['ollama']['capabilities']['image']);
  }

  /**
   * Connecting Ollama stores the URL as an endpoint — never as a key.
   *
   * Asserted on a URL that answers nothing, because the interesting half is what
   * does NOT happen: a refused connect must leave no endpoint behind, and no
   * `aincient.ollama_api_key` may ever exist for a provider that has no key.
   */
  public function testAnUnreachableOllamaConnectsNothing(): void {
    $state = $this->container->get('state');

    $result = $this->store()->connectAndStore('ollama', 'http://127.0.0.1:1/');

    $this->assertFalse($result['ok']);
    $this->assertStringContainsString('Ollama', $result['message']);
    $this->assertNull($state->get('aincient.ollama_endpoint'));
    $this->assertNull($state->get('aincient.ollama_api_key'));
  }

  /**
   * The two-field provider is offered, and says so in its row.
   *
   * `auth` is what the connect step renders from, so this row is the whole reason
   * a second field appears. If it ever came back as plain `api_key` the wizard
   * would collect a key, store it alone, and leave the operator with a provider
   * that has nowhere to send it — which is precisely why this shape spent two
   * releases hidden from the picker rather than offered half-working.
   */
  public function testTheKeyPlusEndpointProviderAsksForBoth(): void {
    $byId = array_column(
      $this->container->get('aincient_onboarding.provider_catalog')->providers(),
      NULL,
      'id',
    );

    $this->assertArrayHasKey('openai_compatible', $byId);
    $this->assertSame('api_key_endpoint', $byId['openai_compatible']['auth']);
    $this->assertSame('api_key_endpoint', $this->store()->authType('openai_compatible'));
    $this->assertTrue($byId['openai_compatible']['capabilities']['chat']);
  }

  /**
   * A key with no base URL is refused, and stores neither half.
   *
   * The connect endpoint is a POST, so the client-side check is a courtesy and
   * this is the real one. Storing half of what a provider needs — a key with
   * nowhere to send it — is the failure mode the refusal exists to prevent, so
   * what matters as much as the message is that State is untouched.
   */
  public function testConnectingWithoutTheBaseUrlIsRefused(): void {
    $result = $this->store()->connectAndStore('openai_compatible', 'sk-something');

    $this->assertFalse($result['ok']);
    $this->assertStringContainsString('base URL', $result['message']);
    $this->assertNull($this->container->get('state')->get('aincient.openai_compatible_api_key'));
    $this->assertNull($this->container->get('state')->get('aincient.openai_compatible_endpoint'));
  }

  /**
   * Both halves given but nothing at the other end ⇒ nothing stored.
   *
   * Ollama's mirror image, and the same rule: a refused connect leaves no residue.
   * With two values the residue could be partial, which would be worse than none —
   * a stored key against an endpoint that was never reachable reads as configured.
   */
  public function testAnUnreachableCompatibleEndpointConnectsNothing(): void {
    $state = $this->container->get('state');

    $result = $this->store()->connectAndStore('openai_compatible', 'sk-something', 'http://127.0.0.1:1/');

    $this->assertFalse($result['ok']);
    $this->assertNull($state->get('aincient.openai_compatible_api_key'));
    $this->assertNull($state->get('aincient.openai_compatible_endpoint'));
  }

  /**
   * Disconnecting a two-field provider clears BOTH halves.
   *
   * Seeded the headless way (`drush state:set`), because that is the install this
   * has to be right for as much as a wizard-connected one — the storage is the
   * same either way. Leaving the endpoint behind would make the provider read as
   * disconnected while still carrying half a configuration.
   */
  public function testDisconnectingClearsTheEndpointAsWellAsTheKey(): void {
    $state = $this->container->get('state');
    $state->set('aincient.openai_compatible_api_key', 'sk-something');
    $state->set('aincient.openai_compatible_endpoint', 'https://api.deepseek.com');

    $this->store()->disconnect('openai_compatible');

    $this->assertNull($state->get('aincient.openai_compatible_api_key'));
    $this->assertNull($state->get('aincient.openai_compatible_endpoint'));
  }

  /**
   * Validation proves a credential that is stored NOWHERE, and writes nothing.
   *
   * The onboarding handshake's first half, and the reason `ProviderInventory`'s
   * `instanceFor()` escape hatch could be deleted: proving a key used to require a
   * live provider instance to push the candidate into
   * (`setAuthentication()`), and for a host provider it required SAVING the URL and
   * rolling it back. Now the credential is an argument. What the product promises is
   * unchanged and is what this asserts: a good credential comes back with models and
   * per-role suggestions, a bad one comes back refused, and neither leaves a trace.
   */
  public function testValidateProvesAnUnstoredCredentialWithoutWriting(): void {
    $state = $this->container->get('state');
    $state->set(ScriptedAdapter::VALID_CREDENTIAL_KEY, 'sk-real');

    $good = $this->store()->validate(ScriptedAdapter::PROVIDER_ID, 'sk-real');
    $this->assertTrue($good['ok']);
    $this->assertArrayHasKey('scripted-chat', $good['models']);
    $this->assertSame('scripted-chat', $good['suggested']['task']);

    $bad = $this->store()->validate(ScriptedAdapter::PROVIDER_ID, 'sk-garbage');
    $this->assertFalse($bad['ok']);
    $this->assertNotEmpty($bad['message']);
    $this->assertSame([], $bad['models']);

    // Neither attempt stored a credential or completed onboarding.
    $this->assertNull($state->get(ScriptedAdapter::CREDENTIAL_KEY));
    $this->assertFalse($this->store()->hasStoredCredential(ScriptedAdapter::PROVIDER_ID));
    $this->assertFalse((bool) $state->get(ProviderConnector::STATE_COMPLETED));
  }

  /**
   * A site is "configured" when a role resolves to a CONNECTED provider.
   *
   * Not when a provider claims to be usable, which is what this asked before: three
   * providers answered TRUE with no key stored, so a keyless site could call itself
   * configured and skip the wizard it needed.
   */
  public function testConfiguredMeansABoundRoleWithAStoredCredential(): void {
    $resolver = $this->container->get('aincient_core.model_role_resolver');
    $resolver->bind('task', ScriptedAdapter::PROVIDER_ID, 'scripted-chat');

    // Bound but keyless is NOT configured — the wizard is still needed.
    $this->assertFalse($this->store()->isConfigured());
    $this->assertTrue($this->store()->needsOnboarding());

    $this->container->get('state')->set(ScriptedAdapter::CREDENTIAL_KEY, 'sk-stored');

    $this->assertTrue($this->store()->isConfigured());
    $this->assertFalse($this->store()->needsOnboarding());
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

    // The chosen chat model is pinned by binding the default role.
    $roles = $this->container->get('config.factory')->get('aincient_core.model_roles')->get('roles');
    $this->assertSame('anthropic', $roles['task']['provider_id']);
    $this->assertSame('claude-opus-4-1-20250805', $roles['task']['model_id']);

    // With a key now resolvable + the flag set, onboarding is done.
    $this->assertTrue($this->store()->isConfigured());
    $this->assertFalse($this->store()->needsOnboarding());
  }

  /**
   * Disconnecting removes the stored key, key entity, and unbinds its roles.
   *
   * The inverse of connecting: after a provider is stored + bound, disconnect()
   * must leave no trace — no State secret, no key entity, no `api_key` pointer,
   * and no role (or framework default) still resolving against it.
   */
  public function testDisconnectRemovesKeyAndUnbindsRoles(): void {
    // Connect anthropic and bind two roles to it (task drives the chat default).
    $this->store()->persist('anthropic', 'sk-ant-test-key');
    $resolver = $this->container->get('aincient_core.model_role_resolver');
    $resolver->bind('task', 'anthropic', 'claude-task');
    $resolver->bind('reasoning', 'anthropic', 'claude-reasoning');
    $resolver->project();

    $state = $this->container->get('state');
    $this->assertSame('sk-ant-test-key', $state->get('aincient.anthropic_api_key'));

    // Disconnect.
    $this->store()->disconnect('anthropic');

    // Both halves of the credential — the Key entity and the State value it
    // names — are gone.
    $this->assertNull($state->get('aincient.anthropic_api_key'));
    $this->assertNull(
      $this->container->get('entity_type.manager')->getStorage('key')->load('anthropic_default_key')
    );

    // Every role that pointed at anthropic is unbound.
    $roles = $this->container->get('config.factory')->get('aincient_core.model_roles')->get('roles');
    $this->assertSame('', (string) ($roles['task']['provider_id'] ?? ''));
    $this->assertSame('', (string) ($roles['reasoning']['provider_id'] ?? ''));

    // Nothing the site resolves through still names the removed provider. This
    // used to check the `ai.settings` chat default as well; with that write gone
    // the bindings ARE the record, so resolving the everyday role is the whole
    // question rather than one of two places it could have been stale.
    $this->assertSame(
      ['provider_id' => '', 'model_id' => ''],
      $this->container->get('aincient_core.model_role_resolver')->resolve(ModelRoles::TASK),
    );
  }

  /**
   * The wizard payload carries the decoupled catalogue + disconnect affordances.
   *
   * The three fields the redesigned steps consume: `disconnectUrl`, the
   * page-load `catalog` (chat/image maps — empty here, no live key), and
   * `modelLabels`. Their PRESENCE + shape is the contract; the catalogue's
   * contents need a live provider round-trip and are exercised in-browser.
   */
  public function testWizardPayloadCarriesCatalogAndDisconnect(): void {
    $forced = $this->alterConsoleSettings(Request::create('/atelier', 'GET', ['onboarding' => '1']));
    $onboarding = $forced['onboarding'];
    $this->assertStringContainsString('/atelier/onboarding/disconnect-provider', $onboarding['disconnectUrl']);
    $this->assertArrayHasKey('catalog', $onboarding);
    $this->assertArrayHasKey('chat', $onboarding['catalog']);
    $this->assertArrayHasKey('image', $onboarding['catalog']);
    $this->assertIsArray($onboarding['modelLabels']);
    // Provider rows carry our curated recommendation label alongside the row.
    // What's pinned is the PLUMBING — the label reaches the row, and it is one the
    // UI knows how to render. Not its value: this used to assert `anthropic` was
    // `recommended`, and the editorial call to endorse no provider at all
    // (DECISIONS 0317) broke a test that was never about that. Curation lives in
    // `model-recommendations.yml`; ModelRecommendationsTest is where it is asserted.
    $byId = array_column($onboarding['providers'], NULL, 'id');
    $this->assertArrayHasKey('recommendation', $byId['anthropic']);
    $this->assertContains(
      $byId['anthropic']['recommendation'],
      ['recommended', 'tested', 'not-recommended', ''],
    );
  }

  /**
   * The wizard payload carries the curated presets + their provenance.
   *
   * The simple mode's contract: the profiles to offer, which one to open on, a
   * resolved map per profile, where the suggestions came from, and the endpoint
   * behind "Check for updates". Presets resolve against connected providers, so
   * with no live key here the maps are legitimately empty — PRESENCE and shape
   * are what this pins; the resolution itself is unit-tested.
   */
  public function testWizardPayloadCarriesPresets(): void {
    $forced = $this->alterConsoleSettings(Request::create('/atelier', 'GET', ['onboarding' => '1']));
    $onboarding = $forced['onboarding'];

    $this->assertNotEmpty($onboarding['profiles']);
    $ids = array_column($onboarding['profiles'], 'id');
    $this->assertContains($onboarding['defaultProfile'], $ids);
    foreach ($onboarding['profiles'] as $profile) {
      $this->assertNotSame('', $profile['label']);
      $this->assertArrayHasKey($profile['id'], $onboarding['presets']);
    }

    // A fresh install has never fetched, so it runs on the bundled snapshot —
    // and says so, with the document's own date.
    $this->assertSame('bundled', $onboarding['recommendationsMeta']['source']);
    $this->assertNull($onboarding['recommendationsMeta']['fetchedAt']);
    $this->assertNotSame('', $onboarding['recommendationsMeta']['updated']);
    $this->assertStringContainsString(
      '/atelier/onboarding/refresh-recommendations',
      $onboarding['refreshRecommendationsUrl'],
    );
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
    $plain = $this->alterConsoleSettings(Request::create('/atelier'));
    $this->assertArrayHasKey('onboarding', $plain);
    $this->assertArrayNotHasKey('needed', $plain['onboarding']);
    $this->assertTrue($plain['onboarding']['canReenter']);

    // `?onboarding=1` as an admin: the wizard is forced back on, marked as a
    // re-run, and pre-filled with the existing bindings (so a no-op finish is safe).
    $forced = $this->alterConsoleSettings(Request::create('/atelier', 'GET', ['onboarding' => '1']));
    $this->assertTrue($forced['onboarding']['needed']);
    $this->assertTrue($forced['onboarding']['forced']);
    $this->assertSame('anthropic:claude-x', $forced['onboarding']['current']['task']);

    // The override is admin-only: a user without the permission can't force it,
    // and gets no re-entry pointer either.
    $this->setCurrentUser($this->createUser());
    $denied = $this->alterConsoleSettings(Request::create('/atelier', 'GET', ['onboarding' => '1']));
    $this->assertArrayNotHasKey('onboarding', $denied);
    $plainNonAdmin = $this->alterConsoleSettings(Request::create('/atelier'));
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

  /**
   * finalizeRoles() records WHICH tier produced the bindings.
   *
   * The intent, not just its result. Without it the site can only describe
   * itself as "Custom" — which is what the wizard used to show for every
   * configured site, no matter which tier set it up — and a recommendations
   * refresh has nothing to honour.
   */
  public function testFinalizeRolesStoresTheChosenProfile(): void {
    $result = $this->store()->finalizeRoles(
      ['task' => ['provider_id' => 'anthropic', 'model_id' => 'claude-task']],
      'balanced',
      '2026-07-25',
    );
    $this->assertTrue($result['ok']);

    $resolver = $this->container->get('aincient_core.model_role_resolver');
    $this->assertSame('balanced', $resolver->profile());
    $this->assertSame('2026-07-25', $resolver->profileUpdated());
  }

  /**
   * Finalising without a tier means Custom — an explicit "these are mine".
   *
   * Auto mode is all-or-nothing, so a hand-picked selection must not leave a
   * stale tier on record that a later refresh would act on.
   */
  public function testFinalizeRolesWithoutProfileIsCustom(): void {
    $resolver = $this->container->get('aincient_core.model_role_resolver');
    // A tier was in force from an earlier run…
    $resolver->setProfile('best_quality', '2026-07-01');

    // …and the operator now picks models by hand.
    $this->store()->finalizeRoles(
      ['task' => ['provider_id' => 'anthropic', 'model_id' => 'claude-hand-picked']],
    );

    $this->assertSame('', $resolver->profile(), 'A hand-picked selection drops the site to Custom.');
    $this->assertSame('', $resolver->profileUpdated());
  }

  /**
   * A Custom site is never re-resolved by a recommendations refresh.
   *
   * The other half of respecting the choice: those bindings are deliberate, so
   * a new document must not move them.
   */
  public function testRefreshLeavesACustomSiteAlone(): void {
    $resolver = $this->container->get('aincient_core.model_role_resolver');
    $resolver->bind('task', 'anthropic', 'claude-hand-picked');
    $resolver->clearProfile();

    $outcome = $this->container->get('aincient_onboarding.profile_applier')->reapplyStored();

    $this->assertFalse($outcome['applied']);
    $this->assertSame('', $outcome['profile']);
    $this->assertSame([], $outcome['changed']);
    $roles = $this->container->get('config.factory')->get('aincient_core.model_roles')->get('roles');
    $this->assertSame('claude-hand-picked', $roles['task']['model_id'], 'Deliberate bindings are untouched.');
  }

  /**
   * An unresolvable profile leaves the existing bindings standing.
   *
   * A profile that matches nothing connected must never be able to wipe a
   * working configuration — failing to update is recoverable, being unbound is
   * not.
   */
  public function testUnresolvableProfileDoesNotWipeBindings(): void {
    $resolver = $this->container->get('aincient_core.model_role_resolver');
    $resolver->bind('task', 'anthropic', 'claude-task');
    $resolver->setProfile('balanced', '2026-07-01');

    // Nothing is connected in this kernel site, so the profile resolves to
    // nothing.
    $outcome = $this->container->get('aincient_onboarding.profile_applier')->reapplyStored();

    $this->assertFalse($outcome['applied']);
    $roles = $this->container->get('config.factory')->get('aincient_core.model_roles')->get('roles');
    $this->assertSame('claude-task', $roles['task']['model_id']);
    $this->assertSame('balanced', $resolver->profile(), 'The tier survives a failed re-resolve.');
  }

}
