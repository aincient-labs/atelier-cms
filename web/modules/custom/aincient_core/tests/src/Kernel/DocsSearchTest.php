<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_core\Kernel;

use Drupal\aincient_core\Docs\DocsCorpus;
use Drupal\aincient_core\Search\SearchIndex;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The assistant's docs search: parse the bundled docs, index, rank, read.
 *
 * Runs against the REAL bundle (aincient_core/docs, synced from the website), so
 * a docs rewrite that stops answering the questions users actually ask fails
 * here. On PostgreSQL (the gate) this exercises native full-text search; on
 * SQLite the PHP fallback.
 */
#[Group('aincient_core')]
#[RunTestsInSeparateProcesses]
final class DocsSearchTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'key', 'aincient_core'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installSchema('aincient_core', ['aincient_search_chunk']);
  }

  /**
   * The bundle parses into sections with public links and no MDX left over.
   */
  public function testCorpusParsesSectionsWithLinksAndNoMarkup(): void {
    $docs = new DocsCorpus();
    $this->assertContains('quickstart', $docs->slugs());
    $this->assertContains('component-packs', $docs->slugs());

    $chunks = $docs->chunks();
    $this->assertGreaterThan(100, count($chunks));
    foreach ($chunks as $chunk) {
      $this->assertStringStartsWith(DocsCorpus::BASE_URL, $chunk['url']);
      $this->assertDoesNotMatchRegularExpression('#</?(Note|Tip|Steps|Step|Card|CardGroup|Frame)\b#', $chunk['body'], $chunk['url']);
      $this->assertStringNotContainsString('](/', $chunk['body'], 'Docs-internal links are made absolute: ' . $chunk['url']);
      $this->assertStringNotContainsString('{/*', $chunk['body'], 'MDX comments are not docs: ' . $chunk['url']);
    }

    $carousel = array_values(array_filter($chunks, static fn(array $c): bool => $c['heading'] === 'Show artwork one image at a time'));
    $this->assertCount(1, $carousel);
    $this->assertSame(DocsCorpus::BASE_URL . '/components#show-artwork-one-image-at-a-time', $carousel[0]['url']);

    $page = $docs->page('https://aincient-labs.com/docs/quickstart#upgrade');
    $this->assertNotNull($page);
    $this->assertSame('quickstart', $page['slug']);
    $this->assertNotSame('', $page['title']);
    $this->assertNull($docs->page('no-such-page'));
    $this->assertSame('index', $docs->page('')['slug']);
  }

  /**
   * Questions users actually asked land on the page that answers them.
   */
  public function testRankingAnswersRealQuestions(): void {
    $index = $this->index();
    $cases = [
      'can I create my own component' => ['component-packs', 'bring-your-own-components', 'components', 'limits'],
      'image slider carousel lightbox' => ['components'],
      'how do I update Atelier' => ['quickstart', 'system', 'troubleshooting'],
      'docker compose version terminal' => ['quickstart'],
    ];
    foreach ($cases as $question => $pages) {
      $hits = $index->searchDocs($question);
      $this->assertNotEmpty($hits, $question);
      $slugs = array_map(static fn(array $h): string => (string) preg_replace('~^.*/docs/?([^/#]*).*$~', '$1', $h['url']), array_slice($hits, 0, 3));
      $this->assertNotEmpty(array_intersect($pages, $slugs), sprintf('"%s" → top 3 were %s', $question, implode(', ', $slugs)));
    }
    $this->assertSame([], $index->searchDocs('?!'));
  }

  /**
   * The index follows the bundle: unchanged → no work; changed → re-indexed.
   */
  public function testIndexRebuildsOnlyWhenTheBundleChanges(): void {
    $index = $this->index();
    $this->assertTrue($index->ensureDocsIndexed());
    $rows = (int) $this->container->get('database')->select(SearchIndex::TABLE)->countQuery()->execute()->fetchField();
    $this->assertSame(count((new DocsCorpus())->chunks()), $rows);
    $this->assertFalse($index->ensureDocsIndexed());

    $this->container->get('state')->set(SearchIndex::DOCS_HASH_STATE, 'stale');
    $this->assertTrue($index->ensureDocsIndexed());
    $rows_after = (int) $this->container->get('database')->select(SearchIndex::TABLE)->countQuery()->execute()->fetchField();
    $this->assertSame($rows, $rows_after, 'A re-index replaces rows, never duplicates them.');
  }

  /**
   * The two tools answer with links, and only for console users.
   */
  public function testToolsAnswerWithLinksForConsoleUsersOnly(): void {
    $manager = $this->container->get('plugin.manager.aincient.capabilities');

    $this->setUpCurrentUser([], []);
    $search = $manager->createInstance('aincient_core:search_docs');
    $search->setContextValue('query', 'component packs');
    $search->execute();
    $this->assertStringStartsWith('Error:', $search->getReadableOutput());

    $this->setUpCurrentUser([], ['use aincient operator console']);
    $search = $manager->createInstance('aincient_core:search_docs');
    $search->setContextValue('query', 'component packs');
    $search->execute();
    $out = $search->getReadableOutput();
    $this->assertStringContainsString('https://aincient-labs.com/docs/component-packs', $out);
    $this->assertStringContainsString('1. ', $out);

    $search = $manager->createInstance('aincient_core:search_docs');
    $search->setContextValue('query', 'zzqx');
    $search->execute();
    $this->assertStringContainsString('No docs section matches', $search->getReadableOutput());

    $read = $manager->createInstance('aincient_core:read_docs_page');
    $read->setContextValue('page', 'quickstart');
    $read->execute();
    $out = $read->getReadableOutput();
    $this->assertStringContainsString('https://aincient-labs.com/docs/quickstart', $out);
    $this->assertStringContainsString('docker compose version', $out);

    $read = $manager->createInstance('aincient_core:read_docs_page');
    $read->setContextValue('page', 'nope');
    $read->execute();
    $this->assertStringContainsString('component-packs', $read->getReadableOutput(), 'An unknown page lists the real ones.');
  }

  private function index(): SearchIndex {
    return $this->container->get('aincient_core.search_index');
  }

}
