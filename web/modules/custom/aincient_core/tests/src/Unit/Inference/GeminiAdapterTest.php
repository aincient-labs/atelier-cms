<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_core\Unit\Inference;

use Drupal\aincient_core\Inference\Adapter\GeminiAdapter;
use Drupal\aincient_core\Inference\Adapter\NanoBananaAdapter;
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
 * Conformance tests for the two Gemini-backed adapters.
 *
 * The contract's whole value is that an adapter answers honestly, so what is
 * asserted here is mostly what happens when the answer is inconvenient: a bad
 * key, an unreachable host, a response shaped like something else. An adapter
 * that returned a plausible catalogue in any of those cases would make onboarding
 * validate a credential that cannot serve a single turn.
 */
#[CoversClass(GeminiAdapter::class)]
#[CoversClass(NanoBananaAdapter::class)]
final class GeminiAdapterTest extends TestCase {

  /**
   * A Guzzle client that always answers with one response (or throws).
   */
  private function guzzle(Response|\Throwable|NULL $result): ClientInterface {
    $client = $this->createMock(ClientInterface::class);
    if ($result instanceof \Throwable) {
      $client->method('request')->willThrowException($result);
    }
    elseif ($result !== NULL) {
      $client->method('request')->willReturn($result);
    }
    else {
      // No credential should mean no request at all — assert that rather than
      // letting a stubbed response hide a wasted round-trip.
      $client->expects($this->never())->method('request');
    }
    return $client;
  }

  /**
   * The chat adapter over a stubbed HTTP client.
   */
  private function gemini(Response|\Throwable|NULL $result): GeminiAdapter {
    return new GeminiAdapter(
      $this->createMock(HttpClientInterface::class),
      $this->guzzle($result),
      $this->createMock(LoggerInterface::class),
    );
  }

  /**
   * The image adapter over a stubbed HTTP client.
   */
  private function nanoBanana(Response|\Throwable|NULL $result): NanoBananaAdapter {
    return new NanoBananaAdapter(
      $this->createMock(HttpClientInterface::class),
      $this->guzzle($result),
      $this->createMock(LoggerInterface::class),
    );
  }

  /**
   * A models response in the shape `GET /v1beta/models` really returns.
   *
   * Copied from a live call, trimmed: a chat model, the image model (whose record
   * is indistinguishable from a chat model's by generation method alone), an
   * embedding model, an Imagen model reachable only via `predict`, and a
   * text-to-speech model.
   */
  private function catalogue(): Response {
    return new Response(200, [], json_encode([
      'models' => [
        [
          'name' => 'models/gemini-2.5-flash',
          'displayName' => 'Gemini 2.5 Flash',
          'supportedGenerationMethods' => ['generateContent', 'countTokens'],
        ],
        [
          'name' => 'models/gemini-2.5-flash-image',
          'displayName' => 'Nano Banana',
          'supportedGenerationMethods' => ['generateContent', 'countTokens'],
        ],
        [
          'name' => 'models/nano-banana-pro-preview',
          'displayName' => 'Nano Banana Pro',
          'supportedGenerationMethods' => ['generateContent', 'countTokens'],
        ],
        [
          'name' => 'models/gemini-embedding-001',
          'displayName' => 'Gemini Embedding 001',
          'supportedGenerationMethods' => ['embedContent', 'countTokens'],
        ],
        [
          'name' => 'models/imagen-4.0-generate-001',
          'displayName' => 'Imagen 4',
          'supportedGenerationMethods' => ['predict'],
        ],
        [
          'name' => 'models/gemini-2.5-flash-preview-tts',
          'displayName' => 'Gemini 2.5 Flash Preview TTS',
          'supportedGenerationMethods' => ['generateContent', 'countTokens'],
        ],
      ],
    ], JSON_THROW_ON_ERROR));
  }

  /**
   * The ids and labels are the strings stored bindings name.
   *
   * `nanobanana` especially: it is not a vendor, it is our historical plugin id,
   * and a "tidy-up" rename here silently orphans an operator's image role.
   */
  public function testStableIdentity(): void {
    $gemini = $this->gemini(NULL);
    $this->assertSame('gemini', $gemini->id());
    $this->assertSame('Google Gemini', $gemini->label());
    $this->assertSame(ProviderAdapterInterface::AUTH_KEY, $gemini->authShape());
    $this->assertFalse($gemini->isProxy());

    $nanoBanana = $this->nanoBanana(NULL);
    $this->assertSame('nanobanana', $nanoBanana->id());
    $this->assertSame('Nano Banana (Gemini images)', $nanoBanana->label());
    $this->assertSame(ProviderAdapterInterface::AUTH_KEY, $nanoBanana->authShape());
    $this->assertFalse($nanoBanana->isProxy());
  }

  /**
   * BOTH Google ids can draw, because the capability belongs to the key.
   *
   * `gemini` used to answer FALSE here, and the consequence was issue #12's second
   * surprise: an operator who connected their Google key as `gemini` got an empty
   * image picker, with no way to learn that a second id backed by the very same key
   * was the missing piece.
   */
  public function testBothGoogleIdsAreImageCapable(): void {
    $gemini = $this->gemini(NULL);
    $this->assertInstanceOf(ImageGenerationAdapterInterface::class, $gemini);
    $this->assertTrue($gemini->supportsImageEditing());

    $nanoBanana = $this->nanoBanana(NULL);
    $this->assertInstanceOf(ImageGenerationAdapterInterface::class, $nanoBanana);
    $this->assertTrue($nanoBanana->supportsImageEditing());
  }

  /**
   * A plain Gemini connection has a non-empty image pool. THE #12 FIX.
   */
  public function testPlainGeminiEnumeratesImageModels(): void {
    $models = $this->gemini($this->catalogue())->listImageModels('k');
    $this->assertSame([
      'gemini-2.5-flash-image' => 'Nano Banana',
      'nano-banana-pro-preview' => 'Nano Banana Pro',
    ], $models);
  }

  /**
   * The two pools stay disjoint, so nothing is offered twice.
   *
   * The subclass inherits both enumerations now, so a marker drifting out of
   * `NON_TEXT_OUTPUT_MARKERS` would put a picture model into every chat select
   * under two provider ids at once. Asserted as an intersection rather than a
   * literal list, so it holds whatever Google's catalogue grows into.
   */
  public function testTheChatAndImagePoolsAreDisjoint(): void {
    foreach ([$this->gemini($this->catalogue()), $this->nanoBanana($this->catalogue())] as $adapter) {
      $this->assertSame([], array_intersect_key(
        $adapter->listChatModels('k'),
        $adapter->listImageModels('k'),
      ));
    }
  }

  /**
   * An empty credential enumerates nothing and never leaves the process.
   */
  public function testEmptyCredentialListsNothing(): void {
    $this->assertSame([], $this->gemini(NULL)->listChatModels(''));
    $this->assertSame([], $this->gemini(NULL)->listChatModels('   '));
    $this->assertSame([], $this->nanoBanana(NULL)->listImageModels(''));
    $this->assertSame([], $this->nanoBanana(NULL)->listChatModels(''));
  }

  /**
   * An empty credential is a configuration error, not an empty platform.
   *
   * The distinction the registry relies on: no models means "cannot validate",
   * no platform means "do not attempt a turn".
   */
  public function testEmptyCredentialCannotBuildPlatform(): void {
    $this->expectException(ProviderConfigurationException::class);
    $this->expectExceptionMessage('No Google API key is stored for provider "gemini".');
    $this->gemini(NULL)->createPlatform('');
  }

  /**
   * The image id names itself in the same failure.
   */
  public function testImageAdapterNamesItselfWhenUnconfigured(): void {
    $this->expectException(ProviderConfigurationException::class);
    $this->expectExceptionMessage('No Google API key is stored for provider "nanobanana".');
    $this->nanoBanana(NULL)->createPlatform('  ');
  }

  /**
   * A real credential yields a platform without touching the network.
   */
  public function testCredentialYieldsPlatform(): void {
    $this->gemini(NULL)->createPlatform('AIza-looks-real');
    // Reaching this line is the assertion: no exception, and the `never()`
    // expectation on the client proves construction is not a round-trip.
    $this->addToAssertionCount(1);
  }

  /**
   * A rejected key (Google answers 400, not 401) enumerates nothing.
   */
  public function testRejectedKeyListsNothing(): void {
    $response = new Response(400, [], '{"error":{"code":400,"message":"API key not valid"}}');
    $this->assertSame([], $this->gemini($response)->listChatModels('garbage'));
    $this->assertSame([], $this->nanoBanana($response)->listImageModels('garbage'));
  }

  /**
   * A 200 that is not the shape we expect enumerates nothing.
   *
   * Covers the captive-portal / proxy-error-page case, where the status line lies
   * and the body is the only evidence.
   */
  public function testMalformedResponseListsNothing(): void {
    $this->assertSame([], $this->gemini(new Response(200, [], 'not json'))->listChatModels('k'));
    $this->assertSame([], $this->gemini(new Response(200, [], '{"data":[]}'))->listChatModels('k'));
    $this->assertSame([], $this->gemini(new Response(200, [], '"a string"'))->listChatModels('k'));
  }

  /**
   * A network fault is reported as "no models", and logged as itself.
   */
  public function testNetworkFaultListsNothingAndLogs(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('warning');
    $adapter = new GeminiAdapter(
      $this->createMock(HttpClientInterface::class),
      $this->guzzle(new ConnectException('dns failure', new Request('GET', 'https://example.com'))),
      $logger,
    );
    $this->assertSame([], $adapter->listChatModels('k'));
  }

  /**
   * Chat enumeration keeps the text models and drops every other modality.
   */
  public function testChatEnumerationFiltersByModality(): void {
    $models = $this->gemini($this->catalogue())->listChatModels('k');
    $this->assertSame(['gemini-2.5-flash' => 'Gemini 2.5 Flash'], $models);
  }

  /**
   * Image enumeration keeps both id shapes Google uses for image output.
   */
  public function testImageEnumerationKeepsImageModels(): void {
    $models = $this->nanoBanana($this->catalogue())->listImageModels('k');
    $this->assertSame([
      'gemini-2.5-flash-image' => 'Nano Banana',
      'nano-banana-pro-preview' => 'Nano Banana Pro',
    ], $models);
  }

  /**
   * A model that cannot be reached by `generateContent` is never offered.
   *
   * Imagen is the trap: it is unambiguously an image generator, and this bridge
   * cannot call it at all (it speaks `predict`). Offering it would produce a
   * binding that fails on every use.
   */
  public function testImageEnumerationDropsUnreachableGenerators(): void {
    $models = $this->nanoBanana($this->catalogue())->listImageModels('k');
    $this->assertArrayNotHasKey('imagen-4.0-generate-001', $models);
  }

  /**
   * The image id still enumerates chat models, because that is the honest answer.
   *
   * Onboarding proves a credential by asking for models; the credential here is a
   * plain Google key, so it can reach Gemini's chat catalogue and saying otherwise
   * would fail a working key.
   */
  public function testImageAdapterStillEnumeratesChatModels(): void {
    $this->assertSame(
      ['gemini-2.5-flash' => 'Gemini 2.5 Flash'],
      $this->nanoBanana($this->catalogue())->listChatModels('k'),
    );
  }

  /**
   * The credential travels in a header, never in the URL.
   *
   * Google's quickstarts show `?key=…`, which would put the secret into every
   * access log and into any exception message that quotes the request. Pinned in a
   * test because it is invisible in behaviour and easy to "simplify" back.
   */
  public function testCredentialTravelsInHeader(): void {
    $client = $this->createMock(ClientInterface::class);
    $client->expects($this->once())
      ->method('request')
      ->with(
        'GET',
        'https://generativelanguage.googleapis.com/v1beta/models',
        $this->callback(function (array $options): bool {
          $this->assertSame('secret-key', $options['headers']['x-goog-api-key'] ?? NULL);
          $this->assertArrayNotHasKey('key', $options['query'] ?? []);
          return TRUE;
        }),
      )
      ->willReturn($this->catalogue());

    $adapter = new GeminiAdapter(
      $this->createMock(HttpClientInterface::class),
      $client,
      $this->createMock(LoggerInterface::class),
    );
    $adapter->listChatModels('secret-key');
  }

  /**
   * An operator's endpoint override is honoured, trailing slash and all.
   */
  public function testEndpointOverrideIsHonoured(): void {
    $client = $this->createMock(ClientInterface::class);
    $client->expects($this->once())
      ->method('request')
      ->with('GET', 'https://gemini.internal/v1beta/models', $this->anything())
      ->willReturn($this->catalogue());

    $adapter = new GeminiAdapter(
      $this->createMock(HttpClientInterface::class),
      $client,
      $this->createMock(LoggerInterface::class),
    );
    $adapter->listChatModels('k', 'https://gemini.internal/');
  }

  /**
   * A record missing a display name falls back to its id, not to nothing.
   */
  public function testMissingDisplayNameFallsBackToId(): void {
    $response = new Response(200, [], json_encode([
      'models' => [
        [
          'name' => 'models/gemini-3.6-flash',
          'supportedGenerationMethods' => ['generateContent'],
        ],
        ['supportedGenerationMethods' => ['generateContent']],
      ],
    ], JSON_THROW_ON_ERROR));
    $this->assertSame(
      ['gemini-3.6-flash' => 'gemini-3.6-flash'],
      $this->gemini($response)->listChatModels('k'),
    );
  }

}
