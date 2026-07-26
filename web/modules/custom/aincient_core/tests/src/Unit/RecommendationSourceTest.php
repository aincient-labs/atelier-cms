<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_core\Unit;

use Drupal\aincient_core\RecommendationSource;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Symfony\Component\Yaml\Yaml;
use Drupal\Tests\aincient_core\Unit\Fixtures\MemoryState;

/**
 * Unit-tests the bundled/fetched recommendation seam.
 *
 * The behaviour that matters here is what happens when the fetch goes WRONG —
 * an install must never end up worse off for having clicked "Check for updates".
 *
 * @group aincient
 * @covers \Drupal\aincient_core\RecommendationSource
 */
final class RecommendationSourceTest extends UnitTestCase {

  /**
   * A source over a stubbed HTTP client and an in-memory State.
   */
  private function source(ClientInterface $client, ?MemoryState $state = NULL): RecommendationSource {
    // tests/src/Unit -> aincient_core, so the real bundled snapshot is the
    // fallback — which is exactly what we want to assert survives a failure.
    $moduleRoot = dirname(__DIR__, 3);
    $moduleList = $this->createMock(ModuleExtensionList::class);
    $moduleList->method('getPath')->with('aincient_core')->willReturn($moduleRoot);

    $logger = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger->method('get')->willReturn($this->createMock(LoggerChannelInterface::class));

    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1800000000);

    return new RecommendationSource($moduleList, $state ?? new MemoryState(), $client, $logger, $time);
  }

  /**
   * An HTTP client that always returns one response (or throws).
   */
  private function client(Response|\Throwable $result): ClientInterface {
    $client = $this->createMock(ClientInterface::class);
    if ($result instanceof \Throwable) {
      $client->method('request')->willThrowException($result);
    }
    else {
      $client->method('request')->willReturn($result);
    }
    return $client;
  }

  /**
   * A valid document, in the format the website serves.
   */
  private function publishedYaml(int $schema = 1): string {
    return <<<YAML
    schema: {$schema}
    updated: '2099-01-01'
    default_profile: balanced
    providers:
      anthropic: recommended
    models:
      anthropic:
        recommended: [sonnet]
    profiles:
      balanced:
        label: Balanced
        description: Sensible.
        roles:
          reasoning: [anthropic:claude-sonnet-5]
    YAML;
  }

  /**
   * With nothing fetched, the bundled snapshot is in force.
   */
  public function testFallsBackToTheBundledSnapshot(): void {
    $source = $this->source($this->client(new Response(200, [], '')));
    $meta = $source->meta();
    $this->assertSame('bundled', $meta['source']);
    $this->assertNull($meta['fetchedAt']);
    // The shipped file really parsed — profiles present, not an empty array.
    $this->assertNotEmpty($source->document()['profiles']);
  }

  /**
   * A good fetch is cached in State and becomes the document in force.
   */
  public function testRefreshCachesTheFetchedDocument(): void {
    $state = new MemoryState();
    $source = $this->source($this->client(new Response(200, [], $this->publishedYaml())), $state);

    $meta = $source->refresh();
    $this->assertSame('remote', $meta['source']);
    $this->assertSame('2099-01-01', $meta['updated']);
    $this->assertSame(1800000000, $meta['fetchedAt']);
    $this->assertSame('2099-01-01', $source->document()['updated']);
    // Persisted, not just memoised — a later request sees it too.
    $this->assertIsArray($state->get('aincient.model_recommendations'));
  }

  /**
   * A newer schema is refused wholesale; the bundled document stays in force.
   *
   * Half-understanding a document is worse than being slightly out of date.
   */
  public function testRejectsFutureSchema(): void {
    $state = new MemoryState();
    $source = $this->source($this->client(new Response(200, [], $this->publishedYaml(99))), $state);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('newer version of Atelier');
    try {
      $source->refresh();
    }
    finally {
      $this->assertNull($state->get('aincient.model_recommendations'));
      $this->assertSame('bundled', $source->meta()['source']);
    }
  }

  /**
   * An HTML page returned with a 200 is not mistaken for our document.
   *
   * This is the real-world failure: a missing file behind a catch-all serves the
   * site's 404 page with a 200, so the status code proves nothing.
   */
  public function testRejectsAnHtmlErrorPage(): void {
    $html = "<!doctype html>\n<html><head><title>Not found</title></head></html>";
    $source = $this->source($this->client(new Response(200, [], $html)));

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage("didn't look like the recommendations file");
    $source->refresh();
  }

  /**
   * A network failure is reported cleanly and leaks no response body.
   *
   * Guzzle's own message carries the entire response; that belongs in the log,
   * never in the sentence an operator reads.
   */
  public function testNetworkFailureIsCleanAndNonFatal(): void {
    $boom = new ConnectException('cURL error 6: could not resolve host', new Request('GET', RecommendationSource::URL));
    $source = $this->source($this->client($boom));

    try {
      $source->refresh();
      $this->fail('Expected a RuntimeException.');
    }
    catch (\RuntimeException $e) {
      $this->assertStringContainsString('bundled suggestions are still in use', $e->getMessage());
      $this->assertStringNotContainsString('cURL', $e->getMessage());
      // Not chained — Drush and Drupal print the whole chain.
      $this->assertNull($e->getPrevious());
    }
    // Still usable afterwards, which is the entire point of bundling a snapshot.
    $this->assertNotEmpty($source->document()['profiles']);
  }

  /**
   * The transport is pinned: HTTPS, verified, and no redirect following.
   *
   * Guzzle's DEFAULT is to follow up to 5 redirects over http *or* https, so a
   * single `301` from a compromised or misconfigured edge would downgrade this
   * to cleartext. That default must never be silently inherited here.
   */
  public function testRequestIsHttpsVerifiedAndDoesNotFollowRedirects(): void {
    $seen = [];
    $client = $this->createMock(ClientInterface::class);
    $client->method('request')->willReturnCallback(
      function (string $method, string $url, array $options) use (&$seen): Response {
        $seen = ['method' => $method, 'url' => $url, 'options' => $options];
        return new Response(200, [], $this->publishedYaml());
      },
    );

    $this->source($client)->refresh();

    $this->assertSame('GET', $seen['method']);
    $this->assertStringStartsWith('https://', $seen['url']);
    $this->assertFalse($seen['options']['allow_redirects']);
    $this->assertTrue($seen['options']['verify']);
    $this->assertTrue($seen['options']['stream']);
  }

  /**
   * A redirect is refused rather than followed.
   */
  public function testRefusesNonOkStatus(): void {
    $source = $this->source($this->client(new Response(301, ['Location' => 'http://evil.example/models.yml'], '')));

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage("Couldn't reach aincient-labs.com");
    $source->refresh();
  }

  /**
   * YAML anchors/aliases are refused — the "billion laughs" class, removed.
   *
   * The byte cap does not help here: a few hundred bytes of nested aliases can
   * expand to gigabytes during parsing. We never author anchors, so refusing them
   * outright is cheaper and safer than trying to bound the expansion.
   */
  public function testRejectsYamlAnchors(): void {
    $bomb = <<<YAML
    schema: 1
    updated: '2099-01-01'
    a: &a ["lol","lol","lol","lol","lol","lol","lol","lol","lol"]
    b: &b [*a,*a,*a,*a,*a,*a,*a,*a,*a]
    c: &c [*b,*b,*b,*b,*b,*b,*b,*b,*b]
    profiles:
      balanced:
        label: Balanced
        roles:
          reasoning: [anthropic:claude-sonnet-5]
    YAML;
    $source = $this->source($this->client(new Response(200, [], $bomb)));

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage("didn't look like the recommendations file");
    $source->refresh();
  }

  /**
   * Legitimate prose containing "&" or "*" is NOT mistaken for an anchor.
   *
   * The anchor guard has to be precise, or the first description with an
   * ampersand in it breaks every install's refresh.
   */
  public function testAmpersandsInProseAreFine(): void {
    $yaml = <<<YAML
    schema: 1
    updated: '2099-01-01'
    profiles:
      balanced:
        label: Balanced
        description: Fast & cheap, 2 * the value — good for most sites.
        roles:
          reasoning: [anthropic:claude-sonnet-5]
    YAML;
    $source = $this->source($this->client(new Response(200, [], $yaml)));

    $this->assertSame('remote', $source->refresh()['source']);
  }

  /**
   * A `!php/object` tag never reaches the parser.
   *
   * Two independent guarantees, and this pins both. Symfony's DEFAULT parse mode
   * is already safe — `!php/object` and `!php/const` yield NULL unless
   * PARSE_OBJECT / PARSE_CONSTANT are passed, and we pass no flags — but it
   * yields NULL *silently*. So we also refuse the tag outright, which means a
   * future careless flag cannot turn a published document into object
   * instantiation.
   */
  public function testRejectsPhpObjectTags(): void {
    $yaml = "schema: 1\nupdated: '2099-01-01'\nprofiles:\n  x: !php/object 'O:8:\"stdClass\":0:{}'\n";
    $source = $this->source($this->client(new Response(200, [], $yaml)));

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage("didn't look like the recommendations file");
    $source->refresh();
  }

  /**
   * The underlying parser is in its safe mode: no flags, so no instantiation.
   *
   * Belt-and-braces for the guard above — if the guard were ever relaxed, this
   * still asserts that parsing cannot construct an object or resolve a constant.
   */
  public function testParserModeCannotInstantiate(): void {
    $parsed = Yaml::parse("object: !php/object 'O:8:\"stdClass\":0:{}'\nconstant: !php/const PHP_INT_MAX\n");
    $this->assertNull($parsed['object']);
    $this->assertNull($parsed['constant']);
  }

  /**
   * An oversized body is abandoned mid-stream, not buffered then measured.
   */
  public function testRejectsAnOversizedBody(): void {
    // 1 MB of valid-looking YAML comment, well past the 256 KB cap.
    $source = $this->source($this->client(new Response(200, [], str_repeat("# padding\n", 110000))));

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage("didn't look like the recommendations file");
    $source->refresh();
  }

  /**
   * Forgetting drops the fetched document and reverts to the bundled one.
   */
  public function testForgetRevertsToBundled(): void {
    $state = new MemoryState();
    $source = $this->source($this->client(new Response(200, [], $this->publishedYaml())), $state);
    $source->refresh();
    $this->assertSame('remote', $source->meta()['source']);

    $source->forget();
    $this->assertSame('bundled', $source->meta()['source']);
    $this->assertNull($state->get('aincient.model_recommendations'));
  }

}
