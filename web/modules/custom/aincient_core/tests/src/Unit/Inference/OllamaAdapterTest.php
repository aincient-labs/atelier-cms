<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_core\Unit\Inference;

use Drupal\aincient_core\Inference\Adapter\OllamaAdapter;
use Drupal\aincient_core\Inference\Exception\ProviderConfigurationException;
use Drupal\aincient_core\Inference\ImageGenerationAdapterInterface;
use Drupal\aincient_core\Inference\ProviderAdapterInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Conformance tests for the one adapter that authenticates with no secret.
 *
 * Ollama is the AUTH_HOST case: its whole credential is where the server is, so
 * the questions that matter here are different from the key providers'. What is
 * pinned is (a) that a URL is never mistaken for a key, (b) that the catalogue
 * tells the truth about what a pulled model can do — Ollama's `/api/tags` cannot,
 * on its own — and (c) that a server which is not there produces an empty
 * catalogue rather than a plausible one.
 */
#[CoversClass(OllamaAdapter::class)]
final class OllamaAdapterTest extends TestCase {

  /**
   * The URLs the last-built adapter's Guzzle client was asked for, in order.
   *
   * @var list<string>
   */
  private array $requested = [];

  /**
   * An adapter whose HTTP client answers from a URL => response map.
   *
   * Enumeration is deliberately N+1 (a `/api/show` per tag), so a single stubbed
   * response cannot express it — the map is what lets a test say "tags answered,
   * and THIS model reported these capabilities".
   *
   * @param array<string, \GuzzleHttp\Psr7\Response|\Throwable|callable> $routes
   *   Substring of the requested URL => what to answer with. The first match
   *   wins; an unmatched URL is a 404, which is itself an assertion (an adapter
   *   asking for something unexpected gets nothing back). A callable receives the
   *   request options, so a `/api/show` route can answer per model.
   */
  private function adapter(array $routes): OllamaAdapter {
    $this->requested = [];
    $client = $this->createMock(ClientInterface::class);
    $client->method('request')->willReturnCallback(
      function (string $method, string $url, array $options) use ($routes): Response {
        $this->requested[] = $url;
        foreach ($routes as $needle => $result) {
          if (str_contains($url, (string) $needle)) {
            if ($result instanceof \Throwable) {
              throw $result;
            }
            return is_callable($result) ? $result($options) : $result;
          }
        }
        return new Response(404, [], '{"error":"not found"}');
      },
    );

    return new OllamaAdapter(
      $this->createMock(HttpClientInterface::class),
      $client,
      $this->createMock(LoggerInterface::class),
    );
  }

  /**
   * An adapter that must make no HTTP call at all.
   */
  private function silentAdapter(): OllamaAdapter {
    $client = $this->createMock(ClientInterface::class);
    $client->expects($this->never())->method('request');

    return new OllamaAdapter(
      $this->createMock(HttpClientInterface::class),
      $client,
      $this->createMock(LoggerInterface::class),
    );
  }

  /**
   * A `/api/tags` body in the shape Ollama really returns.
   *
   * @param list<string> $names
   *   Model tags.
   */
  private function tags(array $names): Response {
    return new Response(200, [], json_encode([
      'models' => array_map(
        static fn (string $name): array => ['name' => $name, 'model' => $name, 'size' => 1],
        $names,
      ),
    ], JSON_THROW_ON_ERROR));
  }

  /**
   * An `/api/show` body carrying a capability list.
   *
   * @param list<string> $capabilities
   *   Ollama's own capability strings.
   */
  private function show(array $capabilities): Response {
    return new Response(200, [], json_encode([
      'details' => ['family' => 'llama'],
      'capabilities' => $capabilities,
    ], JSON_THROW_ON_ERROR));
  }

  /**
   * The credential shape is a host, and nothing about it is a secret.
   */
  public function testTheCredentialShapeIsHost(): void {
    $adapter = $this->silentAdapter();

    $this->assertSame('ollama', $adapter->id());
    $this->assertSame(ProviderAdapterInterface::AUTH_HOST, $adapter->authShape());
    $this->assertTrue($adapter->servesChat());
    $this->assertFalse($adapter->isProxy());
    // Local models draw nothing here: image work is a typed capability and this
    // adapter does not claim it.
    $this->assertNotInstanceOf(ImageGenerationAdapterInterface::class, $adapter);
  }

  /**
   * A platform builds with NO credential — the thing no other adapter allows.
   *
   * Every key provider throws on an empty credential. If this one ever did, the
   * ordinary Ollama install (a server on your laptop, no auth) would be
   * unreachable by construction.
   */
  public function testPlatformBuildsWithoutSecret(): void {
    $this->assertInstanceOf(
      PlatformInterface::class,
      $this->silentAdapter()->createPlatform('', 'http://host.docker.internal:11434'),
    );
  }

  /**
   * No URL, no platform — and the message says what to type.
   */
  public function testBuildingWithoutUrlIsRefused(): void {
    $this->expectException(ProviderConfigurationException::class);
    $this->expectExceptionMessageMatches('#11434#');

    $this->silentAdapter()->createPlatform('', '');
  }

  /**
   * No URL means no catalogue, and no wasted round-trip.
   */
  public function testNoUrlEnumeratesNothing(): void {
    $this->assertSame([], $this->silentAdapter()->listChatModels(''));
  }

  /**
   * Capabilities are read per model, so an embedding model cannot pass as chat.
   *
   * `/api/tags` reports a name and a size — an embedding model and a chat model
   * are indistinguishable in it. Listing the tag list raw is how `nomic-embed-text`
   * ends up bound to the task role and every turn fails.
   */
  public function testItKeepsChatModelsAndDropsEmbeddingModels(): void {
    $adapter = $this->adapter([
      '/api/tags' => $this->tags(['qwen3:8b', 'nomic-embed-text:latest']),
      // Answers as the server does: per model, from the body of the show request.
      '/api/show' => fn (array $options): Response => $this->show(
        str_contains((string) ($options['json']['model'] ?? ''), 'embed')
          ? ['embedding']
          : ['completion', 'tools'],
      ),
    ]);

    $this->assertSame(
      ['qwen3:8b' => 'qwen3:8b'],
      $adapter->listChatModels('', 'http://ollama.test:11434'),
    );
  }

  /**
   * A model that cannot call tools is listed, and says so in its label.
   *
   * Filtering it out would be the opposite lie: it is a fine choice for a vision
   * or plain-chat role, and only Atelier's agent loop needs tools. The constraint
   * belongs in front of the operator, not in a hidden exclusion.
   */
  public function testToollessModelIsLabelledRatherThanHidden(): void {
    $adapter = $this->adapter([
      '/api/tags' => $this->tags(['gemma3:4b']),
      '/api/show' => $this->show(['completion', 'vision']),
    ]);

    $this->assertSame(
      ['gemma3:4b' => 'gemma3:4b — no tool calling'],
      $adapter->listChatModels('', 'http://ollama.test:11434'),
    );
  }

  /**
   * A server too old to report capabilities keeps its models, conservatively.
   *
   * Capabilities arrived in Ollama 0.6. Treating their absence as "not a chat
   * model" would empty the picker on an older server that works fine.
   */
  public function testAnOldServerWithNoCapabilityListStillOffersItsModels(): void {
    $adapter = $this->adapter([
      '/api/tags' => $this->tags(['llama3.1:8b']),
      '/api/show' => new Response(200, [], '{"details":{"family":"llama"}}'),
    ]);

    $this->assertSame(
      ['llama3.1:8b' => 'llama3.1:8b — no tool calling'],
      $adapter->listChatModels('', 'http://ollama.test:11434'),
    );
  }

  /**
   * An unreachable server yields an empty catalogue, never a plausible one.
   */
  public function testAnUnreachableServerHasNoModels(): void {
    $adapter = $this->adapter([
      '/api/tags' => new ConnectException('Connection refused', new Request('GET', 'http://ollama.test:11434/api/tags')),
    ]);

    $this->assertSame([], $adapter->listChatModels('', 'http://ollama.test:11434'));
  }

  /**
   * Anything that is not a 200 with a `models` array is no catalogue.
   */
  public function testMalformedAnswersAreNotCatalogues(): void {
    $base = 'http://ollama.test:11434';

    $this->assertSame([], $this->adapter(['/api/tags' => new Response(500, [], 'boom')])->listChatModels('', $base));
    $this->assertSame([], $this->adapter(['/api/tags' => new Response(200, [], 'not json')])->listChatModels('', $base));
    $this->assertSame([], $this->adapter(['/api/tags' => new Response(200, [], '{"models":"nope"}')])->listChatModels('', $base));
    $this->assertSame([], $this->adapter(['/api/tags' => new Response(200, [], '{"models":[]}')])->listChatModels('', $base));
  }

  /**
   * What an operator plausibly types is normalised into a URL that works.
   *
   * Ollama's own docs print `http://localhost:11434/api/...`, so both a bare
   * `host:port` and a URL ending in `/api` are what people paste. Neither may
   * produce `//api/tags` or an https:// request to a server that speaks plain
   * HTTP.
   *
   * @param string $typed
   *   What the operator entered.
   */
  #[DataProvider('endpointsProvider')]
  public function testItNormalisesWhatAnOperatorTypes(string $typed): void {
    $adapter = $this->adapter(['/api/tags' => $this->tags([])]);

    $adapter->listChatModels('', $typed);

    $this->assertSame(['http://host.docker.internal:11434/api/tags'], $this->requested);
  }

  /**
   * Endpoint spellings that must all mean the same server.
   *
   * @return iterable<string, list<string>>
   *   Test case name => [what was typed].
   */
  public static function endpointsProvider(): iterable {
    yield 'canonical' => ['http://host.docker.internal:11434'];
    yield 'trailing slash' => ['http://host.docker.internal:11434/'];
    yield 'no scheme' => ['host.docker.internal:11434'];
    yield 'copied from the docs' => ['http://host.docker.internal:11434/api'];
    yield 'padded' => ['  http://host.docker.internal:11434  '];
  }

  /**
   * An https:// URL the operator DID type is honoured, not overwritten.
   */
  public function testAnExplicitSchemeIsKept(): void {
    $adapter = $this->adapter(['/api/tags' => $this->tags([])]);

    $adapter->listChatModels('', 'https://ollama.internal.example');

    $this->assertSame(['https://ollama.internal.example/api/tags'], $this->requested);
  }

}
