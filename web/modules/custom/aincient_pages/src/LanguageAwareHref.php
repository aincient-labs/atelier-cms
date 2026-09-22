<?php

declare(strict_types=1);

namespace Drupal\aincient_pages;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Url;
use Drupal\path_alias\AliasManagerInterface;

/**
 * Renders a RAW page-schema href in a given content language.
 *
 * A page-schema link prop may hold a reference token (resolved by
 * {@see EntityEmbedResolver::linkUrl}) or a raw string the author typed —
 * `https://example.com`, `mailto:…`, `#pricing`, or a path on this site like
 * `/book-an-appointment`. Only the last shape is a problem: it was stored once,
 * in ONE language, and shipped verbatim into every translation, so a German
 * reader's CTA pointed at the English page.
 *
 * So an internal, root-relative path is re-rendered through Drupal's own URL
 * generation in the RENDER language: the alias is resolved back to its system
 * path in the SOURCE language (the language it was written in), then reassembled
 * with `['language' => …]`, which runs both outbound processors — path_alias
 * (the translation's own alias, see PageStore::syncTranslationAlias) and
 * language negotiation (the `/de` prefix). Everything else is left byte-identical:
 *
 *   - external / scheme'd (`https:`, `mailto:`, `tel:`) and protocol-relative
 *     (`//cdn…`) hrefs — not ours to rewrite;
 *   - pure fragments (`#top`) — same-document, no path to prefix;
 *   - anything already carrying a configured language prefix — never double-prefix;
 *   - static assets (`/sites/default/files/…`, `/themes/…`, any docroot file) —
 *     the web server serves them at their real path only, a prefix 404s;
 *   - EVERY href on a monolingual site — {@see rewrite()} returns early, so a
 *     site with one language renders exactly the markup it always did.
 */
final class LanguageAwareHref {

  public function __construct(
    private readonly LanguageManagerInterface $languageManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ?AliasManagerInterface $aliasManager = NULL,
  ) {}

  /**
   * Re-render $href in $langcode, or return it unchanged.
   *
   * @param string $href
   *   The raw href as authored.
   * @param string|null $langcode
   *   The render language, or NULL for "no language context" (no rewrite).
   */
  public function rewrite(string $href, ?string $langcode): string {
    if ($langcode === NULL || $langcode === '' || trim($href) === '') {
      return $href;
    }
    // Monolingual (or no language module): nothing to prefix, nothing to
    // translate — and the output must stay byte-identical.
    if (!$this->languageManager->isMultilingual()) {
      return $href;
    }
    $language = $this->languageManager->getLanguage($langcode);
    if ($language === NULL) {
      return $href;
    }
    $href = trim($href);
    // Root-relative internal only: '/x' but not '//cdn.example.com/x', and never
    // a fragment or a scheme'd URI (which cannot start with '/').
    if (!str_starts_with($href, '/') || str_starts_with($href, '//')) {
      return $href;
    }
    // Split off ?query / #fragment — they ride along untouched.
    $cut = strcspn($href, '?#');
    $path = substr($href, 0, $cut);
    $suffix = substr($href, $cut);
    if ($path === '' || $path === '/') {
      // The front page: still worth prefixing, but there is no alias to resolve.
      $path = '/';
    }
    if ($this->isPrefixed($path)) {
      return $href;
    }
    // A static asset (`/sites/default/files/brochure.pdf`, a theme SVG) is served
    // by the web server at its REAL path only; `/de/sites/…` falls through to
    // index.php and 404s. Same test the stock .htaccess makes: a docroot file.
    if ($this->isDocrootAsset($path)) {
      return $href;
    }
    $target = $this->systemPath($path);
    try {
      $url = Url::fromUserInput($target, ['language' => $language]);
      // A ROUTED target (the common case: an alias resolved to /node/N, or a
      // real route) generates through the full outbound stack — the prefix AND
      // the translation's own alias. An UNROUTED one (a file, an unknown path)
      // never path-processes, so it only earns the negotiated prefix, applied
      // here by hand rather than by switching `path_processing` on (which hands
      // the alias processor a slashless path and fatals).
      $rendered = $url->isRouted() ? $url->toString() : $this->prefixed($target, $langcode);
    }
    catch (\Exception) {
      // An unroutable / malformed path — leave the author's string alone.
      return $href;
    }
    return $rendered . $suffix;
  }

  /**
   * $path under $langcode's configured URL prefix (unchanged when there is none
   * — the default language is normally served unprefixed).
   */
  private function prefixed(string $path, string $langcode): string {
    $prefixes = $this->configFactory->get('language.negotiation')->get('url.prefixes');
    $prefix = is_array($prefixes) ? ($prefixes[$langcode] ?? '') : '';
    return is_string($prefix) && $prefix !== '' ? '/' . $prefix . $path : $path;
  }

  /**
   * TRUE when $path names a static file the web server serves directly.
   *
   * Either it sits under one of Drupal's asset trees (public files, core,
   * modules, themes, libraries, profiles), or it exists as a regular file in the
   * docroot — the same `!-f` condition the stock .htaccess uses before handing a
   * request to index.php. Such a path has no language: prefixing it breaks it.
   */
  private function isDocrootAsset(string $path): bool {
    foreach (['/sites/', '/core/', '/modules/', '/themes/', '/libraries/', '/profiles/'] as $tree) {
      if (str_starts_with($path, $tree)) {
        return TRUE;
      }
    }
    if (str_contains($path, '..')) {
      return FALSE;
    }
    $root = defined('DRUPAL_ROOT') ? DRUPAL_ROOT : '';
    return $root !== '' && is_file($root . rawurldecode($path));
  }

  /**
   * TRUE when $path already begins with a configured language URL prefix.
   *
   * Re-prefixing would produce `/de/de/…`; an author who typed the prefixed form
   * (or a value rewritten once already) is taken at their word.
   */
  private function isPrefixed(string $path): bool {
    $prefixes = $this->configFactory->get('language.negotiation')->get('url.prefixes');
    foreach (is_array($prefixes) ? $prefixes : [] as $prefix) {
      if (!is_string($prefix) || $prefix === '') {
        continue;
      }
      if ($path === '/' . $prefix || str_starts_with($path, '/' . $prefix . '/')) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * The system path behind an alias WRITTEN IN THE SOURCE LANGUAGE, else $path.
   *
   * The href was authored against the default-language alias (`/book`), so the
   * lookup must be made in that language — asking in the RENDER language (the
   * alias manager's default) would miss and leave the English alias in place.
   * Falls back to the ambient lookup, then to the path itself (an unaliased
   * path, a console path: they just get the prefix).
   */
  private function systemPath(string $path): string {
    if ($this->aliasManager === NULL) {
      return $path;
    }
    $candidates = [
      $this->languageManager->getDefaultLanguage()->getId(),
      LanguageInterface::LANGCODE_NOT_SPECIFIED,
      NULL,
    ];
    foreach ($candidates as $lookupLang) {
      $resolved = $this->aliasManager->getPathByAlias($path, $lookupLang);
      if ($resolved !== $path) {
        return $resolved;
      }
    }
    return $path;
  }

}
