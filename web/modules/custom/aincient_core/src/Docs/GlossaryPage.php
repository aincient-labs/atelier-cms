<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Docs;

use Symfony\Component\Yaml\Yaml;

/**
 * Renders `aincient_core/glossary.yml` as the public docs Glossary page.
 *
 * Pure PHP (no Drupal bootstrap): `bin/sync-docs` runs it on the host through
 * `scripts/glossary-page.php`, and GlossaryTest compares the bundled page with
 * a fresh render, so the page can never drift from the glossary (0526).
 */
final class GlossaryPage {

  public const FILE = 'glossary.mdx';

  /**
   * The glossary file next to the module.
   */
  public static function source(): string {
    return dirname(__DIR__, 2) . '/glossary.yml';
  }

  /**
   * @return array{terms: array<string, array<string, mixed>>, developer_pages: list<string>}
   */
  public static function load(?string $path = NULL): array {
    $data = Yaml::parseFile($path ?? self::source());
    return [
      'terms' => (array) ($data['terms'] ?? []),
      'developer_pages' => array_values((array) ($data['developer_pages'] ?? [])),
    ];
  }

  /**
   * The page as MDX. Developer-only terms are left out.
   */
  public static function render(?string $path = NULL): string {
    $terms = array_filter(
      self::load($path)['terms'],
      static fn(array $t): bool => array_diff((array) $t['contexts'], ['developer']) !== [],
    );
    uasort($terms, static fn(array $a, array $b): int => strcasecmp(self::heading($a), self::heading($b)));

    $out = [
      '---',
      'title: "Glossary"',
      'description: "The names Atelier uses for its rooms, menus and apps, what each one means, and what it used to be called"',
      '---',
      '',
      '{/* GENERATED from aincient_core/glossary.yml in the cms repo by bin/sync-docs. Edit the glossary, not this page. */}',
      '',
      'Every name you see in Atelier, what it means, and where to read more. Older names are listed so',
      'you can match what you remember to what the console shows now.',
      '',
    ];
    foreach ($terms as $term) {
      $out[] = '## ' . self::heading($term);
      $out[] = '';
      $out[] = trim((string) $term['means']);
      $lines = [];
      if (!empty($term['short'])) {
        $lines[] = sprintf('Shown as **%s** where space is short.', $term['short']);
      }
      if (!empty($term['aliases'])) {
        $lines[] = 'Also called ' . self::list((array) $term['aliases']) . '.';
      }
      if (!empty($term['retired'])) {
        $lines[] = 'Formerly called ' . self::list((array) $term['retired']) . '.';
      }
      if (!empty($term['docs']) && $term['docs'] !== '/docs/glossary') {
        $lines[] = sprintf('More: [%s](%s).', self::heading($term), substr((string) $term['docs'], strlen('/docs')));
      }
      if ($lines !== []) {
        $out[] = '';
        $out[] = implode(' ', $lines);
      }
      $out[] = '';
    }
    return rtrim(implode("\n", $out)) . "\n";
  }

  /**
   * The heading: the prose form when the bare name is ambiguous.
   */
  private static function heading(array $term): string {
    $say = (string) ($term['say'] ?? '');
    if ($say !== '') {
      $say = (string) preg_replace('/^the /', '', $say);
      return ucfirst($say);
    }
    return ucfirst((string) $term['name']);
  }

  /**
   * @param list<string> $names
   */
  private static function list(array $names): string {
    $names = array_map(static fn(string $n): string => "**$n**", $names);
    if (count($names) < 2) {
      return implode('', $names);
    }
    $last = array_pop($names);
    return implode(', ', $names) . ' or ' . $last;
  }

}
