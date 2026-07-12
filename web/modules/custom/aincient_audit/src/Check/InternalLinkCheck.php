<?php

declare(strict_types=1);

namespace Drupal\aincient_audit\Check;

use Drupal\aincient_pages\Controller\PageSpikeController;
use Drupal\aincient_pages\PageStore;
use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\Path\PathValidatorInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The internal-link integrity check.
 *
 * The `links` default policy's logic (DECISIONS 0129). Renders the page
 * chrome-less, pulls every `<a href>` with DOMDocument, and resolves each
 * INTERNAL link against Drupal's router (no HTTP). External links are counted
 * but not fetched (per the no-slow-HTTP decision); fragments/mailto/tel are
 * ignored. A link is "broken" only when no route/alias matches it — access
 * restrictions don't count (we use the access-free validator), so a valid page
 * the operator can't view is not a false positive.
 */
final class InternalLinkCheck implements CheckInterface {

  use FindingTrait;

  public function __construct(
    private readonly PageStore $store,
    private readonly ClassResolverInterface $classResolver,
    private readonly PathValidatorInterface $pathValidator,
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return 'links';
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return 'Internal links';
  }

  /**
   * {@inheritdoc}
   */
  public function evaluate(NodeInterface $node, array $params = []): array {
    // v1 has no tunable knobs — a broken link is broken at any threshold.
    $findings = [];
    $html = $this->renderHtml($node);
    if ($html === NULL) {
      $findings[] = $this->finding('links.render', self::WARN, 'No page content to scan', 'This page has no stored schema, so there are no links to check yet.', 'Links', 'content');
      return $findings;
    }

    $hrefs = $this->extractHrefs($html);
    if ($hrefs === []) {
      $findings[] = $this->finding('links.none', self::PASS, 'No links to check', 'This page has no links in its content.', 'Links', 'content');
      return $findings;
    }

    $internalOk = 0;
    $external = 0;
    $brokenSeen = [];
    foreach ($hrefs as $href) {
      $kind = $this->classify($href);
      if ($kind === 'skip') {
        continue;
      }
      if ($kind === 'external') {
        $external++;
        continue;
      }
      $path = $this->internalPath($href);
      if ($path === NULL) {
        continue;
      }
      // Access-free: a route/alias exists, regardless of who may view it.
      if ($this->pathValidator->getUrlIfValidWithoutAccessCheck($path)) {
        $internalOk++;
      }
      elseif (!isset($brokenSeen[$path])) {
        $brokenSeen[$path] = TRUE;
        // `content` dimension: the broken href lives in a page section. The
        // repair agent locates it by `target.href` and rewrites just that prop
        // (no manual inline editor for links in v1 — Phase 2 keeps it AI-only).
        $findings[] = $this->finding('links.broken:' . $path, self::FAIL, 'Broken internal link', sprintf('“%s” does not resolve to a page on this site.', $href), 'Links', 'content', ['action' => 'edit_prop', 'target' => ['href' => $href], 'aiFixable' => TRUE]);
      }
    }

    if ($brokenSeen === []) {
      $findings[] = $this->finding('links.internal_ok', self::PASS, 'Internal links resolve', sprintf('%d internal link%s checked — all valid.', $internalOk, $internalOk === 1 ? '' : 's'), 'Links', 'content');
    }
    if ($external > 0) {
      $findings[] = $this->finding('links.external', self::PASS, 'External links (not fetched)', sprintf('%d external link%s found — listed, not requested.', $external, $external === 1 ? '' : 's'), 'Links', 'content');
    }

    return $findings;
  }

  /**
   * Render the page's stored schema to chrome-less HTML (no persist), or NULL
   * when the page has no schema yet. Reuses the studio's render seam so the
   * markup matches a live page exactly.
   */
  private function renderHtml(NodeInterface $node): ?string {
    $schema = $this->store->load((string) $node->id());
    if ($schema === NULL) {
      return NULL;
    }
    /** @var \Drupal\aincient_pages\Controller\PageSpikeController $spike */
    $spike = $this->classResolver->getInstanceFromDefinition(PageSpikeController::class);
    return (string) $spike->renderSchema($schema)->getContent();
  }

  /**
   * Pull every non-empty `<a href>` out of an HTML document.
   *
   * @return list<string>
   */
  private function extractHrefs(string $html): array {
    if (trim($html) === '') {
      return [];
    }
    $dom = new \DOMDocument();
    $previous = libxml_use_internal_errors(TRUE);
    // The encoding hint keeps DOMDocument from mangling UTF-8 (it assumes
    // Latin-1 otherwise).
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $hrefs = [];
    foreach ($dom->getElementsByTagName('a') as $anchor) {
      $href = trim((string) $anchor->getAttribute('href'));
      if ($href !== '') {
        $hrefs[] = $href;
      }
    }
    return $hrefs;
  }

  /**
   * Classify an href: `internal` (resolve it), `external` (count, don't fetch),
   * or `skip` (fragment, mailto:, tel:, javascript:, data:, protocol-relative).
   */
  private function classify(string $href): string {
    if ($href === '' || $href[0] === '#') {
      return 'skip';
    }
    if (preg_match('#^(mailto:|tel:|javascript:|data:)#i', $href)) {
      return 'skip';
    }
    // Protocol-relative (//host/…): host is ambiguous → treat as external.
    if (str_starts_with($href, '//')) {
      return 'external';
    }
    if (preg_match('#^https?://#i', $href)) {
      $host = parse_url($href, PHP_URL_HOST);
      $self = $this->requestStack->getCurrentRequest()?->getHost() ?? '';
      return ($host !== NULL && $self !== '' && strcasecmp((string) $host, $self) === 0)
        ? 'internal'
        : 'external';
    }
    // A root-relative or relative path → internal.
    return 'internal';
  }

  /**
   * Reduce an internal href to a router-resolvable path (strip host/query/
   * fragment and the install base path), or NULL when there's no path.
   */
  private function internalPath(string $href): ?string {
    $path = parse_url($href, PHP_URL_PATH);
    if ($path === NULL || $path === FALSE || $path === '') {
      return NULL;
    }
    // Drop the install base path (subdir installs) so the validator sees an
    // internal path; PathValidator ltrims the leading slash itself.
    $base = rtrim(base_path(), '/');
    if ($base !== '' && str_starts_with($path, $base . '/')) {
      $path = substr($path, strlen($base));
    }
    return $path;
  }

}
