<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_core\Unit\Inference;

use Drupal\aincient_core\Inference\Adapter\MistralAdapter;
use Drupal\aincient_core\Inference\Adapter\OpenAiAdapter;
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
 * Conformance tests for the two vendors the picker offered but could not serve.
 *
 * `openai` and `mistral` were in the wizard for as long as their `drupal/ai`
 * modules were installed, and binding either produced a
 * `ProviderConfigurationException` on the next turn — they are the ids named in
 * `ProviderInventory`'s docblock as the reason the inventory was rewritten. What
 * is asserted here is the thing that makes the offer honest: the catalogue comes
 * from the credential, and a credential that does not work produces no catalogue
 * rather than a plausible one.
 *
 * They share a class because they share a failure surface (a bearer key, a
 * `GET /v1/models`, a `data` array) and differ in exactly one interesting way:
 * Mistral's answer carries per-model capabilities and OpenAI's carries none, so
 * one filter is a read and the other is a heuristic. Testing them side by side is
 * what keeps that difference visible.
 */
#[CoversClass(OpenAiAdapter::class)]
#[CoversClass(MistralAdapter::class)]
final class OpenAiAdapterTest extends TestCase {

  /**
   * The URLs the last-built adapter asked for, in order.
   *
   * @var list<string>
   */
  private array $requested = [];

  /**
   * A Guzzle client that always answers with one response (or throws).
   */
  private function guzzle(Response|\Throwable|NULL $result): ClientInterface {
    $this->requested = [];
    $client = $this->createMock(ClientInterface::class);
    if ($result === NULL) {
      // No credential must mean no request at all — assert that rather than
      // letting a stubbed response hide a wasted round-trip.
      $client->expects($this->never())->method('request');
      return $client;
    }
    $client->method('request')->willReturnCallback(
      function (string $method, string $url) use ($result): Response {
        $this->requested[] = $url;
        if ($result instanceof \Throwable) {
          throw $result;
        }
        return $result;
      },
    );
    return $client;
  }

  /**
   * The OpenAI adapter over a stubbed HTTP client.
   */
  private function openAi(Response|\Throwable|NULL $result): OpenAiAdapter {
    return new OpenAiAdapter(
      $this->createMock(HttpClientInterface::class),
      $this->guzzle($result),
      $this->createMock(LoggerInterface::class),
    );
  }

  /**
   * The Mistral adapter over a stubbed HTTP client.
   */
  private function mistral(Response|\Throwable|NULL $result): MistralAdapter {
    return new MistralAdapter(
      $this->createMock(HttpClientInterface::class),
      $this->guzzle($result),
      $this->createMock(LoggerInterface::class),
    );
  }

  /**
   * A `/v1/models` body in the shape OpenAI really returns.
   *
   * Trimmed from a live call: chat models across two generations, plus one of
   * every other modality OpenAI serves from the same list — which is the whole
   * problem, since none of these records says what it can do.
   */
  private function openAiModels(): Response {
    return new Response(200, [], json_encode([
      'object' => 'list',
      'data' => array_map(
        static fn (string $id): array => ['id' => $id, 'object' => 'model', 'owned_by' => 'openai'],
        [
          'gpt-4.1',
          'gpt-4o',
          'o3',
          'text-embedding-3-large',
          'whisper-1',
          'tts-1-hd',
          'dall-e-3',
          'gpt-image-1',
          'omni-moderation-latest',
          'gpt-4o-realtime-preview',
          'gpt-3.5-turbo-instruct',
          'davinci-002',
        ],
      ),
    ], JSON_THROW_ON_ERROR));
  }

  /**
   * A `/v1/models` body in the shape Mistral really returns.
   *
   * The capability block is the part that matters: Mistral is the one vendor of
   * the set that answers "what can this model do?" in the same call.
   */
  private function mistralModels(): Response {
    return new Response(200, [], json_encode([
      'object' => 'list',
      'data' => [
        [
          'id' => 'mistral-large-latest',
          'name' => 'Mistral Large',
          'capabilities' => ['completion_chat' => TRUE, 'function_calling' => TRUE, 'vision' => FALSE],
        ],
        [
          'id' => 'open-mistral-nemo',
          'capabilities' => ['completion_chat' => TRUE, 'function_calling' => FALSE],
        ],
        [
          'id' => 'mistral-embed',
          'capabilities' => ['completion_chat' => FALSE, 'function_calling' => FALSE],
        ],
      ],
    ], JSON_THROW_ON_ERROR));
  }

  /**
   * Both are key providers, by their historical ids, and neither draws.
   */
  public function testTheyAreKeyProvidersUnderTheHistoricalIds(): void {
    $openAi = $this->openAi(NULL);
    $mistral = $this->mistral(NULL);

    $this->assertSame('openai', $openAi->id());
    $this->assertSame('mistral', $mistral->id());
    foreach ([$openAi, $mistral] as $adapter) {
      $this->assertSame(ProviderAdapterInterface::AUTH_KEY, $adapter->authShape());
      $this->assertTrue($adapter->servesChat());
      $this->assertFalse($adapter->isProxy());
      $this->assertNotInstanceOf(ImageGenerationAdapterInterface::class, $adapter);
    }
  }

  /**
   * No key, no platform.
   */
  public function testNoKeyMeansNoPlatform(): void {
    $this->expectException(ProviderConfigurationException::class);
    $this->openAi(NULL)->createPlatform('');
  }

  /**
   * No key, no platform — Mistral's half of the same rule.
   */
  public function testNoKeyMeansNoMistralPlatform(): void {
    $this->expectException(ProviderConfigurationException::class);
    $this->mistral(NULL)->createPlatform('');
  }

  /**
   * No key means no catalogue, and no wasted round-trip.
   */
  public function testNoKeyEnumeratesNothing(): void {
    $this->assertSame([], $this->openAi(NULL)->listChatModels(''));
    $this->assertSame([], $this->mistral(NULL)->listChatModels('  '));
  }

  /**
   * OpenAI's list is filtered by id, because there is nothing else to read.
   *
   * `/v1/models` returns embeddings, speech, images, moderation and the realtime
   * transport from the same endpoint, with no field distinguishing them. Offering
   * that list raw fills the role pickers with models that cannot answer.
   */
  public function testOpenAiKeepsOnlyChatModels(): void {
    $models = $this->openAi($this->openAiModels())->listChatModels('sk-test');

    $this->assertSame(['gpt-4.1', 'gpt-4o', 'o3'], array_keys($models));
    $this->assertSame('https://api.openai.com/v1/models', $this->requested[0]);
  }

  /**
   * Mistral's filter is a READ of what Mistral says, not a guess.
   *
   * And a model that cannot call tools keeps its place with the limit in its
   * label — the rule set by the Ollama adapter: only the agent loop needs tools,
   * a vision or plain-chat role does not, so hiding it would be the opposite lie.
   */
  public function testMistralReadsTheCapabilitiesItIsGiven(): void {
    $models = $this->mistral($this->mistralModels())->listChatModels('sk-test');

    $this->assertSame(
      [
        'mistral-large-latest' => 'Mistral Large',
        'open-mistral-nemo' => 'open-mistral-nemo — no tool calling',
      ],
      $models,
    );
  }

  /**
   * With no capability block, Mistral falls back rather than emptying the picker.
   *
   * An account whose response omits capabilities must not look like an account
   * whose key was refused — those need completely different remedies.
   */
  public function testMistralFallsBackWhenCapabilitiesAreAbsent(): void {
    $response = new Response(200, [], json_encode([
      'data' => [
        ['id' => 'mistral-small-latest'],
        ['id' => 'mistral-embed'],
        ['id' => 'mistral-ocr-latest'],
      ],
    ], JSON_THROW_ON_ERROR));

    $models = $this->mistral($response)->listChatModels('sk-test');

    $this->assertSame(['mistral-small-latest' => 'mistral-small-latest'], $models);
  }

  /**
   * A refused key yields no catalogue — which is how onboarding refuses it.
   */
  public function testRefusedKeyHasNoModels(): void {
    $unauthorized = new Response(401, [], '{"error":{"message":"Incorrect API key provided"}}');

    $this->assertSame([], $this->openAi($unauthorized)->listChatModels('sk-wrong'));
    $this->assertSame([], $this->mistral($unauthorized)->listChatModels('sk-wrong'));
  }

  /**
   * An unreachable vendor yields no catalogue either, and says so in the log.
   */
  public function testAnUnreachableVendorHasNoModels(): void {
    $down = new ConnectException('Connection timed out', new Request('GET', 'https://api.openai.com/v1/models'));

    $this->assertSame([], $this->openAi($down)->listChatModels('sk-test'));
    $this->assertSame([], $this->mistral($down)->listChatModels('sk-test'));
  }

  /**
   * Anything that is not a 200 carrying a `data` array is not a catalogue.
   */
  public function testMalformedAnswersAreNotCatalogues(): void {
    $this->assertSame([], $this->openAi(new Response(200, [], 'not json'))->listChatModels('sk-test'));
    $this->assertSame([], $this->openAi(new Response(200, [], '{"data":"nope"}'))->listChatModels('sk-test'));
    $this->assertSame([], $this->mistral(new Response(200, [], '"a string"'))->listChatModels('sk-test'));
    $this->assertSame([], $this->mistral(new Response(200, [], '{}'))->listChatModels('sk-test'));
  }

  /**
   * A stored base-URL override is honoured — a proxy in front is legitimate.
   */
  public function testAnEndpointOverrideIsUsed(): void {
    $this->mistral($this->mistralModels())->listChatModels('sk-test', 'https://mistral.proxy.example/');

    $this->assertSame('https://mistral.proxy.example/v1/models', $this->requested[0]);
  }

}
