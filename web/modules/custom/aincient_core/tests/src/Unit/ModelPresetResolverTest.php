<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_core\Unit;

use Drupal\aincient_core\ModelPresetResolver;
use Drupal\aincient_core\ModelRoles;
use Drupal\aincient_core\RecommendationSource;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\ClientInterface;

/**
 * Unit-tests profile → per-role model resolution.
 *
 * Most cases run against a small SYNTHETIC document so the assertions stay
 * readable and don't churn every time we re-curate the real one; the last test
 * runs against the shipped snapshot, which is what catches a document that has
 * drifted out of a usable shape.
 *
 * @group aincient
 * @covers \Drupal\aincient_core\ModelPresetResolver
 */
final class ModelPresetResolverTest extends UnitTestCase {

  /**
   * A resolver over an in-memory document (State wins over the bundled file).
   *
   * @param array<string, mixed> $document
   *   The document the source should serve.
   * @param array<string, mixed> $preferences
   *   `aincient_core.model_preferences` values; empty means "site declares
   *   nothing", which must resolve exactly as it did before preferences existed.
   */
  private function resolver(array $document, array $preferences = []): ModelPresetResolver {
    $state = $this->createMock(StateInterface::class);
    $state->method('get')->willReturnCallback(
      static fn (string $key) => $key === 'aincient.model_recommendations' ? $document : NULL,
    );
    $source = new RecommendationSource(
      $this->createMock(ModuleExtensionList::class),
      $state,
      $this->createMock(ClientInterface::class),
      $this->createMock(LoggerChannelFactoryInterface::class),
      $this->createMock(TimeInterface::class),
    );
    return new ModelPresetResolver($source, $this->configFactory($preferences));
  }

  /**
   * A config factory serving one `aincient_core.model_preferences` payload.
   *
   * @param array<string, mixed> $preferences
   *   Keys of that config object ('avoid', 'prefer'); anything else reads NULL.
   */
  private function configFactory(array $preferences = []): ConfigFactoryInterface {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(
      static fn (string $key) => $preferences[$key] ?? NULL,
    );
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturn($config);
    return $factory;
  }

  /**
   * A resolver over the SHIPPED document snapshot (no State override).
   */
  private function shippedResolver(): ModelPresetResolver {
    // tests/src/Unit -> aincient_core.
    $moduleRoot = dirname(__DIR__, 3);
    $moduleList = $this->createMock(ModuleExtensionList::class);
    $moduleList->method('getPath')->with('aincient_core')->willReturn($moduleRoot);
    $state = $this->createMock(StateInterface::class);
    $state->method('get')->willReturn(NULL);
    return new ModelPresetResolver(
      new RecommendationSource(
        $moduleList,
        $state,
        $this->createMock(ClientInterface::class),
        $this->createMock(LoggerChannelFactoryInterface::class),
        $this->createMock(TimeInterface::class),
      ),
      $this->configFactory(),
    );
  }

  /**
   * A minimal but complete two-profile document.
   *
   * @return array<string, mixed>
   *   The parsed document, in the shape the website publishes.
   */
  private function document(): array {
    return [
      'schema' => 1,
      'updated' => '2026-07-25',
      'default_profile' => 'balanced',
      'profiles' => [
        'value' => [
          'label' => 'Best value',
          'description' => 'Cheap.',
          'roles' => [
            'reasoning' => ['openai:gpt-5.4-mini'],
            'task' => ['openai:gpt-5.4-mini'],
            'fast' => ['openai:gpt-5.4-mini'],
            'vision' => ['openai:gpt-5.4-mini'],
            'image' => ['nanobanana:gemini-2.5-flash-image'],
          ],
        ],
        'balanced' => [
          'label' => 'Balanced',
          'description' => 'Sensible.',
          'roles' => [
            'reasoning' => ['anthropic:claude-sonnet-5', 'openai:gpt-5.4'],
            'task' => ['anthropic:claude-haiku-4-5', 'openai:gpt-5.4-mini'],
            'fast' => ['anthropic:claude-haiku-4-5'],
            'vision' => ['anthropic:claude-sonnet-5'],
            'image' => ['nanobanana:gemini-2.5-flash-image'],
          ],
        ],
      ],
      'regions' => [
        'in' => [
          'label' => 'India',
          'roles' => ['task' => ['openai:gpt-5.4-mini']],
        ],
      ],
    ];
  }

  /**
   * Exact `provider:model` hits win, and every role is filled.
   */
  public function testExactMatch(): void {
    $chat = [
      'anthropic:claude-sonnet-5' => 'Claude Sonnet 5',
      'anthropic:claude-haiku-4-5' => 'Claude Haiku 4.5',
      'openai:gpt-5.4' => 'GPT-5.4',
    ];
    $image = ['nanobanana:gemini-2.5-flash-image' => 'Nano Banana'];

    $picked = $this->resolver($this->document())->apply('balanced', $chat, $image);

    $this->assertSame([
      ModelRoles::REASONING => 'anthropic:claude-sonnet-5',
      ModelRoles::TASK => 'anthropic:claude-haiku-4-5',
      ModelRoles::FAST => 'anthropic:claude-haiku-4-5',
      ModelRoles::VISION => 'anthropic:claude-sonnet-5',
      ModelRoles::IMAGE => 'nanobanana:gemini-2.5-flash-image',
    ], $picked);
  }

  /**
   * A dated version bump still resolves, via the substring fallback.
   *
   * This is the case that decides whether the published document needs an edit
   * every time a vendor appends a date to an id. It must not.
   */
  public function testSubstringFallbackSurvivesVersionBump(): void {
    $chat = ['anthropic:claude-sonnet-5-20260401' => 'Claude Sonnet 5'];
    $picked = $this->resolver($this->document())->apply('balanced', $chat, []);
    $this->assertSame('anthropic:claude-sonnet-5-20260401', $picked[ModelRoles::REASONING]);
  }

  /**
   * The substring fallback never crosses providers.
   *
   * A same-named model at a different vendor is NOT what the document meant, and
   * silently binding it would be a costly surprise.
   */
  public function testSubstringFallbackIsScopedToItsProvider(): void {
    // Only OpenRouter carries a "claude-sonnet-5"; the candidate names Anthropic.
    // So candidate #1 must NOT match, leaving candidate #2 (openai:gpt-5.4) to win.
    $chat = [
      'openrouter:anthropic/claude-sonnet-5' => 'Sonnet via OpenRouter',
      'openai:gpt-5.4' => 'GPT-5.4',
    ];
    $picked = $this->resolver($this->document())->apply('balanced', $chat, []);
    $this->assertSame('openai:gpt-5.4', $picked[ModelRoles::REASONING]);
  }

  /**
   * A candidate whose provider isn't connected is skipped for the next one.
   */
  public function testUnconnectedProviderFallsToTheNextCandidate(): void {
    $chat = ['openai:gpt-5.4' => 'GPT-5.4'];
    $picked = $this->resolver($this->document())->apply('balanced', $chat, []);
    // Anthropic (first candidate) isn't connected → OpenAI (second) wins.
    $this->assertSame('openai:gpt-5.4', $picked[ModelRoles::REASONING]);
  }

  /**
   * When the document names nothing available, the tier hints take over.
   *
   * This is the pre-profiles behaviour, and it must survive: a provider the
   * document is silent about is no worse off than it was before profiles existed.
   */
  public function testFallsThroughToTierHints(): void {
    // Ollama appears in no profile and no tier hint...
    $chat = [
      'ollama:llama3.3' => 'Llama 3.3',
      'ollama:qwen2.5-coder' => 'Qwen Coder',
    ];
    $picked = $this->resolver($this->document())->apply('balanced', $chat, []);
    // ...so every role gets the pool's first entry rather than nothing.
    $this->assertSame('ollama:llama3.3', $picked[ModelRoles::REASONING]);

    // OpenAI DOES have tier hints; with no profile candidate available they
    // decide, and `fast` must land on the mini model, not merely the first one.
    $openai = [
      'openai:gpt-4o' => 'GPT-4o',
      'openai:gpt-4o-mini' => 'GPT-4o mini',
    ];
    $picked = $this->resolver($this->document())->apply('nonexistent-profile', $openai, []);
    $this->assertSame('openai:gpt-4o-mini', $picked[ModelRoles::FAST]);
    $this->assertSame('openai:gpt-4o', $picked[ModelRoles::TASK]);
  }

  /**
   * A proxy's catalogue resolves the document's candidates for other vendors.
   *
   * A LiteLLM/OpenRouter key serves other people's models under a `vendor/model`
   * id, so the curated candidates describe them — they just don't carry the
   * proxy's provider id. Without this the profiles collapse to "the pool's first
   * model" for anyone whose only provider is a proxy (the hosted demo), and the
   * one question we ask a beginner has three identical answers.
   */
  public function testProfileCandidatesResolveThroughProxyProviders(): void {
    $chat = [
      'litellm:openai/gpt-5.4-mini' => 'GPT-5.4 mini',
      'litellm:anthropic/claude-sonnet-5' => 'Claude Sonnet 5',
      'litellm:anthropic/claude-haiku-4-5' => 'Claude Haiku 4.5',
    ];
    $picked = $this->resolver($this->document())->apply('balanced', $chat, []);
    // `anthropic:claude-sonnet-5` — Anthropic isn't connected, the proxy serves it.
    $this->assertSame('litellm:anthropic/claude-sonnet-5', $picked[ModelRoles::REASONING]);
    $this->assertSame('litellm:anthropic/claude-haiku-4-5', $picked[ModelRoles::TASK]);
  }

  /**
   * A direct key BEATS the same model reached through a proxy.
   *
   * The proxy pass is a second sweep over the candidates, not an inner loop, so a
   * lower-ranked candidate on a provider the operator actually connected wins over
   * a higher-ranked one that would have to go through a proxy — an extra hop, an
   * extra bill, and a credential they didn't choose for this.
   */
  public function testDirectProviderWinsOverProxy(): void {
    $chat = [
      // The profile's FIRST candidate for `reasoning`, via the proxy...
      'litellm:anthropic/claude-sonnet-5' => 'Sonnet via LiteLLM',
      // ...and its second candidate on a direct key.
      'openai:gpt-5.4' => 'GPT-5.4',
    ];
    $picked = $this->resolver($this->document())->apply('balanced', $chat, []);
    $this->assertSame('openai:gpt-5.4', $picked[ModelRoles::REASONING]);
  }

  /**
   * A dated candidate resolves against a catalogue serving the family id.
   *
   * The mirror image of testSubstringFallbackSurvivesVersionBump: there the POOL
   * was more specific than the document, here the document is more specific than
   * the pool — which is what proxies do (`anthropic/claude-haiku-4-5` for what
   * Anthropic itself lists as `claude-haiku-4-5-20251001`).
   */
  public function testDatedCandidateResolvesAgainstFamilyId(): void {
    $document = $this->document();
    $document['profiles']['balanced']['roles']['task'] = ['anthropic:claude-haiku-4-5-20251001'];
    $chat = ['litellm:anthropic/claude-haiku-4-5' => 'Claude Haiku 4.5'];
    $picked = $this->resolver($document)->apply('balanced', $chat, []);
    $this->assertSame('litellm:anthropic/claude-haiku-4-5', $picked[ModelRoles::TASK]);
  }

  /**
   * A short, generic pool id must NOT capture a longer candidate.
   *
   * The guard on the reverse match ({@see ModelPresetResolver}'s FAMILY_MIN):
   * `gpt-5` shares a prefix with `gpt-5.6-sol` but is a different model at a
   * different price, so binding it would be a silent downgrade — and the whole
   * point of a profile is that its picks are the ones we curated.
   */
  public function testShortGenericIdDoesNotCaptureLongerCandidate(): void {
    $document = $this->document();
    $document['profiles']['balanced']['roles']['reasoning'] = ['openai:gpt-5.6-sol'];
    $chat = [
      'litellm:openai/gpt-5' => 'GPT-5',
      'litellm:anthropic/claude-sonnet-5' => 'Claude Sonnet 5',
    ];
    $picked = $this->resolver($document)->apply('balanced', $chat, []);
    // Not `litellm:openai/gpt-5` — nothing the document named is available, so the
    // LiteLLM tier hints decide, and `sonnet` is a reasoning hint ahead of `gpt-5`.
    $this->assertSame('litellm:anthropic/claude-sonnet-5', $picked[ModelRoles::REASONING]);
  }

  /**
   * With no image provider connected, `image` stays UNBOUND.
   *
   * It is a product gate ({@see \Drupal\aincient_core\ModelRoleResolver::imageBinding()}):
   * a guessed binding would falsely light up the Media studio's AI rail.
   */
  public function testImageStaysUnboundWithoutAnImagePool(): void {
    $picked = $this->resolver($this->document())->apply('balanced', ['openai:gpt-5.4' => 'GPT-5.4'], []);
    $this->assertArrayNotHasKey(ModelRoles::IMAGE, $picked);
  }

  /**
   * A region's candidates are tried before the profile's, for its roles only.
   */
  public function testRegionOverridesOnlyTheRolesItNames(): void {
    $chat = [
      'anthropic:claude-sonnet-5' => 'Claude Sonnet 5',
      'anthropic:claude-haiku-4-5' => 'Claude Haiku 4.5',
      'openai:gpt-5.4-mini' => 'GPT-5.4 mini',
    ];
    $picked = $this->resolver($this->document())->apply('balanced', $chat, [], 'in');
    // `task` is overridden by the region...
    $this->assertSame('openai:gpt-5.4-mini', $picked[ModelRoles::TASK]);
    // ...everything else still comes from the profile.
    $this->assertSame('anthropic:claude-sonnet-5', $picked[ModelRoles::REASONING]);
  }

  /**
   * SECURITY: every value returned is a key from the LOCAL pool.
   *
   * This is the invariant that makes a remotely-fetched document safe to act on.
   * The document is authored by us and fetched over pinned HTTPS, but it is still
   * the only untrusted input that reaches the appliance — so the resolver must
   * never be able to emit a provider or model id of the *document's* choosing,
   * only select among models the installed provider plugins already offered.
   *
   * Break this and a compromised document could write arbitrary strings into
   * `aincient_core.model_roles` and, via projection, into `ai.settings`.
   */
  public function testNeverReturnsAnythingOutsideThePool(): void {
    $hostile = [
      'schema' => 1,
      'profiles' => [
        'evil' => [
          'label' => 'Evil',
          'roles' => [
            'reasoning' => [
              'attacker:../../etc/passwd',
              'anthropic:$(id)',
              '',
              ':',
              'anthropic:',
              ':model-only',
              'anthropic:claude-sonnet-5:extra',
            ],
            'task' => ['totally-unknown-provider:whatever'],
            'fast' => ['anthropic:<script>alert(1)</script>'],
            'vision' => ['anthropic:%00'],
            'image' => ['nanobanana:../../../'],
          ],
        ],
      ],
    ];
    $chat = [
      'anthropic:claude-sonnet-5' => 'Claude Sonnet 5',
      'anthropic:claude-haiku-4-5' => 'Claude Haiku 4.5',
    ];
    $image = ['nanobanana:gemini-2.5-flash-image' => 'Nano Banana'];

    $picked = $this->resolver($hostile)->apply('evil', $chat, $image);

    $this->assertNotEmpty($picked, 'the resolver should still fall back to something usable');
    foreach ($picked as $role => $value) {
      $pool = $role === ModelRoles::IMAGE ? $image : $chat;
      $this->assertArrayHasKey($value, $pool, "role {$role} escaped the local pool with \"{$value}\"");
    }
  }

  /**
   * A hostile profile LABEL is returned verbatim, for the renderers to escape.
   *
   * Documented deliberately: sanitising here would be the wrong layer and would
   * double-escape. The console renders these through React and the settings form
   * through Drupal's form API, both of which escape by default — this test exists
   * so that anyone who changes those renderers knows the input is untrusted.
   */
  public function testProfileLabelsAreNotSanitisedHere(): void {
    $document = $this->document();
    $document['profiles']['value']['label'] = '<script>alert(1)</script>';
    $this->assertSame('<script>alert(1)</script>', $this->resolver($document)->profiles()[0]['label']);
  }

  /**
   * Profile metadata: order preserved, default honoured.
   */
  public function testProfilesAndDefault(): void {
    $resolver = $this->resolver($this->document());
    $this->assertSame(['value', 'balanced'], array_column($resolver->profiles(), 'id'));
    $this->assertSame('Best value', $resolver->profiles()[0]['label']);
    $this->assertSame('balanced', $resolver->defaultProfile());
    $this->assertTrue($resolver->hasProfile('value'));
    $this->assertFalse($resolver->hasProfile('nope'));
    $this->assertSame([['id' => 'in', 'label' => 'India']], $resolver->regions());
  }

  /**
   * A document with no `default_profile` still names one.
   */
  public function testDefaultProfileFallsBackToTheFirstDefined(): void {
    $document = $this->document();
    unset($document['default_profile']);
    $this->assertSame('value', $this->resolver($document)->defaultProfile());
  }

  /**
   * The SHIPPED snapshot resolves every role for a realistic provider mix.
   *
   * Guards the real document, not the algorithm: a curation pass that drops a
   * profile, mistypes a role key, or leaves a role with no reachable candidate
   * fails here rather than in someone's onboarding.
   */
  public function testShippedDocumentResolves(): void {
    $resolver = $this->shippedResolver();

    $this->assertNotEmpty($resolver->profiles());
    $this->assertTrue($resolver->hasProfile($resolver->defaultProfile()));

    // The REAL catalogues, copied from what the providers returned for a live
    // Anthropic + Google key on 2026-07-25. Using the true ids (dated Haiku,
    // undated Claude 5, the contrib module's `-preview` image id) is the point:
    // a synthetic pool would let the profiles resolve through the tier-hint
    // fallback and quietly hide a mistyped or retired id in the document.
    $chat = [
      'anthropic:claude-opus-5' => 'Claude Opus 5',
      'anthropic:claude-sonnet-5' => 'Claude Sonnet 5',
      'anthropic:claude-fable-5' => 'Claude Fable 5',
      'anthropic:claude-opus-4-8' => 'Claude Opus 4.8',
      'anthropic:claude-sonnet-4-6' => 'Claude Sonnet 4.6',
      'anthropic:claude-haiku-4-5-20251001' => 'Claude Haiku 4.5',
      'anthropic:claude-sonnet-4-5-20250929' => 'Claude Sonnet 4.5',
    ];
    $image = [
      'nanobanana:gemini-2.5-flash-image' => 'Nano Banana',
      'nanobanana:gemini-3-pro-image-preview' => 'Nano Banana Pro',
    ];

    foreach ($resolver->profiles() as $profile) {
      $picked = $resolver->apply($profile['id'], $chat, $image);
      foreach (array_keys(ModelPresetResolver::rolePools()) as $role) {
        $this->assertArrayHasKey($role, $picked, "profile {$profile['id']} left role {$role} unresolved");
        $pool = $role === ModelRoles::IMAGE ? $image : $chat;
        $this->assertArrayHasKey($picked[$role], $pool);
      }
    }

    // The exact bindings an Anthropic-only site gets. Spelled out so that a
    // curation pass which accidentally demotes a role — "high thinking" landing
    // on Haiku, or "best quality" silently costing Opus money on trivial calls —
    // fails here instead of shipping.
    $balanced = $resolver->apply('balanced', $chat, $image);
    $this->assertSame('anthropic:claude-sonnet-5', $balanced[ModelRoles::REASONING]);
    $this->assertSame('anthropic:claude-haiku-4-5-20251001', $balanced[ModelRoles::TASK]);
    $this->assertSame('anthropic:claude-haiku-4-5-20251001', $balanced[ModelRoles::FAST]);
    $this->assertSame('anthropic:claude-sonnet-5', $balanced[ModelRoles::VISION]);
    $this->assertSame('nanobanana:gemini-2.5-flash-image', $balanced[ModelRoles::IMAGE]);

    $quality = $resolver->apply('quality', $chat, $image);
    $this->assertSame('anthropic:claude-opus-5', $quality[ModelRoles::REASONING]);
    // `fast` must stay cheap even here — choosing "best quality" should not
    // multiply the cost of every trivial classify/extract call.
    $this->assertSame('anthropic:claude-haiku-4-5-20251001', $quality[ModelRoles::FAST]);
    // Vision is Sonnet, never Opus: alt text is not a reasoning task.
    $this->assertSame('anthropic:claude-sonnet-5', $quality[ModelRoles::VISION]);
    // `gemini-3-pro-image` resolves to the contrib module's `-preview` id via the
    // substring match, and will keep working when the module ships the stable id.
    $this->assertSame('nanobanana:gemini-3-pro-image-preview', $quality[ModelRoles::IMAGE]);

    // Best value must never pick a frontier model, whatever the fallbacks do.
    $value = $resolver->apply('value', $chat, $image);
    foreach ([ModelRoles::REASONING, ModelRoles::TASK, ModelRoles::FAST] as $role) {
      $this->assertSame('anthropic:claude-haiku-4-5-20251001', $value[$role], "value profile put {$role} on an expensive model");
    }
  }

  /**
   * The SHIPPED document gives three DIFFERENT answers on a proxy-only site.
   *
   * This is the hosted-demo case: the only connected provider is a LiteLLM proxy
   * with a handful of models, and the visitor is asked the one question the wizard
   * leads with. If the three profiles resolved to the same model, that question
   * would be theatre — so the guard is that the tiers stay ORDERED (value ≤
   * balanced ≤ quality on capability) and that `reasoning` genuinely differs.
   *
   * The pool is the catalogue Drupal Forge's proxy is believed to serve. It is a
   * belief, not a reading — `/model/info` needs the injected key, so it cannot be
   * checked from here (see apps/forge-demo/.devpanel/wire-ai.sh, which logs the
   * real list from inside the container).
   */
  public function testShippedDocumentResolvesOnProxyOnlyPool(): void {
    $resolver = $this->shippedResolver();
    $chat = [
      'litellm:openai/gpt-5.4' => 'GPT-5.4',
      'litellm:openai/gpt-5.4-mini' => 'GPT-5.4 mini',
      'litellm:gemini/gemini-3.5-flash' => 'Gemini 3.5 Flash',
      'litellm:anthropic/claude-haiku-4-5' => 'Claude Haiku 4.5',
    ];

    foreach ($resolver->profiles() as $profile) {
      $picked = $resolver->apply($profile['id'], $chat, []);
      // Every role but `image` (no image model on a chat-only proxy) resolves,
      // and only ever to something in the pool.
      foreach ([ModelRoles::REASONING, ModelRoles::TASK, ModelRoles::FAST, ModelRoles::VISION] as $role) {
        $this->assertArrayHasKey($role, $picked, "profile {$profile['id']} left role {$role} unresolved");
        $this->assertArrayHasKey($picked[$role], $chat);
      }
      $this->assertArrayNotHasKey(ModelRoles::IMAGE, $picked);
    }

    // `reasoning` is the role the money goes on, and the three profiles must
    // actually differ there: the cheapest model, the mid one, the strongest.
    $this->assertSame('litellm:anthropic/claude-haiku-4-5', $resolver->apply('value', $chat, [])[ModelRoles::REASONING]);
    $this->assertSame('litellm:gemini/gemini-3.5-flash', $resolver->apply('balanced', $chat, [])[ModelRoles::REASONING]);
    $this->assertSame('litellm:openai/gpt-5.4', $resolver->apply('quality', $chat, [])[ModelRoles::REASONING]);

    // And `fast` stays cheap in every profile — including "best quality".
    foreach (['value', 'balanced', 'quality'] as $id) {
      $this->assertSame(
        'litellm:anthropic/claude-haiku-4-5',
        $resolver->apply($id, $chat, [])[ModelRoles::FAST],
        "profile {$id} put the fast tier on an expensive model",
      );
    }
  }

  /**
   * An install that declares nothing resolves exactly as it always did.
   *
   * The guard on the whole feature: preferences are opt-in, and their machinery
   * must be invisible until someone writes something into the config object.
   */
  public function testEmptyPreferencesChangeNothing(): void {
    $chat = [
      'anthropic:claude-sonnet-5' => 'Claude Sonnet 5',
      'anthropic:claude-haiku-4-5' => 'Claude Haiku 4.5',
    ];
    $bare = $this->resolver($this->document())->apply('balanced', $chat, []);
    $declared = $this->resolver($this->document(), ['avoid' => [], 'prefer' => []])
      ->apply('balanced', $chat, []);
    $this->assertSame($bare, $declared);
    $this->assertSame('anthropic:claude-sonnet-5', $bare[ModelRoles::REASONING]);
  }

  /**
   * An avoided vendor is never bound, and the next candidate takes the role.
   */
  public function testAvoidSkipsToTheNextCandidate(): void {
    $chat = [
      'anthropic:claude-sonnet-5' => 'Claude Sonnet 5',
      'openai:gpt-5.4' => 'GPT-5.4',
    ];
    $picked = $this->resolver($this->document(), ['avoid' => ['anthropic:*']])
      ->apply('balanced', $chat, []);
    $this->assertSame('openai:gpt-5.4', $picked[ModelRoles::REASONING]);
  }

  /**
   * `avoid` reaches a vendor's models served through a proxy.
   *
   * The case this feature exists for: excluding `anthropic:*` on a site whose only
   * provider is a LiteLLM proxy has to exclude `litellm:anthropic/...` too, or the
   * declaration means nothing where it is needed most.
   */
  public function testAvoidReachesThroughAProxy(): void {
    $chat = [
      'litellm:anthropic/claude-sonnet-5' => 'Sonnet via LiteLLM',
      'litellm:openai/gpt-5.4' => 'GPT-5.4 via LiteLLM',
    ];
    $picked = $this->resolver($this->document(), ['avoid' => ['anthropic:*']])
      ->apply('balanced', $chat, []);
    $this->assertSame('litellm:openai/gpt-5.4', $picked[ModelRoles::REASONING]);
  }

  /**
   * A single model can be excluded without excluding its vendor.
   */
  public function testAvoidCanNameOneModel(): void {
    $chat = [
      'anthropic:claude-sonnet-5' => 'Claude Sonnet 5',
      'anthropic:claude-haiku-4-5' => 'Claude Haiku 4.5',
    ];
    $picked = $this->resolver($this->document(), ['avoid' => ['anthropic:claude-sonnet-5']])
      ->apply('balanced', $chat, []);
    // Reasoning wanted Sonnet and cannot have it; Haiku is still fair game, and
    // the roles that always wanted Haiku are untouched.
    $this->assertNotSame('anthropic:claude-sonnet-5', $picked[ModelRoles::REASONING] ?? '');
    $this->assertSame('anthropic:claude-haiku-4-5', $picked[ModelRoles::TASK]);
  }

  /**
   * Avoiding everything leaves the role UNBOUND rather than substituting.
   *
   * The important half of "hard": the tier hints and the first-in-pool fallback
   * both read the pool, so filtering the pool is what stops them quietly handing
   * back the very model the site excluded.
   */
  public function testAvoidingTheWholePoolLeavesRolesUnbound(): void {
    $chat = [
      'anthropic:claude-sonnet-5' => 'Claude Sonnet 5',
      'anthropic:claude-haiku-4-5' => 'Claude Haiku 4.5',
    ];
    $picked = $this->resolver($this->document(), ['avoid' => ['anthropic:*']])
      ->apply('balanced', $chat, []);
    $this->assertSame([], $picked);
  }

  /**
   * `prefer` outranks the curated pick for that role, and only that role.
   */
  public function testPreferOverridesTheCuratedPick(): void {
    $chat = [
      'anthropic:claude-sonnet-5' => 'Claude Sonnet 5',
      'anthropic:claude-haiku-4-5' => 'Claude Haiku 4.5',
      'openai:gpt-5.4' => 'GPT-5.4',
    ];
    $picked = $this->resolver($this->document(), [
      'prefer' => [ModelRoles::REASONING => ['openai:gpt-5.4']],
    ])->apply('balanced', $chat, []);

    $this->assertSame('openai:gpt-5.4', $picked[ModelRoles::REASONING]);
    // Untouched roles still follow the document.
    $this->assertSame('anthropic:claude-haiku-4-5', $picked[ModelRoles::TASK]);
  }

  /**
   * A preference wins even when the curated pick sits on a DIRECT provider.
   *
   * Deliberately inverts the document's own "a direct key beats the same model
   * through a proxy" rule. That rule breaks ties between candidates we chose; an
   * operator naming a model their proxy serves has made the choice themselves.
   */
  public function testPreferBeatsADirectlyConnectedCuratedPick(): void {
    $chat = [
      'anthropic:claude-sonnet-5' => 'Claude Sonnet 5',
      'litellm:openai/gpt-5.4' => 'GPT-5.4 via LiteLLM',
    ];
    $picked = $this->resolver($this->document(), [
      'prefer' => [ModelRoles::REASONING => ['openai:gpt-5.4']],
    ])->apply('balanced', $chat, []);
    $this->assertSame('litellm:openai/gpt-5.4', $picked[ModelRoles::REASONING]);
  }

  /**
   * An unresolvable preference falls through to the curated list.
   *
   * The soft half: a house preference for a provider that is briefly unreachable
   * must degrade to our guidance, not strand the role.
   */
  public function testPreferFallsThroughWhenUnavailable(): void {
    $chat = ['anthropic:claude-sonnet-5' => 'Claude Sonnet 5'];
    $picked = $this->resolver($this->document(), [
      'prefer' => [ModelRoles::REASONING => ['mistral:mistral-large-2512']],
    ])->apply('balanced', $chat, []);
    $this->assertSame('anthropic:claude-sonnet-5', $picked[ModelRoles::REASONING]);
  }

  /**
   * `avoid` wins over `prefer` — the hard list is hard.
   *
   * A contradictory pair is a mistake, and the safe reading of a mistake is the
   * restrictive one: never bind what the site said never to bind.
   */
  public function testAvoidOverridesPrefer(): void {
    $chat = [
      'anthropic:claude-sonnet-5' => 'Claude Sonnet 5',
      'openai:gpt-5.4' => 'GPT-5.4',
    ];
    $picked = $this->resolver($this->document(), [
      'avoid' => ['anthropic:*'],
      'prefer' => [ModelRoles::REASONING => ['anthropic:claude-sonnet-5']],
    ])->apply('balanced', $chat, []);
    $this->assertSame('openai:gpt-5.4', $picked[ModelRoles::REASONING]);
  }

}
