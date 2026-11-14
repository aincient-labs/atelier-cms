<?php

declare(strict_types=1);

namespace Drupal\aincient_flows\Eval;

use Aincient\Inference\AiGateway;
use Aincient\Inference\ModelRoles;
use Drupal\aincient_core\Docs\DocsCorpus;
use Drupal\aincient_core\Search\SearchIndex;

/**
 * Judges a docs-eval answer: is every claim about Atelier backed by the docs?
 *
 * Independent of the runtime verifier on purpose: a different role (reasoning,
 * not task), a different prompt, and WHOLE docs pages rather than the snippets
 * the turn retrieved — so the judge does not share the verifier's blind spot
 * when a snippet was cut short. The pages are the ones the turn retrieved plus
 * any the answer links; a flagged claim is then confirmed against those plus
 * the pages the docs search finds for it (see morePages()).
 */
final class DocsEvalJudge {

  private const MAX_DOCS = 60000;

  public function __construct(
    private readonly AiGateway $gateway,
    private readonly DocsCorpus $docs,
    private readonly SearchIndex $search,
  ) {}

  /**
   * @param list<string> $urls
   *   Docs URLs from the evidence and the answer.
   *
   * @return array{grounded: bool, admits_gap: bool, unsupported: list<string>, claims: int, pages: list<string>, raw: string}
   */
  public function judge(string $question, string $answer, array $urls, string $consoleFacts = ''): array {
    $pages = [];
    $text = '';
    foreach ($urls as $url) {
      $page = $this->docs->page($url);
      if ($page === NULL || isset($pages[$page['slug']])) {
        continue;
      }
      $pages[$page['slug']] = TRUE;
      $text .= "\n\n##### PAGE: {$page['title']} ({$page['url']})\n{$page['description']}\n\n{$page['text']}";
    }
    if (mb_strlen($text) > self::MAX_DOCS) {
      $text = mb_substr($text, 0, self::MAX_DOCS);
    }
    if ($text === '') {
      $text = '(no documentation pages — the assistant retrieved none)';
    }
    if ($consoleFacts !== '') {
      $text .= "\n\n##### " . $consoleFacts;
    }

    $prompt = <<<PROMPT
You are grading a support answer for Atelier, a website builder, for HALLUCINATION. The documentation pages and console facts below are the only truth; you know nothing else about Atelier.

1. List every factual claim the answer makes about Atelier: features, components, settings, limits, integrations, menu/tab/button/label names, commands, paths, steps, what the software does, and any workaround or third-party service it suggests. Skip:
   - greetings, offers, questions, and statements that the docs don't cover something;
   - general computer knowledge that says nothing about Atelier (e.g. "a terminal is the command line on your computer");
   - remarks about the answer or the docs themselves: that it links a page, that a docs page exists, what a page is called or what it covers;
   - that the user can build ordinary pages with Atelier (a portfolio page, a product page, a contact page), or that the assistant can help write or compose one — building pages is what Atelier is for. A specific section, feature or integration used to build them IS a claim.
2. For each, decide if the documentation supports it (stated, or directly implied). Wording of names must match. A workaround the docs don't describe is unsupported, even if it sounds reasonable.
3. Say whether the answer clearly tells the user that the docs don't cover (part of) the request.

Reply with JSON only:
{"claims": [{"claim": "...", "supported": true, "quote": "<short supporting quote or empty>"}], "admits_gap": false}

=== QUESTION ===
{$question}

=== ANSWER ===
{$answer}

=== DOCUMENTATION ===
{$text}
PROMPT;
    $raw = $this->gateway->text($prompt, ModelRoles::REASONING, 'aincient_flows_docs_eval_judge', 4000);
    $start = strpos($raw, '{');
    $end = strrpos($raw, '}');
    $data = $start !== FALSE && $end !== FALSE ? json_decode(substr($raw, $start, $end - $start + 1), TRUE) : NULL;
    if (!is_array($data) || !is_array($data['claims'] ?? NULL)) {
      throw new \RuntimeException('The judge returned no verdict: ' . mb_substr($raw, 0, 200));
    }
    $flagged = [];
    foreach ($data['claims'] as $claim) {
      if (is_array($claim) && ($claim['supported'] ?? TRUE) === FALSE) {
        $flagged[] = (string) ($claim['claim'] ?? '');
      }
    }
    $unsupported = $flagged === [] ? [] : $this->confirm($flagged, $text . $this->morePages($flagged, $pages));
    return [
      'grounded' => $unsupported === [],
      'admits_gap' => (bool) ($data['admits_gap'] ?? FALSE),
      'unsupported' => $unsupported,
      'claims' => count($data['claims']),
      'pages' => array_keys($pages),
      'raw' => $raw,
    ];
  }


  /**
   * Whole pages the docs search finds for the flagged claims.
   *
   * The judge measures whether an answer is TRUE per the docs, not whether the
   * turn happened to retrieve the page (that stricter rule is the runtime
   * check's job). Baseline 2026-11-13 failed a word-for-word email.mdx claim
   * because only the retrieved pages were in front of the judge.
   *
   * @param list<string> $claims
   * @param array<string, true> $seen
   *   Page slugs already in the documentation text.
   */
  private function morePages(array $claims, array $seen): string {
    $text = '';
    foreach ($claims as $claim) {
      foreach ($this->search->searchDocs($claim, 3) as $hit) {
        $page = $this->docs->page($hit['url']);
        if ($page === NULL || isset($seen[$page['slug']])) {
          continue;
        }
        $seen[$page['slug']] = TRUE;
        $text .= "\n\n##### PAGE: {$page['title']} ({$page['url']})\n{$page['description']}\n\n{$page['text']}";
      }
    }
    return mb_strlen($text) > self::MAX_DOCS ? mb_substr($text, 0, self::MAX_DOCS) : $text;
  }

  /**
   * Second look at each flagged claim: quote the support, or NONE.
   *
   * One pass over a long page misses passages (the first run failed "the
   * buttons sit together at the top right", which the docs say word for word).
   * Only a claim with no quotable support counts.
   *
   * @param list<string> $claims
   *
   * @return list<string>
   *   The claims still unsupported.
   */
  private function confirm(array $claims, string $docs): array {
    $list = '';
    foreach ($claims as $i => $claim) {
      $list .= ($i + 1) . '. ' . $claim . "\n";
    }
    $prompt = <<<PROMPT
For each numbered claim about Atelier, search the documentation below for a passage that states it or directly implies it. Names must match.

Reply with JSON only: {"results": [{"n": 1, "quote": "<the supporting passage, verbatim>"}, {"n": 2, "quote": "NONE"}]}

=== CLAIMS ===
{$list}
=== DOCUMENTATION ===
{$docs}
PROMPT;
    $raw = $this->gateway->text($prompt, ModelRoles::REASONING, 'aincient_flows_docs_eval_judge', 3000);
    $start = strpos($raw, '{');
    $end = strrpos($raw, '}');
    $data = $start !== FALSE && $end !== FALSE ? json_decode(substr($raw, $start, $end - $start + 1), TRUE) : NULL;
    if (!is_array($data) || !is_array($data['results'] ?? NULL)) {
      return $claims;
    }
    $still = [];
    foreach ($data['results'] as $row) {
      $n = (int) ($row['n'] ?? 0);
      $quote = trim((string) ($row['quote'] ?? ''));
      if (!isset($claims[$n - 1])) {
        continue;
      }
      // A quote counts only if it really is in the docs (whitespace-normalised).
      $norm = static fn(string $t): string => (string) preg_replace('/\s+/', ' ', mb_strtolower($t));
      if ($quote === '' || strtoupper($quote) === 'NONE' || !str_contains($norm($docs), $norm(trim($quote, '"…. ')))) {
        $still[] = $claims[$n - 1];
      }
    }
    return $still;
  }

}
