<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_core\Kernel;

use Drupal\aincient_core\Inference\ProviderAdapterInterface;
use Drupal\aincient_core\Inference\ProviderInventory;
use Drupal\aincient_inference_test\ScriptedAdapter;
use Drupal\KernelTests\KernelTestBase;

/**
 * Pins the promise the inventory makes to the console: the offer is honourable.
 *
 * This class is what the models form, the onboarding picker and the
 * `aincient:model-*` commands read to decide what to OFFER an operator. When it
 * over-reports, the product advertises capability it does not have: before it read
 * the adapter set, `chat` came back as `anthropic, mistral, ollama, openai,
 * openrouter, gemini` and four of those six could not serve a single turn — the
 * form let you bind `mistral` and the next request threw. So the tests here are
 * about the OFFER, not about the plumbing that produces it.
 *
 * Run against the real container (with a real registered adapter from
 * aincient_inference_test) rather than a doubled registry, because "what does this
 * site offer" is a question about the collected adapter set — which a mock cannot
 * answer wrongly, and therefore cannot answer usefully.
 *
 * @group aincient
 * @coversDefaultClass \Drupal\aincient_core\Inference\ProviderInventory
 */
final class ProviderInventoryTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'key',
    'aincient_core',
    'aincient_inference_test',
  ];

  /**
   * The inventory under test, from the real container.
   */
  private function inventory(): ProviderInventory {
    return $this->container->get('aincient_core.inference.provider_inventory');
  }

  /**
   * Everything offered can be served, and nothing servable is hidden.
   *
   * The one invariant the whole class exists for. Stated as a round trip through
   * {@see ProviderInventory::has()} so it holds whatever the adapter set grows
   * into, rather than freezing today's four ids into an expectation that a new
   * adapter would "break".
   */
  public function testEveryOfferedProviderCanBeServed(): void {
    $providers = $this->inventory()->providers();
    $this->assertNotSame([], $providers, 'A site with adapters must offer something.');

    foreach ($providers as $id => $row) {
      $this->assertTrue($this->inventory()->has($id));
      $this->assertSame($id, $row['id']);
      $this->assertNotSame('', $row['label'], sprintf('%s must be nameable in a picker.', $id));
      $this->assertNotSame('', $row['description'], sprintf('%s must be explainable in a picker.', $id));
      $this->assertNotSame('', $row['auth'], sprintf('%s must say what credential it needs.', $id));
    }
  }

  /**
   * The providers that never had an adapter are not offered. THE COVERAGE GAP.
   *
   * These ids were in the picker for as long as their `drupal/ai` modules were
   * installed, and binding one produced a ProviderConfigurationException on the
   * next turn. They are named explicitly because their absence is the point of the
   * change — a regression here would silently reopen the trap.
   *
   * `ollama`, `openai` and `mistral` USED TO BE ON THIS LIST and no longer are,
   * which is the rule working rather than an exception to it: the list is "no
   * adapter", not "not our kind of provider", so writing one takes an id off.
   * What must never happen is an id being offered WITHOUT an adapter — this
   * asserts that, and nothing more.
   */
  public function testUnservableProvidersAreNotOffered(): void {
    foreach (['openrouter', 'litellm'] as $id) {
      $this->assertFalse($this->inventory()->has($id));
      $this->assertArrayNotHasKey($id, $this->inventory()->providers());
      $this->assertArrayNotHasKey($id, $this->inventory()->providersWith(ProviderInventory::CHAT));
    }

    // The other direction, on the three ids that moved: each servable, offered
    // for chat, and asking for the credential shape its vendor actually uses.
    $chat = $this->inventory()->providersWith(ProviderInventory::CHAT);
    foreach ([
      'ollama' => ProviderAdapterInterface::AUTH_HOST,
      'openai' => ProviderAdapterInterface::AUTH_KEY,
      'mistral' => ProviderAdapterInterface::AUTH_KEY,
    ] as $id => $auth) {
      $this->assertTrue($this->inventory()->has($id));
      $this->assertArrayHasKey($id, $chat);
      $this->assertSame($auth, $this->inventory()->authShape($id));
    }
  }

  /**
   * A binding to a provider we cannot serve stays nameable, never fatal.
   *
   * The other half of the degrade-visibly path (the console's half is
   * ModelRolesFormTest): a stored binding can name anything, and the code that
   * has to warn about it must be able to compose a sentence rather than throw
   * while trying.
   */
  public function testAnUnservableProviderIsStillNameable(): void {
    // `openrouter` rather than `mistral`: mistral acquired an adapter, and the
    // case being pinned is what happens to a binding naming something we CANNOT
    // serve — so it has to name an id that is still unservable.
    $this->assertSame('openrouter', $this->inventory()->label('openrouter'));
    $this->assertSame('', $this->inventory()->authShape('openrouter'));
    $this->assertFalse($this->inventory()->isConnected('openrouter'));
    $this->assertSame([], $this->inventory()->models('openrouter', ProviderInventory::CHAT));
  }

  /**
   * Only a provider that can actually draw appears in the image pool.
   *
   * A capability CHECK, not a capability claim: the old backend asked for
   * `text_to_image` models and Anthropic answered with its eleven chat models, so
   * the console's image picker offered models that cannot make a picture.
   */
  public function testImagePoolHoldsOnlyProvidersThatCanDraw(): void {
    $imagePool = $this->inventory()->providersWith(ProviderInventory::IMAGE);

    $this->assertArrayHasKey('nanobanana', $imagePool);
    $this->assertArrayHasKey(ScriptedAdapter::PROVIDER_ID, $imagePool);
    $this->assertArrayNotHasKey('anthropic', $imagePool);
    $this->assertArrayNotHasKey('gemini', $imagePool);

    // And a text-only provider offers no image models even when it is connected
    // and happily lists chat ones.
    $this->assertArrayHasKey('anthropic', $this->inventory()->providersWith(ProviderInventory::CHAT));
    $this->assertSame([], $this->inventory()->models('anthropic', ProviderInventory::IMAGE));
  }

  /**
   * The same key behind two provider ids offers its chat models ONCE.
   *
   * `gemini` and `nanobanana` are one Google credential: the image id can answer a
   * chat question (it is the same platform) but must not be OFFERED for chat, or
   * every role select shows all of Gemini's models twice — measured at 71 chat
   * options for 41 distinct models — and an operator can bind the image id for text
   * work. The image id keeps its own pool, which is the whole reason it exists.
   */
  public function testTheImageIdIsNotOfferedForChat(): void {
    $this->assertArrayNotHasKey('nanobanana', $this->inventory()->providersWith(ProviderInventory::CHAT));
    $this->assertArrayHasKey('nanobanana', $this->inventory()->providersWith(ProviderInventory::IMAGE));
    $this->assertSame([], $this->inventory()->models('nanobanana', ProviderInventory::CHAT));
    // Nor by probing a candidate credential, which is the same picker by another
    // route (the onboarding wizard's).
    $this->assertSame(
      [],
      $this->inventory()->modelsForCredential('nanobanana', ProviderInventory::CHAT, 'sk-whatever'),
    );
  }

  /**
   * Connectedness tracks the stored credential, and gates the model list.
   *
   * The answer `drupal/ai`'s `isUsable()` got wrong in both directions on the
   * install this was written against — TRUE for three providers with no key, FALSE
   * for one that had one. A picker built on that either offers a provider that
   * 401s on first use or hides one the operator has already paid for.
   */
  public function testConnectedFollowsTheStoredCredential(): void {
    $id = ScriptedAdapter::PROVIDER_ID;
    $this->assertFalse($this->inventory()->isConnected($id));
    $this->assertSame([], $this->inventory()->models($id, ProviderInventory::CHAT));

    $this->container->get('state')->set(ScriptedAdapter::CREDENTIAL_KEY, 'sk-stored');

    $this->assertTrue($this->inventory()->isConnected($id));
    $this->assertSame(
      ['scripted-chat' => 'Scripted chat'],
      $this->inventory()->models($id, ProviderInventory::CHAT),
    );
    $this->assertSame(
      ['scripted-image' => 'Scripted image'],
      $this->inventory()->models($id, ProviderInventory::IMAGE),
    );
  }

  /**
   * A credential can be proven BEFORE it is stored. THE ESCAPE HATCH REPLACEMENT.
   *
   * This is what onboarding does when an operator pastes a key: validate it for
   * real, store nothing until it answers. It used to require a live provider
   * instance handed out through `ProviderInventory::instanceFor()` so the key could
   * be pushed in at runtime; the adapter contract takes the credential as an
   * argument, so the hatch is gone and this is an ordinary question.
   */
  public function testAnUnstoredCredentialCanBeValidated(): void {
    $id = ScriptedAdapter::PROVIDER_ID;
    $this->container->get('state')->set(ScriptedAdapter::VALID_CREDENTIAL_KEY, 'sk-real');

    // Nothing stored, so the provider is not connected.
    $this->assertFalse($this->inventory()->isConnected($id));
    // Yet the right credential still enumerates, which is what proves it works.
    $this->assertSame(
      ['scripted-chat' => 'Scripted chat'],
      $this->inventory()->modelsForCredential($id, ProviderInventory::CHAT, 'sk-real'),
    );
    // A wrong one comes back empty — onboarding's "this key does not work".
    $this->assertSame([], $this->inventory()->modelsForCredential($id, ProviderInventory::CHAT, 'sk-garbage'));
    // And the probe wrote nothing, either way.
    $this->assertFalse($this->inventory()->isConnected($id));
    $this->assertNull($this->container->get('state')->get(ScriptedAdapter::CREDENTIAL_KEY));
  }

  /**
   * Probing a provider we cannot serve answers empty rather than exploding.
   *
   * The wizard posts a provider id from the browser; an id with no adapter has to
   * be a validation failure, not a 500.
   */
  public function testProbingAnUnservableProviderIsAFailureNotAFatal(): void {
    $this->assertSame(
      [],
      $this->inventory()->modelsForCredential('openrouter', ProviderInventory::CHAT, 'sk-whatever'),
    );
  }

}
