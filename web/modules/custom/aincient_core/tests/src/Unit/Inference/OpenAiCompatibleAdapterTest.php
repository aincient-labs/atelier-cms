<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_core\Unit\Inference;

use Drupal\aincient_core\Inference\Adapter\OpenAiCompatibleAdapter;
use Drupal\aincient_core\Inference\Exception\ProviderConfigurationException;
use Drupal\aincient_core\Inference\ImageGenerationAdapterInterface;
use Drupal\aincient_core\Inference\ProviderAdapterInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * What may come out of an OpenAI-compatible endpoint's catalogue.
 *
 * This adapter's `/v1/models` is the only one in the set that is not a single
 * vendor's. A LiteLLM or OpenRouter deployment re-serves OpenAI, Gemini,
 * Anthropic and Mistral from one list, so it carries every vendor's non-chat
 * modality AND LiteLLM's own wildcard model GROUPS — records shaped exactly
 * like models that name a routing pattern rather than anything callable.
 *
 * The regression these tests hold down was live on the Forge demo: the filter
 * dropped ids containing `embed` and nothing else, so `openai/*` reached the
 * pool, and because an unresolved role ends at "the first model in the pool"
 * it won all four roles at once. Every turn would have failed on a model id
 * that cannot exist.
 */
#[CoversClass(OpenAiCompatibleAdapter::class)]
final class OpenAiCompatibleAdapterTest extends TestCase {

  /**
   * The URLs the last-built adapter asked for, in order.
   *
   * @var list<string>
   */
  private array $requested = [];

  /**
   * The adapter over a Guzzle client that always answers once (or throws).
   */
  private function adapter(Response|\Throwable|NULL $result): OpenAiCompatibleAdapter {
    $this->requested = [];
    $client = $this->createMock(ClientInterface::class);
    if ($result === NULL) {
      $client->expects($this->never())->method('request');
    }
    else {
      $client->method('request')->willReturnCallback(
        function (string $method, string $url) use ($result): Response {
          $this->requested[] = $url;
          if ($result instanceof \Throwable) {
            throw $result;
          }
          return $result;
        },
      );
    }
    return new OpenAiCompatibleAdapter(
      $this->createMock(HttpClientInterface::class),
      $client,
      $this->createMock(LoggerInterface::class),
    );
  }

  /**
   * Builds a `/v1/models` body from bare ids.
   *
   * @param list<string> $ids
   *   The model ids the endpoint claims to serve.
   */
  private function catalogue(array $ids): Response {
    return new Response(200, [], json_encode([
      'object' => 'list',
      'data' => array_map(
        static fn (string $id): array => ['id' => $id, 'object' => 'model'],
        $ids,
      ),
    ], JSON_THROW_ON_ERROR));
  }

  /**
   * It is the key+endpoint provider, and it does not draw.
   */
  public function testItIsAKeyAndEndpointProvider(): void {
    $adapter = $this->adapter(NULL);

    $this->assertSame('openai_compatible', $adapter->id());
    $this->assertSame(ProviderAdapterInterface::AUTH_KEY_ENDPOINT, $adapter->authShape());
    $this->assertTrue($adapter->servesChat());
    $this->assertNotInstanceOf(ImageGenerationAdapterInterface::class, $adapter);
  }

  /**
   * A base URL is not optional, and neither is a key.
   */
  public function testItRefusesToBuildAPlatformWithoutBoth(): void {
    $this->expectException(ProviderConfigurationException::class);
    $this->adapter(NULL)->createPlatform('sk-test', '');
  }

  /**
   * Half a configuration means no catalogue, and no wasted round-trip.
   */
  public function testHalfAConfigurationEnumeratesNothing(): void {
    $this->assertSame([], $this->adapter(NULL)->listChatModels('sk-test', ''));
    $this->assertSame([], $this->adapter(NULL)->listChatModels('  ', 'https://ai.example'));
  }

  /**
   * LiteLLM's wildcard GROUPS never reach the pool.
   *
   * This is the Forge-demo regression. `openai/*` is a routing pattern; it is
   * offered in the picker like a model, can be bound to a role, and fails every
   * turn. Worse, it is a candidate for "first model in the pool" — so it does
   * not merely sit there, it decides roles.
   */
  public function testWildcardModelGroupsAreNotModels(): void {
    $models = $this->adapter($this->catalogue([
      'openai/*',
      'anthropic/*',
      'gemini/*',
      'openai/gpt-5.4',
      'anthropic/claude-sonnet-5',
    ]))->listChatModels('sk-test', 'https://ai.example');

    $this->assertSame(
      ['anthropic/claude-sonnet-5', 'openai/gpt-5.4'],
      array_keys($models),
    );
  }

  /**
   * Every vendor's non-chat modality is filtered, not just embeddings.
   *
   * The ids here are all real entries from the proxy the Forge demo points at,
   * and all of them were being offered as chat models.
   */
  public function testNonChatModalitiesAreFiltered(): void {
    $models = $this->adapter($this->catalogue([
      'openai/gpt-5.4',
      'text-embedding-3-large',
      'gemini/gemini-2.5-flash-preview-tts',
      'gemini/gemini-2.5-flash-native-audio-latest',
      'gemini/gemini-3.1-flash-live-preview',
      'gemini/gemini-2.5-flash-image',
      'gemini/gemini-2.5-computer-use-preview-10-2025',
      'gemini/gemini-robotics-er-1.5-preview',
      'gemini/lyria-002',
      'whisper-1',
      'dall-e-3',
      'mistral-ocr-latest',
      'omni-moderation-latest',
      'gpt-3.5-turbo-instruct',
    ]))->listChatModels('sk-test', 'https://ai.example');

    $this->assertSame(['openai/gpt-5.4'], array_keys($models));
  }

  /**
   * A real chat catalogue survives intact, and comes back sorted.
   *
   * Sorted because an unresolved role ends at the pool's first entry, and the
   * endpoint's own response order would make that binding differ between two
   * installs pointed at the same proxy.
   */
  public function testChatModelsSurviveAndAreSorted(): void {
    $models = $this->adapter($this->catalogue([
      'openai/gpt-5.4',
      'deepseek-v4-pro',
      'anthropic/claude-opus-5',
      'gemini/gemini-3.5-flash',
    ]))->listChatModels('sk-test', 'https://ai.example');

    $this->assertSame(
      [
        'anthropic/claude-opus-5',
        'deepseek-v4-pro',
        'gemini/gemini-3.5-flash',
        'openai/gpt-5.4',
      ],
      array_keys($models),
    );
    $this->assertSame('https://ai.example/v1/models', $this->requested[0]);
  }

  /**
   * A base URL the operator typed with `/v1` already on it is not doubled.
   */
  public function testTheEndpointFootgunStaysDisarmed(): void {
    $this->adapter($this->catalogue(['deepseek-chat']))
      ->listChatModels('sk-test', 'https://api.deepseek.com/v1/');

    $this->assertSame('https://api.deepseek.com/v1/models', $this->requested[0]);
  }

  /**
   * A refused key yields no catalogue — which is how onboarding refuses it.
   */
  public function testRefusedKeyHasNoModels(): void {
    $unauthorized = new Response(401, [], '{"error":{"message":"Invalid API key"}}');

    $this->assertSame(
      [],
      $this->adapter($unauthorized)->listChatModels('sk-wrong', 'https://ai.example'),
    );
  }

  /**
   * An unreachable endpoint yields no catalogue either.
   */
  public function testAnUnreachableEndpointHasNoModels(): void {
    $down = new ConnectException('Connection timed out', new Request('GET', 'https://ai.example/v1/models'));

    $this->assertSame(
      [],
      $this->adapter($down)->listChatModels('sk-test', 'https://ai.example'),
    );
  }

  /**
   * Anything that is not a 200 carrying a `data` array is not a catalogue.
   */
  public function testMalformedAnswersAreNotCatalogues(): void {
    foreach (['not json', '{"data":"nope"}', '{}', '"a string"'] as $body) {
      $this->assertSame(
        [],
        $this->adapter(new Response(200, [], $body))->listChatModels('sk-test', 'https://ai.example'),
      );
    }
  }

}
