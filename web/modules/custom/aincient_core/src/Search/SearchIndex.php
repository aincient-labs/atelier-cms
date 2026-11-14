<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Search;

use Drupal\aincient_core\Docs\DocsCorpus;
use Drupal\Core\Database\Connection;
use Drupal\Core\State\StateInterface;

/**
 * Keyword search over text chunks, one table for every source.
 *
 * Rows live in `aincient_search_chunk`, tagged by `source`. Today the only
 * source is `docs` (the bundled Atelier docs, {@see DocsCorpus}); site content
 * joins later as its own sources, opened up one at a time (plans/docs-search.md
 * D7). On PostgreSQL the ranking is native full-text search — an expression GIN
 * index over title (weight A), heading (B) and body (D) with the `english`
 * configuration. Other drivers (the SQLite `--fast` test runs) get a plain term
 * match in PHP, good enough for a few hundred rows. No embeddings: this works
 * on every install, including one that only has a Claude key.
 *
 * The docs are indexed lazily: a search compares the bundle's hash with the one
 * stored at the last index and re-indexes on a mismatch, so an upgrade that
 * ships new docs needs no update hook.
 */
final class SearchIndex {

  public const TABLE = 'aincient_search_chunk';

  /**
   * State key holding the docs bundle hash the table was built from.
   */
  public const DOCS_HASH_STATE = 'aincient_core.search.docs_hash';

  /**
   * The document vector; the GIN index is built over EXACTLY this expression.
   */
  private const PG_VECTOR = "(setweight(to_tsvector('english', coalesce(title, '')), 'A') || setweight(to_tsvector('english', coalesce(heading, '')), 'B') || setweight(to_tsvector('english', coalesce(body, '')), 'D'))";

  /**
   * Words the PHP fallback ignores (PostgreSQL drops its own stop words).
   */
  private const STOP_WORDS = ['a', 'an', 'and', 'are', 'can', 'do', 'does', 'for', 'how', 'i', 'in', 'is', 'it', 'me', 'my', 'of', 'on', 'or', 'the', 'to', 'what', 'where', 'with', 'you', 'your'];

  /**
   * One-way query expansions (word → also search for).
   */
  private const SYNONYMS = [
    'update' => 'upgrade',
    'upgrade' => 'update',
    'updating' => 'upgrade',
    'slider' => 'carousel',
  ];

  public function __construct(
    private readonly Connection $database,
    private readonly StateInterface $state,
    private readonly DocsCorpus $docs,
  ) {}

  /**
   * Search the bundled docs.
   *
   * @return list<array{title: string, heading: string, url: string, body: string, score: float}>
   */
  public function searchDocs(string $query, int $limit = 5): array {
    $this->ensureDocsIndexed();
    return $this->search('docs', $query, $limit);
  }

  /**
   * The docs hits as the text the assistant (and the docs check) reads.
   *
   * Shared by the search_docs tool and the dispatcher's own search for a reply
   * the agent gave without searching, so both evidence paths look the same.
   *
   * @param list<array{title: string, heading: string, url: string, body: string, score: float}> $hits
   */
  public static function formatDocsHits(string $query, array $hits, int $sectionChars = 1500): string {
    if ($hits === []) {
      return sprintf('No docs section matches "%s". Try other keywords, or tell the user the docs do not cover it (https://aincient-labs.com/docs). Do not guess.', $query);
    }
    $out = [sprintf('Docs sections matching "%s", best first. Answer ONLY from this text and link the page you used. Name a menu, button, setting, command or path only if it appears below word for word; if the step the user needs is not here, say the docs do not spell it out and link the closest page — do not guess.', $query)];
    foreach ($hits as $i => $hit) {
      $body = $hit['body'];
      if (mb_strlen($body) > $sectionChars) {
        $body = rtrim(mb_substr($body, 0, $sectionChars)) . ' … (read_docs_page for the rest)';
      }
      $out[] = sprintf("\n%d. %s%s\n%s\n%s", $i + 1, $hit['title'], $hit['heading'] !== '' ? ' — ' . $hit['heading'] : '', $hit['url'], $body);
    }
    return implode("\n", $out);
  }

  /**
   * Search one source.
   *
   * @return list<array{title: string, heading: string, url: string, body: string, score: float}>
   */
  public function search(string $source, string $query, int $limit = 5): array {
    $terms = self::terms($query);
    if ($terms === []) {
      return [];
    }
    return $this->isPostgres()
      ? $this->searchPostgres($source, $terms, $limit)
      : $this->searchFallback($source, $terms, $limit);
  }

  /**
   * Re-index the docs when the bundle changed since the last index.
   *
   * @return bool
   *   TRUE when it re-indexed.
   */
  public function ensureDocsIndexed(): bool {
    $hash = $this->docs->hash();
    if ($this->state->get(self::DOCS_HASH_STATE) === $hash) {
      return FALSE;
    }
    $this->replaceSource('docs', $this->docs->chunks());
    $this->state->set(self::DOCS_HASH_STATE, $hash);
    return TRUE;
  }

  /**
   * Replace every row of one source.
   *
   * @param list<array{slug: string, title: string, heading: string, url: string, body: string}> $chunks
   */
  public function replaceSource(string $source, array $chunks): void {
    $this->ensureFullTextIndex();
    $transaction = $this->database->startTransaction();
    try {
      $this->database->delete(self::TABLE)->condition('source', $source)->execute();
      if ($chunks !== []) {
        $insert = $this->database->insert(self::TABLE)->fields(['source', 'ref', 'title', 'heading', 'url', 'body']);
        foreach ($chunks as $chunk) {
          $insert->values([
            'source' => $source,
            'ref' => mb_substr($chunk['slug'], 0, 255),
            'title' => mb_substr($chunk['title'], 0, 255),
            'heading' => mb_substr($chunk['heading'], 0, 255),
            'url' => mb_substr($chunk['url'], 0, 512),
            'body' => $chunk['body'],
          ]);
        }
        $insert->execute();
      }
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
  }

  /**
   * The search words of a query: letters/digits only, lower-cased, unique.
   *
   * @return list<string>
   */
  public static function terms(string $query): array {
    preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($query), $m);
    $terms = array_values(array_unique(array_filter(
      $m[0],
      static fn(string $t): bool => mb_strlen($t) > 1 || ctype_digit($t),
    )));
    // Words the docs use interchangeably with the user's: one pair is enough
    // to matter today ("update" questions, "Upgrade" headings).
    foreach (self::SYNONYMS as $word => $synonym) {
      if (in_array($word, $terms, TRUE) && !in_array($synonym, $terms, TRUE)) {
        $terms[] = $synonym;
      }
    }
    return array_slice($terms, 0, 16);
  }

  /**
   * PostgreSQL full-text search: any term may match, ranked by cover density.
   *
   * @param list<string> $terms
   *
   * @return list<array{title: string, heading: string, url: string, body: string, score: float}>
   */
  private function searchPostgres(string $source, array $terms, int $limit): array {
    // OR the terms: a question in plain words rarely has ALL its words in one
    // section, and ranking already puts sections that match more of them first.
    // Terms are [\p{L}\p{N}]+ only, so nothing in them is tsquery syntax.
    $tsquery = implode(' | ', $terms);
    // Rank first by HOW MANY of the words a section has, then by cover
    // density: otherwise one common word in a title ("image") outranks the
    // section that has all of "image slider carousel lightbox" in its body.
    // Each matched word counts by its rarity (IDF), so "Atelier" — on every
    // page — does not tie with "update", the word that decides the answer.
    $args = [':tsquery' => $tsquery, ':source' => $source];
    $total = (int) $this->database->query('SELECT COUNT(*) FROM {' . self::TABLE . '} WHERE source = :source', [':source' => $source])->fetchField();
    $matched = [];
    foreach ($terms as $i => $term) {
      $df = (int) $this->database->query(
        'SELECT COUNT(*) FROM {' . self::TABLE . '} WHERE source = :source AND ' . self::PG_VECTOR . ' @@ to_tsquery(\'english\', :t)',
        [':source' => $source, ':t' => $term],
      )->fetchField();
      if ($df === 0) {
        // A stop word or a word no section has: it can't rank anything.
        continue;
      }
      $idf = log(($total + 1) / ($df + 0.5));
      $args[':t' . $i] = $term;
      $args[':h' . $i] = $term . ':AB';
      // Matched anywhere, plus once more when it is in the title or heading
      // (weights A/B): a section ABOUT the word beats one that mentions it.
      $matched[] = '((' . self::PG_VECTOR . ' @@ to_tsquery(\'english\', :t' . $i . '))::int + (' . self::PG_VECTOR . ' @@ to_tsquery(\'english\', :h' . $i . '))::int) * ' . sprintf('%.6F', $idf);
    }
    if ($matched === []) {
      return [];
    }
    $rows = $this->database->query(
      'SELECT title, heading, url, body, ts_rank_cd(' . self::PG_VECTOR . ', q, 1) AS score, ' . implode(' + ', $matched) . ' AS matched
         FROM {' . self::TABLE . '}, to_tsquery(\'english\', :tsquery) q
        WHERE source = :source AND ' . self::PG_VECTOR . ' @@ q
        ORDER BY matched DESC, score DESC, id ASC
        LIMIT ' . max(1, min(20, $limit)),
      $args,
    )->fetchAll(\PDO::FETCH_ASSOC);
    return array_map(static fn(array $r): array => [
      'title' => (string) $r['title'],
      'heading' => (string) $r['heading'],
      'url' => (string) $r['url'],
      'body' => (string) $r['body'],
      'score' => (float) $r['matched'] + (float) $r['score'],
    ], $rows);
  }

  /**
   * Plain term match for drivers without full-text search.
   *
   * @param list<string> $terms
   *
   * @return list<array{title: string, heading: string, url: string, body: string, score: float}>
   */
  private function searchFallback(string $source, array $terms, int $limit): array {
    $terms = array_values(array_filter($terms, static fn(string $t): bool => !in_array($t, self::STOP_WORDS, TRUE)));
    if ($terms === []) {
      return [];
    }
    $rows = $this->database->select(self::TABLE, 'c')
      ->fields('c', ['id', 'title', 'heading', 'url', 'body'])
      ->condition('source', $source)
      ->orderBy('id')
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);
    $hits = [];
    foreach ($rows as $row) {
      $score = 0.0;
      foreach ($terms as $term) {
        // Crude stemming: "components" finds "component" and vice versa.
        $stem = (string) preg_replace('/(ies|es|s|ing|ed)$/u', '', $term);
        $stem = mb_strlen($stem) >= 3 ? $stem : $term;
        $pattern = '/\b' . preg_quote($stem, '/') . '/iu';
        $weight = 1.0 * (preg_match($pattern, (string) $row['title']) ? 1 : 0)
          + 0.4 * (preg_match($pattern, (string) $row['heading']) ? 1 : 0)
          + 0.1 * min(5, (int) preg_match_all($pattern, (string) $row['body']));
        // Each matched word counts 10 before any weighting, as on PostgreSQL:
        // more of the question's words beats one word in a title.
        $score += $weight > 0 ? 10 + $weight : 0;
      }
      if ($score > 0) {
        $hits[] = [
          'title' => (string) $row['title'],
          'heading' => (string) $row['heading'],
          'url' => (string) $row['url'],
          'body' => (string) $row['body'],
          'score' => $score,
        ];
      }
    }
    // usort is stable: equal scores keep table (= corpus) order.
    usort($hits, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);
    return array_slice($hits, 0, max(1, min(20, $limit)));
  }

  /**
   * Create the PostgreSQL GIN index over the document vector, once.
   */
  private function ensureFullTextIndex(): void {
    if (!$this->isPostgres()) {
      return;
    }
    // The prefixed table name keeps the index name unique per test prefix.
    $table = trim($this->database->getPrefix() . self::TABLE, '"');
    $this->database->query('CREATE INDEX IF NOT EXISTS ' . $table . '_fts ON {' . self::TABLE . '} USING GIN (' . self::PG_VECTOR . ')');
  }

  /**
   * Whether the site runs on PostgreSQL.
   */
  private function isPostgres(): bool {
    return $this->database->databaseType() === 'pgsql';
  }

}
