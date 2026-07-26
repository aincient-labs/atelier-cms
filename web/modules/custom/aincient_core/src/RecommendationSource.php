<?php

declare(strict_types=1);

namespace Drupal\aincient_core;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\StateInterface;
use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Where the curated recommendation document comes from: bundled, or fetched.
 *
 * The document ({@see \Drupal\aincient_core\ModelRecommendations} for the quality
 * labels, {@see ModelPresetResolver} for the profiles) answers "which model should
 * this site use?" — and the honest answer changes on roughly a weekly cadence:
 * models get retired, prices move, a provider's flash tier overtakes another's mid
 * tier. Guidance baked into a release is stale the moment it ships.
 *
 * So the canonical document is PUBLISHED at {@see self::URL} and hand-maintained in
 * the website repo, and what ships inside the module is a *snapshot* of it (synced
 * by `apps/website/bin/sync-recommendations`). This service is the seam between the
 * two:
 *
 * - {@see self::document()} returns the fetched document when one is cached and
 *   understood, else the bundled snapshot. Everything downstream reads through
 *   here and never needs to know which it got.
 * - {@see self::refresh()} performs the fetch. It is called ONLY from an explicit
 *   operator action ("Check for updates" in onboarding / the models settings form /
 *   `drush aincient:models-refresh`). There is no cron, no on-install fetch, and no
 *   background retry: an Atelier appliance phones home nothing unless a human asks
 *   it to (DECISIONS 0239). A failed refresh is never fatal — the bundled snapshot
 *   keeps working, which is the whole reason it is bundled.
 *
 * The fetched document is stored in State, not config: it is remote data, not an
 * operator's decision, and it must never land in `drush cex` output or git.
 *
 * ## Threat model
 *
 * This is the only untrusted input that ever enters the appliance from us, so it
 * is treated as hostile even though we author it. The transport is pinned to
 * HTTPS with verification and no redirects ({@see self::REQUEST_OPTIONS}); the
 * body is size-capped while streaming, refused if it is markup, and refused if it
 * uses YAML anchors; and it is parsed in Symfony's default mode, which cannot
 * instantiate a PHP object or resolve a constant.
 *
 * The document is **data, never code**. Nothing in it is evaluated, included,
 * used as a callable, a service id, a file path, or a shell argument. Its strings
 * reach exactly two places: rendered labels (escaped by React in the console and
 * by the render/form API in Drupal), and {@see ModelPresetResolver}, which uses
 * them only to *select among models the local provider plugins already offer* —
 * every value it returns is a key from that locally-built pool, so a compromised
 * document cannot inject a provider or model id of its own choosing. The worst a
 * hostile document can do is pick a bad-but-real model, or make itself rejected.
 */
final class RecommendationSource {

  /**
   * The highest document `schema` this release understands.
   *
   * A document declaring anything higher is REJECTED wholesale rather than
   * partially read — a schema bump means a shape we can't reason about, and a
   * half-understood recommendation is worse than a slightly old one.
   */
  public const SCHEMA = 1;

  /**
   * The published document. Plain text on purpose — inspectable like install.sh.
   */
  public const URL = 'https://aincient-labs.com/atelier/models.yml';

  /**
   * State keys: the fetched document, and when we fetched it.
   */
  private const STATE_DOC = 'aincient.model_recommendations';
  private const STATE_FETCHED = 'aincient.model_recommendations_fetched';

  /**
   * Refuse a response larger than this (bytes).
   *
   * The real document is a few KB; anything near this is a misconfigured URL or
   * a captive portal, not us.
   */
  private const MAX_BYTES = 262144;

  /**
   * Guzzle options for the one outbound request this product makes.
   *
   * Each entry is a deliberate restriction, not a default worth re-deriving:
   *
   * - `allow_redirects: FALSE` — Guzzle's default follows up to 5 redirects over
   *   `['http', 'https']`, so a single `301` to an `http://` URL would silently
   *   downgrade the transport. We request one exact path we control and accept
   *   only a direct `200`; if the document ever moves, we move this constant.
   * - `verify: TRUE` — explicit TLS certificate + hostname verification. Not
   *   merely restating Guzzle's default: Drupal's `http_client` is built by a
   *   factory that merges `$settings['http_client_config']` from `settings.php`,
   *   where a site can set `verify: FALSE` globally (people do, to get past a
   *   corporate MITM proxy). A per-request option beats the client default, so
   *   stating it here keeps this one request verified regardless.
   * - `stream: TRUE` — the body is read through {@see self::readCapped()} rather
   *   than buffered whole, so a hostile or broken endpoint cannot exhaust memory
   *   before we get a chance to check its size.
   * - short timeouts — this runs inside an admin request; it must fail fast.
   */
  private const REQUEST_OPTIONS = [
    'timeout' => 5,
    'connect_timeout' => 5,
    'allow_redirects' => FALSE,
    'verify' => TRUE,
    'stream' => TRUE,
    'headers' => ['Accept' => 'text/plain, text/yaml, */*'],
  ];

  /**
   * Resolved document, memoised per request.
   *
   * @var array<string, mixed>|null
   */
  private ?array $document = NULL;

  public function __construct(
    private readonly ModuleExtensionList $moduleList,
    private readonly StateInterface $state,
    private readonly ClientInterface $httpClient,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly TimeInterface $time,
  ) {}

  /**
   * The document in force: the fetched one if usable, else the bundled snapshot.
   *
   * @return array<string, mixed>
   *   The parsed document.
   */
  public function document(): array {
    if ($this->document === NULL) {
      $remote = $this->state->get(self::STATE_DOC);
      $this->document = (is_array($remote) && $this->isUsable($remote))
        ? $remote
        : $this->bundled();
    }
    return $this->document;
  }

  /**
   * Provenance for the UI's "bundled 2026-07-25 · Check for updates" line.
   *
   * @return array{updated: string, source: string, fetchedAt: int|null, url: string}
   *   The document's date, whether it is bundled or fetched, when it was
   *   fetched, and the URL a refresh would call.
   */
  public function meta(): array {
    $remote = $this->state->get(self::STATE_DOC);
    $isRemote = is_array($remote) && $this->isUsable($remote);
    return [
      'updated' => (string) ($this->document()['updated'] ?? ''),
      'source' => $isRemote ? 'remote' : 'bundled',
      'fetchedAt' => $isRemote ? (int) $this->state->get(self::STATE_FETCHED, 0) : NULL,
      'url' => self::URL,
    ];
  }

  /**
   * Fetch the published document and cache it. Explicit operator action only.
   *
   * @return array{updated: string, source: string, fetchedAt: int|null, url: string}
   *   The refreshed {@see self::meta()}.
   *
   * @throws \RuntimeException
   *   When the document can't be fetched, parsed, or understood. The caller is
   *   expected to surface this as an inline message and carry on — the previously
   *   held document (fetched or bundled) is left untouched.
   */
  public function refresh(): array {
    try {
      $response = $this->httpClient->request('GET', self::URL, self::REQUEST_OPTIONS);
    }
    catch (\Throwable $e) {
      $this->fail("Couldn't reach aincient-labs.com. Your bundled suggestions are still in use.", $e->getMessage());
    }

    // Redirects are disabled, so anything but a 200 is someone else answering —
    // a 3xx here means the URL moved and we should move with it deliberately,
    // not follow whatever Location we were handed.
    if ($response->getStatusCode() !== 200) {
      $this->fail(
        "Couldn't reach aincient-labs.com. Your bundled suggestions are still in use.",
        sprintf('unexpected status %d', $response->getStatusCode()),
      );
    }

    $body = $this->readCapped($response);

    // A missing file behind a SPA-style catch-all comes back as an HTML page with
    // a 200, so "did the request succeed?" is not the question — "is this our
    // document?" is. Reject anything markup-shaped before handing it to a parser.
    if (str_starts_with(ltrim($body), '<')) {
      $this->fail(
        "That didn't look like the recommendations file. Your bundled suggestions are still in use.",
        'response body is markup, not YAML',
      );
    }

    // Refuse two YAML constructs the real document never uses, before the parser
    // ever sees them. Rejecting a feature we don't need removes a whole class of
    // risk rather than bounding it.
    //
    // 1. Anchors/aliases (`&a` / `*a`) — unbounded alias expansion is the classic
    //    "billion laughs" memory bomb. The byte cap above does NOT help: a few
    //    hundred bytes of nested aliases expand to gigabytes during parsing, and
    //    Symfony's parser imposes no depth limit of its own.
    // 2. `!php/` tags — Symfony's default mode already neuters these (they parse
    //    to NULL; only PARSE_OBJECT / PARSE_CONSTANT would make them live, and we
    //    pass no flags). This is belt-and-braces so that a future careless flag
    //    can't turn a published document into object instantiation. Unknown tags
    //    already throw on their own.
    if (preg_match('/(?:^|[\s\[{,])[&*][^\s,\]}\'"]+/m', $body) === 1) {
      $this->fail(
        "That didn't look like the recommendations file. Your bundled suggestions are still in use.",
        'response uses YAML anchors/aliases, which the document never does',
      );
    }
    if (stripos($body, '!php/') !== FALSE) {
      $this->fail(
        "That didn't look like the recommendations file. Your bundled suggestions are still in use.",
        'response uses a !php/ YAML tag',
      );
    }

    try {
      // No flags: Symfony's DEFAULT parse mode is the safe one — `!php/object`
      // needs PARSE_OBJECT, `!php/const` needs PARSE_CONSTANT, and unknown tags
      // need PARSE_CUSTOM_TAGS. Without them each of those throws instead of
      // instantiating anything, so parsing this document can never construct a
      // PHP object or resolve a constant. Do not add flags here.
      $parsed = Yaml::parse($body);
    }
    catch (\Throwable $e) {
      $this->fail("The recommendations file couldn't be read. Your bundled suggestions are still in use.", $e->getMessage());
    }

    if (!is_array($parsed) || !$this->isUsable($parsed)) {
      // The most likely cause by far is a schema this release predates — say so,
      // because "update Atelier" is the actionable advice, not "try again".
      $this->fail(
        'These suggestions need a newer version of Atelier. Your bundled suggestions are still in use.',
        sprintf('document schema %s, understood up to %d', var_export($parsed['schema'] ?? NULL, TRUE), self::SCHEMA),
      );
    }

    $this->state->set(self::STATE_DOC, $parsed);
    $this->state->set(self::STATE_FETCHED, $this->time->getRequestTime());
    $this->document = $parsed;

    return $this->meta();
  }

  /**
   * Drop the fetched document, reverting to the bundled snapshot.
   */
  public function forget(): void {
    $this->state->delete(self::STATE_DOC);
    $this->state->delete(self::STATE_FETCHED);
    $this->document = NULL;
  }

  /**
   * Read at most {@see self::MAX_BYTES} from the response, then give up.
   *
   * Checking `strlen()` after `(string) $response->getBody()` would be too late:
   * the cast buffers the entire body first, so an endless or enormous response
   * exhausts PHP's memory limit before the check ever runs. Reading in chunks and
   * stopping at the cap makes the limit real. A `Content-Length` header is not
   * trusted for this — it is attacker-controlled and may simply be absent.
   *
   * @param \Psr\Http\Message\ResponseInterface $response
   *   The streamed response.
   *
   * @return string
   *   The body, guaranteed no larger than the cap.
   */
  private function readCapped(ResponseInterface $response): string {
    $stream = $response->getBody();
    $body = '';
    while (!$stream->eof() && strlen($body) <= self::MAX_BYTES) {
      $chunk = $stream->read(8192);
      if ($chunk === '') {
        break;
      }
      $body .= $chunk;
    }
    $stream->close();

    if (strlen($body) > self::MAX_BYTES) {
      $this->fail(
        "That didn't look like the recommendations file. Your bundled suggestions are still in use.",
        sprintf('response exceeded the %d byte cap', self::MAX_BYTES),
      );
    }
    return $body;
  }

  /**
   * Log the diagnosis, throw the sentence a human should read.
   *
   * The two are deliberately different. Whatever came back from the network is
   * untrusted and often enormous (an HTML error page, a captive portal): it
   * belongs in the log, never in a message rendered back to the operator — and
   * never chained as a previous exception, since Drush and Drupal both print the
   * whole chain.
   *
   * @throws \RuntimeException
   *   Always.
   */
  private function fail(string $message, string $detail): never {
    $this->loggerFactory->get('aincient_core')->warning(
      'Model recommendations could not be refreshed from @url: @detail',
      // Clamped: a Guzzle client error carries the whole response body, and a
      // 404 HTML page in dblog helps nobody.
      ['@url' => self::URL, '@detail' => mb_substr($detail, 0, 300)],
    );
    throw new \RuntimeException($message);
  }

  /**
   * Whether a parsed document is one this release can act on.
   *
   * @param array<string, mixed> $document
   *   The parsed document.
   */
  private function isUsable(array $document): bool {
    $schema = $document['schema'] ?? NULL;
    if (!is_int($schema) || $schema > self::SCHEMA) {
      return FALSE;
    }
    // A document with no profiles is not worth preferring over the bundled one —
    // the profiles are the reason to fetch at all.
    return !empty($document['profiles']) && is_array($document['profiles']);
  }

  /**
   * The snapshot shipped in the module root.
   *
   * @return array<string, mixed>
   *   The parsed snapshot, or an empty array if it is missing or unreadable.
   */
  private function bundled(): array {
    $path = $this->moduleList->getPath('aincient_core') . '/model-recommendations.yml';
    $parsed = is_file($path) ? Yaml::parseFile($path) : [];
    return is_array($parsed) ? $parsed : [];
  }

}
