<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_core\Unit;

use Drupal\aincient_core\Docs\GlossaryPage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The glossary is the one home for product names (DECISIONS 0526).
 *
 * Fails when a console room name, an assistant's label or a shipped prompt
 * drifts from `aincient_core/glossary.yml`. Rename in the glossary first; this
 * test then lists every place still using the old name.
 */
#[Group('aincient_core')]
final class GlossaryTest extends TestCase {

  private const KINDS = ['room', 'menu', 'assistant', 'app', 'place', 'concept'];

  private const CONTEXTS = ['console', 'docs', 'prompts', 'developer'];

  /**
   * @return array<string, array<string, mixed>>
   */
  private static function terms(): array {
    $data = Yaml::parseFile(self::modules() . '/custom/aincient_core/glossary.yml');
    return $data['terms'];
  }

  private static function modules(): string {
    return dirname(__DIR__, 5);
  }

  private static function root(): string {
    return dirname(__DIR__, 7);
  }

  /**
   * Shipped files, test modules and node_modules left out.
   *
   * @return list<string>
   */
  private static function shipped(string $pattern): array {
    $files = [];
    foreach (['custom', 'studio'] as $tree) {
      $dir = self::modules() . '/' . $tree;
      if (!is_dir($dir)) {
        continue;
      }
      $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
      foreach ($it as $file) {
        $path = $file->getPathname();
        if (str_contains($path, '/tests/') || str_contains($path, '/node_modules/') || !fnmatch($pattern, $file->getFilename())) {
          continue;
        }
        $files[] = $path;
      }
    }
    sort($files);
    return $files;
  }

  public function testEveryTermIsWellFormed(): void {
    foreach (self::terms() as $id => $term) {
      $this->assertNotSame('', trim((string) ($term['name'] ?? '')), "$id: name");
      $this->assertContains($term['kind'] ?? NULL, self::KINDS, "$id: kind");
      $this->assertNotSame('', trim((string) ($term['means'] ?? '')), "$id: means");
      $this->assertNotEmpty($term['contexts'] ?? [], "$id: contexts");
      foreach ($term['contexts'] as $context) {
        $this->assertContains($context, self::CONTEXTS, "$id: context $context");
      }
      if (isset($term['docs'])) {
        $slug = substr((string) $term['docs'], strlen('/docs/'));
        $this->assertFileExists(self::modules() . "/custom/aincient_core/docs/$slug.mdx", "$id: docs page {$term['docs']} is not in the bundle");
      }
    }
  }

  public function testConsoleRoomNamesMatch(): void {
    $owner = [];
    foreach (self::terms() as $id => $term) {
      foreach ((array) ($term['studio'] ?? []) as $studio) {
        $this->assertArrayNotHasKey($studio, $owner, "Studio $studio is claimed by two terms");
        $owner[$studio] = $id;
      }
    }
    $terms = self::terms();
    $seen = [];
    foreach (self::shipped('*.studios.yml') as $file) {
      foreach (Yaml::parseFile($file) ?? [] as $studio => $def) {
        $this->assertArrayHasKey($studio, $owner, "Studio $studio ($file) has no glossary term");
        $term = $terms[$owner[$studio]];
        $shown = (string) ($def['ui']['name'] ?? $def['label']);
        $this->assertSame($term['name'], $shown, "Studio $studio shows \"$shown\"; the glossary says \"{$term['name']}\"");
        if (isset($def['ui']['short_name'])) {
          $this->assertSame($term['short'] ?? NULL, $def['ui']['short_name'], "Studio $studio short name");
        }
        $seen[$studio] = TRUE;
      }
    }
    $this->assertSame([], array_keys(array_diff_key($owner, $seen)), 'Glossary names studios that no module declares');

    $nav = self::modules() . '/custom/aincient_chat/chat-ui/src/nav-model.ts';
    if (is_file($nav)) {
      preg_match_all('/kind: "group",\s*id: "[a-z_]+",\s*name: "([^"]+)"/', (string) file_get_contents($nav), $m);
      $this->assertSame([$terms['site']['name']], $m[1], 'The top-bar menu name');
    }
  }

  public function testAssistantLabelsMatch(): void {
    foreach (self::terms() as $id => $term) {
      foreach ((array) ($term['agent'] ?? []) as $workflow) {
        $name = "flowdrop_workflow.flowdrop_workflow.$workflow.yml";
        $copies = array_filter(self::shipped($name));
        $sync = self::root() . "/config/sync/$name";
        if (is_file($sync)) {
          $copies[] = $sync;
        }
        $this->assertNotEmpty($copies, "$id: workflow $workflow not found");
        foreach ($copies as $file) {
          $label = (string) (Yaml::parseFile($file)['label'] ?? '');
          $this->assertSame($term['name'], $label, "$workflow label in $file");
        }
      }
    }
  }

  public function testRetiredNamesStayOutOfShippedText(): void {
    $retired = [];
    foreach (self::terms() as $id => $term) {
      foreach ((array) ($term['retired'] ?? []) as $old) {
        $retired[$old] = $term['name'];
      }
    }
    // Prompts, studio help text, and the tool descriptions the models read.
    $tools = array_filter(self::shipped('*.php'), static fn(string $f): bool => str_contains($f, '/src/Plugin/AiCapability/'));
    $files = array_merge(self::shipped('flowdrop_workflow.*.yml'), self::shipped('*.studios.yml'), $tools);
    $sync = glob(self::root() . '/config/sync/flowdrop_workflow.*.yml') ?: [];
    $hits = [];
    foreach (array_merge($files, $sync) as $file) {
      // Comments are for developers; everything else reaches a model or a user.
      $text = (string) preg_replace('/^\s*(#|\*|\/\/|\/\*).*$/m', '', (string) file_get_contents($file));
      foreach ($retired as $old => $now) {
        if (preg_match('/(?<![\w-])' . str_replace(' ', '\s+', preg_quote($old, '/')) . '(?![\w-])/', $text)) {
          $hits[] = sprintf('%s: "%s" (now "%s")', str_replace(self::root() . '/', '', $file), $old, $now);
        }
      }
    }
    $this->assertSame([], $hits, 'Retired names in shipped prompts or studio text');
  }

  public function testTheDocsUseTheGlossary(): void {
    $docs = self::modules() . '/custom/aincient_core/docs';
    $glossary = GlossaryPage::load();
    $this->assertSame(GlossaryPage::render(), (string) @file_get_contents("$docs/" . GlossaryPage::FILE), 'The Glossary page is stale: run bin/sync-docs (it regenerates the page from glossary.yml) and commit the docs.');

    $retired = [];
    $devOnly = [];
    foreach ($glossary['terms'] as $term) {
      foreach ((array) ($term['retired'] ?? []) as $old) {
        $retired[] = $old;
      }
      if ((array) $term['contexts'] === ['developer']) {
        $devOnly[] = (string) $term['name'];
      }
    }
    $hits = [];
    foreach (glob("$docs/*.mdx") ?: [] as $file) {
      $page = basename($file, '.mdx');
      if ($page . '.mdx' === GlossaryPage::FILE) {
        continue;
      }
      $text = (string) preg_replace('/\{\/\*.*?\*\/\}/s', '', (string) file_get_contents($file));
      foreach (preg_split('/(?<=[.!?])\s+|\n{2,}/', $text) as $sentence) {
        $sentence = (string) preg_replace('/\s+/', ' ', $sentence);
        foreach ($retired as $old) {
          if (preg_match('/(?<![\w-])' . str_replace(' ', '\s+', preg_quote($old, '/')) . '(?![\w-])/', $sentence) && !preg_match('/\bformerly\b/i', $sentence)) {
            $hits[] = "$page: \"$old\"";
          }
        }
        if (!in_array($page, $glossary['developer_pages'], TRUE)) {
          foreach ($devOnly as $word) {
            if (preg_match('/\b' . preg_quote($word, '/') . 's?\b/i', $sentence)) {
              $hits[] = "$page: \"$word\" (developer pages only)";
            }
          }
        }
      }
    }
    $this->assertSame([], array_values(array_unique($hits)), 'Names the glossary retires or keeps for developers, in the user docs');
  }

}
