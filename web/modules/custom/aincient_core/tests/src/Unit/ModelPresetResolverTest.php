<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_core\Unit;

use Drupal\aincient_core\ModelPresetResolver;
use Drupal\aincient_core\ModelRoles;
use Drupal\aincient_core\RecommendationSource;
use Drupal\Component\Datetime\TimeInterface;
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
   */
  private function resolver(array $document): ModelPresetResolver {
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
    return new ModelPresetResolver($source);
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
    // tests/src/Unit -> aincient_core.
    $moduleRoot = dirname(__DIR__, 3);
    $moduleList = $this->createMock(ModuleExtensionList::class);
    $moduleList->method('getPath')->with('aincient_core')->willReturn($moduleRoot);
    $state = $this->createMock(StateInterface::class);
    $state->method('get')->willReturn(NULL);
    $resolver = new ModelPresetResolver(new RecommendationSource(
      $moduleList,
      $state,
      $this->createMock(ClientInterface::class),
      $this->createMock(LoggerChannelFactoryInterface::class),
      $this->createMock(TimeInterface::class),
    ));

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

}
