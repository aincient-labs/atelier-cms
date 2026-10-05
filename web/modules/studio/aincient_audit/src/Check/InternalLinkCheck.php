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
    $schema = $this->store->resolve($node);
    $html = $this->renderHtml($node, $schema);
    if ($html === NULL) {
      $findings[] = $this->finding('links.render', self::WARN, 'No page content to scan', 'This page has no stored schema, so there are no links to check yet.', 'Links', 'content');
      return $findings;
    }

    $hrefs = $this->extractHrefs($html);
    if ($hrefs === []) {
      $findings[] = $this->finding('links.none', self::PASS, 'No links to check', 'This page has no links in its content.', 'Links', 'content');
      return $findings;
    }

    // Every `id` attribute rendered on THIS page — section anchors (DECISIONS
    // 0413, PageSpikeController::anchorSection) and markdown heading slugs
    // (MarkdownRenderer) alike — is what a same-page `#x` fragment resolves
    // against.
    $ids = $this->extractIds($html);

    // One finding per broken PATH (or dangling fragment), however often the
    // page links to it: every href that reduced to the key, and how many
    // links it has (0453 S4 — one fix for every copy).
    $internalOk = 0;
    $external = 0;
    /** @var array<string, array{hrefs: array<string, true>, count: int}> $broken */
    $broken = [];
    /** @var array<string, array{hrefs: array<string, true>, count: int}> $fragments */
    $fragments = [];
    foreach ($hrefs as $href) {
      $kind = $this->classify($href);
      if ($kind === 'fragment') {
        $fragment = substr($href, 1);
        if (isset($ids[$fragment])) {
          $internalOk++;
        }
        else {
          $fragments[$fragment]['hrefs'][$href] = TRUE;
          $fragments[$fragment]['count'] = ($fragments[$fragment]['count'] ?? 0) + 1;
        }
        continue;
      }
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
      // Note: a cross-page `/other#x` fragment is left unchecked here — we'd
      // need the OTHER page's rendered HTML to validate its ids, which this
      // check (single-page, no-HTTP) doesn't have.
      if (isset($broken[$path])) {
        $broken[$path]['hrefs'][$href] = TRUE;
        $broken[$path]['count']++;
      }
      elseif ($this->pathValidator->getUrlIfValidWithoutAccessCheck($path)) {
        $internalOk++;
      }
      else {
        $broken[$path] = ['hrefs' => [$href => TRUE], 'count' => 1];
      }
    }

    foreach ($fragments as $fragment => $seen) {
      $href = (string) array_key_first($seen['hrefs']);
      $findings[] = $this->finding('links.fragment:' . $fragment, self::FAIL, 'Dangling in-page link', sprintf('“#%s” points at no section or heading on this page.', $fragment) . $this->timesSuffix($seen['count']), 'Links', 'content', $this->linkRemediation($href, $seen, $schema));
    }
    foreach ($broken as $path => $seen) {
      $href = (string) array_key_first($seen['hrefs']);
      // `content` dimension: the broken href lives in page sections. The
      // repair agent rewrites it at every `target.locations[]` entry in one
      // edit (no manual inline editor for links — AI-only).
      $findings[] = $this->finding('links.broken:' . $path, self::FAIL, 'Broken internal link', sprintf('“%s” does not resolve to a page on this site.', $href) . $this->timesSuffix($seen['count']), 'Links', 'content', $this->linkRemediation($href, $seen, $schema));
    }

    if ($broken === [] && $fragments === []) {
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
   * markup matches a live page exactly — in the node's OWN language, so a
   * translation's overlay, heading slugs and language-prefixed hrefs are what
   * get checked (a German page is graded on its German links, not the source's).
   * The schema comes from THIS revision ({@see PageStore::resolve}), not the
   * published default: a draft audit must grade the draft's links (0450).
   *
   * @param array<string, mixed> $schema
   *   The revision's resolved schema ({@see PageStore::resolve}).
   */
  private function renderHtml(NodeInterface $node, array $schema): ?string {
    $langcode = $node->language()->getId();
    /** @var \Drupal\aincient_pages\Controller\PageSpikeController $spike */
    $spike = $this->classResolver->getInstanceFromDefinition(PageSpikeController::class);
    return (string) $spike->renderSchema($schema, $langcode)->getContent();
  }

  /**
   * " Linked N times." for a target the page links to more than once.
   */
  private function timesSuffix(int $count): string {
    return $count > 1 ? sprintf(' Linked %d times.', $count) : '';
  }

  /**
   * The remediation for a broken link or dangling fragment: the href, every
   * place in the schema that writes it, and how many links the page has to it.
   *
   * @param array{hrefs: array<string, true>, count: int} $seen
   * @param array<string, mixed> $schema
   *
   * @return array<string, mixed>
   */
  private function linkRemediation(string $href, array $seen, array $schema): array {
    return [
      'action' => 'edit_prop',
      'target' => [
        'href' => $href,
        'locations' => $this->locate($schema, array_keys($seen['hrefs'])),
        'occurrences' => $seen['count'],
      ],
      'aiFixable' => TRUE,
    ];
  }

  /**
   * Where the schema writes any of `$hrefs`: one `{section, prop, href}` per
   * section prop that carries it — as the whole value, a Markdown link target
   * or an HTML `href` — walking nested props (`prop` is a dotted path, e.g.
   * `items.2.url`). A rendered href the schema doesn't spell the same way (a
   * language prefix the renderer added) has no location; the finding still
   * stands on the rendered page.
   *
   * @param array<string, mixed> $schema
   * @param list<string> $hrefs
   *
   * @return list<array{section: string, prop: string, href: string}>
   */
  private function locate(array $schema, array $hrefs): array {
    $out = [];
    foreach ($schema['sections'] ?? [] as $section) {
      $id = (string) ($section['id'] ?? '');
      if ($id === '' || !is_array($section['props'] ?? NULL)) {
        continue;
      }
      $this->walkProps($section['props'], '', $id, $hrefs, $out);
    }
    return $out;
  }

  /**
   * Recursive step of {@see self::locate}.
   *
   * @param array<array-key, mixed> $props
   * @param list<string> $hrefs
   * @param list<array{section: string, prop: string, href: string}> $out
   */
  private function walkProps(array $props, string $prefix, string $section, array $hrefs, array &$out): void {
    foreach ($props as $key => $value) {
      $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
      if (is_array($value)) {
        $this->walkProps($value, $path, $section, $hrefs, $out);
        continue;
      }
      if (!is_string($value) || $value === '') {
        continue;
      }
      foreach ($hrefs as $href) {
        if ($value === $href || str_contains($value, '](' . $href) || str_contains($value, 'href="' . $href . '"')) {
          $out[] = ['section' => $section, 'prop' => $path, 'href' => $href];
          break;
        }
      }
    }
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
      // The language switcher (`hreflang` links) points at "this page in
      // another language", built from the CURRENT REQUEST's route — the audit
      // runs outside the page's own route (the report endpoint, a chat turn,
      // drush), so those hrefs name that request (`/de/atelier/chat`), not the
      // page. They aren't page content and no page edit could fix them.
      if ($anchor->hasAttribute('hreflang')) {
        continue;
      }
      $href = trim((string) $anchor->getAttribute('href'));
      if ($href !== '') {
        $hrefs[] = $href;
      }
    }
    return $hrefs;
  }

  /**
   * Every `id` attribute present anywhere in the rendered HTML, as a lookup
   * set (`//*[@id]`) — section-anchor wrappers and markdown heading slugs
   * alike.
   *
   * @return array<string, true>
   */
  private function extractIds(string $html): array {
    if (trim($html) === '') {
      return [];
    }
    $dom = new \DOMDocument();
    $previous = libxml_use_internal_errors(TRUE);
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $ids = [];
    $xpath = new \DOMXPath($dom);
    foreach ($xpath->query('//*[@id]') as $node) {
      $id = trim($node->getAttribute('id'));
      if ($id !== '') {
        $ids[$id] = TRUE;
      }
    }
    return $ids;
  }

  /**
   * Classify an href: `internal` (resolve it), `external` (count, don't fetch),
   * `fragment` (a non-empty same-page `#x`, resolved against the page's own
   * rendered ids), or `skip` (a bare `#`, mailto:, tel:, javascript:, data:).
   */
  private function classify(string $href): string {
    if ($href === '') {
      return 'skip';
    }
    if ($href[0] === '#') {
      return $href === '#' ? 'skip' : 'fragment';
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
