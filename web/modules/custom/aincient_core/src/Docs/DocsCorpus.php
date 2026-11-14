<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Docs;

/**
 * The Atelier docs bundled with this release, read as pages and sections.
 *
 * The bundle (`aincient_core/docs/*.mdx`) is a copy of the public docs made by
 * the umbrella's `bin/sync-docs`, so the assistant answers from the docs of the
 * version that is INSTALLED, offline, with no outbound call. This class only
 * parses: MDX → plain text, one chunk per `##` section. Indexing and ranking
 * live in {@see \Drupal\aincient_core\Search\SearchIndex}.
 */
final class DocsCorpus {

  /**
   * Public base URL of the docs; page links point here.
   */
  public const BASE_URL = 'https://aincient-labs.com/docs';

  /**
   * @param string $directory
   *   The bundle directory (defaults to the module's `docs/`).
   */
  public function __construct(private readonly string $directory = __DIR__ . '/../../docs') {}

  /**
   * A hash of the bundle's contents; changes whenever a page does.
   */
  public function hash(): string {
    $context = hash_init('sha256');
    foreach ($this->files() as $slug => $path) {
      hash_update($context, $slug . "\0" . (string) file_get_contents($path) . "\0");
    }
    return hash_final($context);
  }

  /**
   * Every page slug in the bundle, sorted.
   *
   * @return list<string>
   */
  public function slugs(): array {
    return array_keys($this->files());
  }

  /**
   * One page, parsed; NULL when the bundle has no such page.
   *
   * @return array{slug: string, title: string, description: string, url: string, text: string}|null
   */
  public function page(string $slug): ?array {
    $slug = trim(strtolower($slug));
    // Accept a full docs URL or path as well as a bare slug.
    $slug = (string) preg_replace('#^(https?://[^/]+)?/?(docs/?)?#', '', $slug);
    $slug = (string) preg_replace('/[#?].*$/', '', $slug);
    $slug = trim($slug, '/');
    if ($slug === '') {
      $slug = 'index';
    }
    $files = $this->files();
    if (!isset($files[$slug])) {
      return NULL;
    }
    [$meta, $body] = $this->split((string) file_get_contents($files[$slug]));
    return [
      'slug' => $slug,
      'title' => $this->fullTitle($slug, $meta['title'] ?? $slug, $this->sections()),
      'description' => $meta['description'] ?? '',
      'url' => self::url($slug),
      'text' => $this->plain($body),
    ];
  }

  /**
   * Every section of every page — the rows the search index stores.
   *
   * The text before the first `##` is the page's intro section (heading '').
   *
   * @return list<array{slug: string, title: string, heading: string, anchor: string, url: string, body: string}>
   */
  public function chunks(): array {
    $chunks = [];
    $sidebar = $this->sections();
    foreach ($this->files() as $slug => $path) {
      [$meta, $body] = $this->split((string) file_get_contents($path));
      $title = $this->fullTitle($slug, $meta['title'] ?? $slug, $sidebar);
      // Split at `##` AND `###`: a long section cut to fit the tool result
      // loses its later subsections, and a model missing the one line that
      // answers will fill the gap itself. A `###` chunk's heading carries its
      // parent ("Upgrade › Which builds you get") so it still reads in context.
      $sections = preg_split('/^(?=###? )/m', $body) ?: [];
      $parent = '';
      foreach ($sections as $section) {
        $heading = '';
        $anchorSource = '';
        if (preg_match('/^(###?) /', $section, $level)) {
          [$line, $section] = array_pad(explode("\n", $section, 2), 2, '');
          $anchorSource = trim(substr($line, strlen($level[1]) + 1));
          if ($level[1] === '##') {
            $parent = $anchorSource;
            $heading = $anchorSource;
          }
          else {
            $heading = $parent === '' ? $anchorSource : $parent . ' › ' . $anchorSource;
          }
        }
        $text = $this->plain($section);
        if ($heading === '') {
          // The intro carries the page description: it is the best summary of
          // what the page is for, and it is not repeated in the body.
          $text = trim(($meta['description'] ?? '') . "\n\n" . $text);
        }
        if ($text === '' && $heading === '') {
          continue;
        }
        $anchor = $anchorSource === '' ? '' : self::anchor($anchorSource);
        $chunks[] = [
          'slug' => $slug,
          'title' => $title,
          'heading' => $this->plain($heading),
          'anchor' => $anchor,
          'url' => self::url($slug) . ($anchor === '' ? '' : '#' . $anchor),
          'body' => $text,
        ];
      }
    }
    return $chunks;
  }

  /**
   * The public URL of a page.
   */
  public static function url(string $slug): string {
    return $slug === 'index' ? self::BASE_URL : self::BASE_URL . '/' . $slug;
  }

  /**
   * The heading anchor the docs site generates (github-slugger rules).
   */
  public static function anchor(string $heading): string {
    $text = mb_strtolower(trim((string) preg_replace('/`([^`]*)`|\*\*?([^*]+)\*\*?|\[([^\]]+)\]\([^)]*\)/u', '$1$2$3', $heading)));
    $text = (string) preg_replace('/[^\p{L}\p{N}\s_-]/u', '', $text);
    return (string) preg_replace('/\s/u', '-', $text);
  }

  /**
   * Page slug → its sidebar section ("---Identity---" in meta.json).
   *
   * @return array<string, string>
   */
  private function sections(): array {
    $meta = json_decode((string) @file_get_contents($this->directory . '/meta.json'), TRUE);
    $map = [];
    $section = '';
    foreach ((array) ($meta['pages'] ?? []) as $entry) {
      if (preg_match('/^---(.+)---$/', (string) $entry, $m)) {
        $section = trim($m[1]);
      }
      elseif ($section !== '') {
        $map[(string) $entry] = $section;
      }
    }
    return $map;
  }

  /**
   * A page title as a reader of the sidebar sees it: "Identity › Overview".
   *
   * Some pages are named only relative to their section ("Overview" under
   * Identity); without the section a search for colours and fonts gets no
   * title signal from the Identity page at all.
   */
  private function fullTitle(string $slug, string $title, array $sections): string {
    $section = $sections[$slug] ?? '';
    return $section === '' || stripos($title, $section) !== FALSE ? $title : $section . ' › ' . $title;
  }

  /**
   * The bundle's pages, slug → path, sorted by slug.
   *
   * @return array<string, string>
   */
  private function files(): array {
    $files = [];
    foreach (glob($this->directory . '/*.mdx') ?: [] as $path) {
      $files[basename($path, '.mdx')] = $path;
    }
    ksort($files);
    return $files;
  }

  /**
   * Splits YAML frontmatter (title/description only) from the body.
   *
   * @return array{0: array<string, string>, 1: string}
   */
  private function split(string $source): array {
    $meta = [];
    if (preg_match('/\A---\n(.*?)\n---\n?/s', $source, $m)) {
      foreach (explode("\n", $m[1]) as $line) {
        if (preg_match('/^(title|description):\s*(.*)$/', $line, $kv)) {
          $value = trim($kv[2]);
          if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
            $value = stripcslashes(substr($value, 1, -1));
          }
          $meta[$kv[1]] = $value;
        }
      }
      $source = substr($source, strlen($m[0]));
    }
    return [$meta, $source];
  }

  /**
   * MDX → readable plain text: no imports or JSX tags; links made absolute.
   */
  private function plain(string $mdx): string {
    $text = (string) preg_replace('/^(import|export) .*$/m', '', $mdx);
    // MDX comments are notes to editors ("When filmed, restore the embed"),
    // not docs: the reader of the site never sees them, so neither does the model.
    $text = (string) preg_replace('#\{/\*.*?\*/\}#s', '', $text);
    // A component's title (Step, Card, Tab) is content: keep it as a line.
    $text = (string) preg_replace('/<[A-Z][A-Za-z]*\b[^>]*?\btitle="([^"]*)"[^>]*>/', "$1:", $text);
    // Every other JSX/HTML tag, opening, closing or self-closing.
    $text = (string) preg_replace('#</?[A-Za-z][A-Za-z0-9]*\b[^>]*?/?>#', '', $text);
    // Docs-internal links (`/components`, `/quickstart#upgrade`) → public URLs.
    $text = (string) preg_replace_callback(
      '/\]\((\/[^)\s]*)\)/',
      static fn(array $m): string => '](' . self::BASE_URL . $m[1] . ')',
      $text,
    );
    $text = (string) preg_replace("/[ \t]+\n/", "\n", $text);
    $text = (string) preg_replace("/\n{3,}/", "\n\n", $text);
    return trim($text);
  }

}
