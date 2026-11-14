<?php

declare(strict_types=1);

namespace Drupal\aincient_chat\Chat;

use Aincient\Inference\AiGateway;
use Aincient\Inference\ModelRoles;
use Drupal\aincient_core\Docs\GlossaryPage;
use Psr\Log\LoggerInterface;

/**
 * Checks an assistant reply against the docs text its turn retrieved.
 *
 * A verify-then-revise pass (plans/docs-search.md, DECISIONS 0525): when a turn
 * used the docs tools, a SEPARATE model call gets only the draft reply and the
 * exact docs text those tools returned, and judges every concrete claim about
 * Atelier against that text — not against its own knowledge, so it cannot be
 * talked into a plausible guess. Unsupported claims are removed (or the reply
 * becomes "the docs don't cover that"), before the user sees it.
 *
 * Deterministic guards around the model: a revision must keep only links that
 * were already in the reply or the evidence, must not be empty, and must pass
 * the same check again. A failed check (provider error, unparseable verdict)
 * fails CLOSED to an honest "couldn't confirm that, here's the closest page".
 */
final class DocsAnswerVerifier {

  /**
   * Marker lines the docs tools start their results with.
   */
  public const EVIDENCE_MARKERS = ['Docs sections matching', 'No docs section matches', 'Docs page: '];

  /**
   * Evidence characters sent to the verifier (5 sections ≈ 8k; a page ≤ 20k).
   */
  private const MAX_EVIDENCE = 40000;

  /**
   * The model role that checks. `reasoning`, not `task`: in the first eval run
   * (2026-11-13) the task tier passed three answers the judge failed and kept
   * a Stripe/Etsy suggestion in its own rewrite.
   */
  private const ROLE = ModelRoles::REASONING;

  public function __construct(
    private readonly AiGateway $gateway,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Whether a tool result is docs evidence.
   */
  public static function isEvidence(string $toolResult): bool {
    foreach (self::EVIDENCE_MARKERS as $marker) {
      if (str_starts_with(ltrim($toolResult), $marker)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Verify a reply.
   *
   * @param string $answer
   *   The assistant's draft reply.
   * @param list<string> $evidence
   *   The docs tools' results from the same turn.
   *
   * @return array{status: string, text: string, unsupported: list<string>}
   *   status: `skipped` (no evidence), `supported` (unchanged), `revised`, or
   *   `error` (check failed; text = the honest fallback — fail closed).
   */
  public function verify(string $answer, array $evidence): array {
    $evidence = array_values(array_filter($evidence, static fn(string $e): bool => trim($e) !== ''));
    if ($evidence === [] || trim($answer) === '') {
      return ['status' => 'skipped', 'text' => $answer, 'unsupported' => []];
    }
    $evidenceText = implode("\n\n=====\n\n", $evidence);
    if (mb_strlen($evidenceText) > self::MAX_EVIDENCE) {
      $evidenceText = mb_substr($evidenceText, 0, self::MAX_EVIDENCE);
    }

    try {
      $raw = $this->gateway->text($this->prompt($answer, $evidenceText), self::ROLE, 'aincient_chat_docs_verifier', 8000);
    }
    catch (\Throwable $e) {
      // Fail CLOSED: an unchecked reply is exactly what this check exists to
      // stop (eval 2026-11-13: a token-capped check let an invented Typeform
      // workaround through). The honest minimum still links the docs.
      $this->logger->warning('Docs answer check failed (provider): @m', ['@m' => $e->getMessage()]);
      return ['status' => 'error', 'text' => self::fallback($evidenceText), 'unsupported' => []];
    }
    $verdict = self::parse($raw);
    if ($verdict === NULL) {
      $this->logger->warning('Docs answer check returned no verdict: @raw', ['@raw' => mb_substr($raw, 0, 300)]);
      return ['status' => 'error', 'text' => self::fallback($evidenceText), 'unsupported' => []];
    }
    $unsupported = $verdict['unsupported'];
    if ($unsupported === []) {
      return ['status' => 'supported', 'text' => $answer, 'unsupported' => []];
    }

    // Up to two rounds: a rewrite is checked again, and a rewrite that still
    // carries a claim gets one more rewrite. Only then the honest fallback —
    // eval 2026-11-13 run 3 lost two good carousel answers to a fallback after
    // a single leftover phrase.
    $known = $answer . "\n" . $evidenceText;
    $revised = trim($verdict['revised']);
    for ($round = 1; $round <= 2; $round++) {
      if ($revised === '' || !self::linksAllowed($revised, $known)) {
        break;
      }
      $again = $this->check($revised, $evidenceText);
      if ($again === NULL) {
        break;
      }
      if ($again['unsupported'] === []) {
        return ['status' => 'revised', 'text' => $revised, 'unsupported' => $unsupported];
      }
      $unsupported = array_values(array_unique(array_merge($unsupported, $again['unsupported'])));
      $revised = trim($again['revised']);
    }
    return ['status' => 'revised', 'text' => self::fallback($evidenceText), 'unsupported' => $unsupported];
  }

  /**
   * One check of a text; NULL when the check itself failed.
   *
   * @return array{unsupported: list<string>, revised: string}|null
   */
  private function check(string $text, string $evidence): ?array {
    try {
      return self::parse($this->gateway->text($this->prompt($text, $evidence), self::ROLE, 'aincient_chat_docs_verifier', 8000));
    }
    catch (\Throwable $e) {
      $this->logger->warning('Docs answer re-check failed (provider): @m', ['@m' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * The verifier prompt.
   */
  private function prompt(string $answer, string $evidence): string {
    return <<<PROMPT
You check a support reply for Atelier (a website builder) against the ONLY source of truth: the documentation excerpts and console facts below. You do not know anything about Atelier yourself.

Go through the reply and list every CLAIM ABOUT ATELIER that the excerpts do not support:
- a feature, component, section type, setting, integration or limit;
- a menu, tab, button, label, command, path or click-sequence;
- what Atelier or its apps DO ("it prompts you", "it backs up first", "it rolls back");
- a workaround or third-party setup it suggests for Atelier.
A claim is supported only if the excerpts state it, or it follows directly from what they state. Names must match the excerpts' wording.
NOT claims (never list these): greetings, offers to help, questions to the user, "the docs don't cover that", general advice that says nothing about Atelier, and links to aincient-labs.com/docs pages.

Then, if anything is unsupported, rewrite the reply WITHOUT those claims: same language, same friendly tone, keep every supported sentence and every docs link. Where the removed claim answered the user's question, say plainly that the docs don't cover it and point to the closest page from the excerpts. Do not add anything new.

Answer with JSON only, no prose around it:
{"unsupported": ["<claim>", ...], "revised": "<the rewritten reply, or empty string if nothing is unsupported>"}

=== DOCUMENTATION EXCERPTS ===
{$evidence}

=== REPLY TO CHECK ===
{$answer}
PROMPT;
  }

  /**
   * Parse the verifier's JSON (tolerating a fence or stray prose).
   *
   * @return array{unsupported: list<string>, revised: string}|null
   */
  public static function parse(string $raw): ?array {
    $raw = trim($raw);
    $start = strpos($raw, '{');
    $end = strrpos($raw, '}');
    if ($start === FALSE || $end === FALSE || $end < $start) {
      return NULL;
    }
    $data = json_decode(substr($raw, $start, $end - $start + 1), TRUE);
    if (!is_array($data) || !array_key_exists('unsupported', $data) || !is_array($data['unsupported'])) {
      return NULL;
    }
    $unsupported = array_values(array_filter(array_map(
      static fn(mixed $c): string => is_string($c) ? trim($c) : '',
      $data['unsupported'],
    ), static fn(string $c): bool => $c !== ''));
    return ['unsupported' => $unsupported, 'revised' => is_string($data['revised'] ?? NULL) ? $data['revised'] : ''];
  }

  /**
   * Every URL in the revision must already be in the reply or the evidence.
   */
  public static function linksAllowed(string $revised, string $known): bool {
    preg_match_all('#https?://[^\s)\]>"\']+#', $revised, $m);
    foreach ($m[0] as $url) {
      $url = rtrim($url, '.,;:');
      if (!str_contains($known, $url)) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * The General assistant's own "THE CONSOLE, ROOM BY ROOM" section.
   *
   * True by construction (we wrote it, it ships with the release) and the only
   * source for room routing the docs describe loosely ("open Pages from the top
   * bar"). Read from the live workflow config, so there is one home for the
   * facts and the check can never disagree with what the assistant was told.
   */
  public static function consoleFacts(string $workflowId = WorkflowCatalog::FALLBACK_WORKFLOW): string {
    $nodes = \Drupal::config('flowdrop_workflow.flowdrop_workflow.' . $workflowId)->get('nodes');
    foreach (is_array($nodes) ? $nodes : [] as $node) {
      $template = (string) ($node['data']['config']['template'] ?? '');
      if (preg_match('/^# THE CONSOLE, ROOM BY ROOM\n(.*?)(?=^# )/ms', $template, $m)) {
        return "Console facts (from the assistant's own instructions — authoritative):\n" . trim($m[1]);
      }
    }
    return '';
  }

  /**
   * The first docs source in the evidence, as a Markdown link (or '').
   */
  public static function firstSource(array $evidence): string {
    foreach ($evidence as $chunk) {
      if (preg_match('~^\d+\. (.+)\n(https://aincient-labs\.com/docs\S*)~m', $chunk, $m)
        || preg_match('~^Docs page: (.+)\n(https://aincient-labs\.com/docs\S*)~m', $chunk, $m)) {
        return sprintf('[%s](%s)', trim($m[1]), $m[2]);
      }
    }
    return '';
  }

  /**
   * The first docs hit whose title carries a name the reply puts in bold.
   *
   * For a reply the assistant gave without searching: the dispatcher's own
   * search may rank another page first ("change colours" → Component library,
   * which mentions the Identity room), so only a hit about the thing the reply
   * names counts as its source. Small talk names nothing and gets no link.
   */
  public static function namedSource(string $reply, array $evidence): string {
    preg_match_all('/\*\*([^*\n]{2,60})\*\*/', $reply, $bold);
    $names = array_map('trim', $bold[1]);
    // Room names count bold or not ("Colours live in Identity").
    foreach (self::roomNames() as $room) {
      if (preg_match('/(?<![\w])' . preg_quote($room, '/') . '(?![\w])/u', $reply)) {
        $names[] = $room;
      }
    }
    $names = array_values(array_unique($names));
    if ($names === []) {
      return '';
    }
    foreach ($evidence as $chunk) {
      preg_match_all('~^\d+\. (.+)\n(https://aincient-labs\.com/docs\S*)~m', $chunk, $hits, PREG_SET_ORDER);
      foreach ($hits as $hit) {
        foreach ($names as $name) {
          if (preg_match('/(?<![\w])' . preg_quote($name, '/') . '(?![\w])/u', $hit[1])) {
            return sprintf('[%s](%s)', trim($hit[1]), $hit[2]);
          }
        }
      }
    }
    return '';
  }

  /**
   * The console's room names, from the glossary (0526).
   *
   * @return list<string>
   */
  private static function roomNames(): array {
    static $names;
    if ($names === NULL) {
      $names = [];
      foreach (GlossaryPage::load()['terms'] as $term) {
        if (($term['kind'] ?? '') === 'room') {
          $names[] = (string) $term['name'];
          if (!empty($term['short'])) {
            $names[] = (string) $term['short'];
          }
        }
      }
    }
    return $names;
  }

  /**
   * The honest minimum: the docs didn't confirm it, here is the closest page.
   */
  public static function fallback(string $evidence): string {
    $text = "I couldn't confirm that in the Atelier docs, so I'd rather not guess.";
    if (preg_match('#https://aincient-labs\.com/docs[^\s)\]]*#', $evidence, $m)) {
      $text .= sprintf(' The closest page is [%s](%s).', $m[0], $m[0]);
    }
    return $text;
  }

}
