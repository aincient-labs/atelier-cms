<?php

declare(strict_types=1);

namespace Drupal\aincient_flows\Eval;

use Drupal\Component\Utility\Crypt;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\user\Entity\User;

/**
 * Runs ONE docs-eval case as a real General-assistant turn, then judges it.
 *
 * Same entry point as the console (`aincient_chat.processor` → dispatcher →
 * operator workflow), so the runtime docs verifier (0525) is part of what is
 * measured. Observed per run: the answer the user saw, the agent's draft
 * before the check, the check's verdict and removed claims, the docs text the
 * turn retrieved, and an independent judge's verdict ({@see DocsEvalJudge}).
 *
 * Cross-module services are looked up at run time, like BrandEvalRunner.
 */
final class DocsEvalRunner {

  public const WORKFLOW = 'aincient_operator_agent_loop';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountSwitcherInterface $accountSwitcher,
    private readonly DocsEvalJudge $judge,
  ) {}

  /**
   * Whether a turn can run at all.
   */
  public function preflight(): ?string {
    foreach (['aincient_chat.processor', 'flowdrop_session.service', 'aincient_core.usage_query'] as $sid) {
      if (!\Drupal::hasService($sid)) {
        return "Service $sid is missing — is aincient_chat enabled and FlowDrop installed?";
      }
    }
    if (!$this->entityTypeManager->getStorage('flowdrop_workflow')->load(self::WORKFLOW)) {
      return 'Workflow ' . self::WORKFLOW . ' is not installed.';
    }
    $gateway = \Drupal::service('aincient_core.inference.gateway');
    foreach (['task', 'reasoning'] as $role) {
      if (!$gateway->canText($role)) {
        return "The $role role is unbound — connect a provider in onboarding before running evals.";
      }
    }
    return NULL;
  }

  /**
   * Run one case once.
   *
   * @return array<string, mixed>
   *   The observed map (answer, draft, docs_check, removed, searched, judge…).
   */
  public function run(DocsEvalCase $case, bool $deleteSession = FALSE): array {
    $uid1 = User::load(1);
    if ($uid1 === NULL) {
      throw new \RuntimeException('User 1 does not exist; the eval runs its turns as uid 1.');
    }
    $this->accountSwitcher->switchTo($uid1);
    $started = microtime(TRUE);
    $since = min(time(), (int) \Drupal::time()->getRequestTime()) - 5;
    // Usage rows carry REQUEST time (one stamp for the whole drush process),
    // so a run's cost is the difference of two totals, not a time window.
    $spentBefore = \Drupal::service('aincient_core.usage_query')->totals($since)['spend'];
    $threadId = 'thr_eval_docs_' . preg_replace('/[^a-z0-9]/', '', $case->name) . '_' . Crypt::randomBytesBase64(4);
    try {
      $sessions = \Drupal::service('flowdrop_session.service');
      $workflow = $this->entityTypeManager->getStorage('flowdrop_workflow')->load(self::WORKFLOW);
      $session = $sessions->createSession($workflow, 'console:' . $threadId);

      $answer = '';
      $error = '';
      $check = 'none';
      $removed = [];
      $linkedBy = '';
      $variables = class_exists('\Drupal\aincient_chat\Chat\ContextPolicy')
        ? ['variables' => ['context_policy' => \Drupal\aincient_chat\Chat\ContextPolicy::TEXT]]
        : [];
      foreach (\Drupal::service('aincient_chat.processor')->processTurn($case->ask, $threadId, NULL, self::WORKFLOW, $variables) as $event) {
        $type = $event->type->name ?? (string) $event->type->value;
        if ($type === 'RESULT') {
          $answer .= (string) ($event->data['text'] ?? '');
        }
        elseif ($type === 'ERROR') {
          $error .= (string) ($event->data['message'] ?? '');
        }
        elseif ($type === 'STATUS' && isset($event->data['docs_link'])) {
          // The dispatcher searched for the assistant and linked the page its
          // reply names: the user still gets a source (plan G5).
          $linkedBy = (string) $event->data['docs_link'];
        }
        elseif ($type === 'STATUS' && isset($event->data['docs_check'])) {
          $check = (string) $event->data['docs_check'];
          $removed = array_values((array) ($event->data['docs_removed'] ?? []));
        }
      }

      [$draft, $evidence] = $this->trail((int) $session->id(), $since);
      $urls = [];
      foreach (array_merge($evidence, [$answer]) as $chunk) {
        preg_match_all('~https://aincient-labs\.com/docs[^\s)\]>"\'#]*~', $chunk, $m);
        foreach ($m[0] as $url) {
          $urls[rtrim($url, '.,;:')] = TRUE;
        }
      }
      $verdict = $answer === ''
        ? ['grounded' => FALSE, 'admits_gap' => FALSE, 'unsupported' => ['(no answer: ' . $error . ')'], 'claims' => 0, 'pages' => []]
        : $this->judge->judge($case->ask, $answer, array_keys($urls), class_exists('\Drupal\aincient_chat\Chat\DocsAnswerVerifier') ? \Drupal\aincient_chat\Chat\DocsAnswerVerifier::consoleFacts(self::WORKFLOW) : '');

      preg_match_all('~https://aincient-labs\.com(/docs[^\s)\]>"\'#]*)~', $answer, $links);
      $usage = \Drupal::service('aincient_core.usage_query')->totals($since);

      if ($deleteSession) {
        $sessions->deleteSession($session);
      }
      elseif (\Drupal::hasService('aincient_chat.thread_store')) {
        \Drupal::service('aincient_chat.thread_store')->archive($threadId, 1, TRUE);
      }

      return [
        'answer' => $answer,
        'error' => $error,
        'draft' => $draft,
        'docs_check' => $check,
        'removed' => $removed,
        'searched' => $evidence !== [] || $linkedBy !== '',
        'links' => array_values(array_unique($links[1])),
        'judge' => $verdict,
        'cost_usd' => $usage['spend'] === NULL || $spentBefore === NULL ? NULL : round($usage['spend'] - $spentBefore, 4),
        'seconds' => round(microtime(TRUE) - $started, 1),
        'thread_id' => $threadId,
      ];
    }
    finally {
      $this->accountSwitcher->switchBack();
    }
  }

  /**
   * Check one run against its case.
   *
   * @return list<string>
   *   Failed expectations (empty = pass).
   */
  public static function failures(DocsEvalCase $case, array $o): array {
    $failed = [];
    if ($o['answer'] === '') {
      return ['no answer' . ($o['error'] !== '' ? ': ' . $o['error'] : '')];
    }
    if (!$o['searched']) {
      $failed[] = 'never searched the docs';
    }
    if (!$o['judge']['grounded']) {
      $failed[] = 'unsupported: ' . implode(' | ', $o['judge']['unsupported']);
    }
    if ($case->kind === 'uncovered' && !$o['judge']['admits_gap']) {
      $failed[] = 'did not say the docs don\'t cover it';
    }
    if ($case->linksAny !== []) {
      $hit = FALSE;
      foreach ($o['links'] as $link) {
        foreach ($case->linksAny as $want) {
          if (str_starts_with($link, $want)) {
            $hit = TRUE;
          }
        }
      }
      if (!$hit) {
        $failed[] = 'no link to ' . implode(' or ', $case->linksAny) . ' (got ' . (implode(', ', $o['links']) ?: 'none') . ')';
      }
    }
    foreach ($case->mentionAll as $pattern) {
      if (!preg_match($pattern, $o['answer'])) {
        $failed[] = "does not mention $pattern";
      }
    }
    if ($case->proseNot !== '' && ($hit = self::assertedMatch($case->proseNot, $o['answer'])) !== NULL) {
      $failed[] = "says \"$hit\"";
    }
    return $failed;
  }

  /**
   * The first match of a pattern in a sentence that is not negated.
   *
   * "Atelier doesn't have an import tool" is the honest answer to an
   * uncovered ask, not an invented feature (baseline 2026-11-13 failed it).
   */
  public static function assertedMatch(string $pattern, string $text): ?string {
    if (!preg_match_all($pattern, $text, $m, PREG_OFFSET_CAPTURE)) {
      return NULL;
    }
    foreach ($m[0] as [$match, $offset]) {
      $before = substr($text, 0, $offset);
      $start = max((int) strrpos($before, '. '), (int) strrpos($before, "\n"), (int) strrpos($before, '? '), (int) strrpos($before, '! '));
      $rest = substr($text, $offset);
      $end = preg_match('/[.!?](\s|$)|\n/', $rest, $e, PREG_OFFSET_CAPTURE) ? $offset + $e[0][1] : strlen($text);
      $sentence = substr($text, $start, $end - $start);
      if (!preg_match("/\\b(not|no|never|none|without|cannot)\\b|n['’]t\\b/i", $sentence)) {
        return $match;
      }
    }
    return NULL;
  }

  /**
   * The agent's last draft (before the check) and the docs text it retrieved.
   *
   * @return array{0: string, 1: list<string>}
   */
  private function trail(int $sessionId, int $since): array {
    $storage = $this->entityTypeManager->getStorage('flowdrop_pipeline');
    $ids = $storage->getQuery()->accessCheck(FALSE)
      ->condition('workflow_id', self::WORKFLOW)
      ->condition('created', $since, '>=')
      ->sort('id', 'DESC')->range(0, 20)->execute();
    $root = NULL;
    foreach ($storage->loadMultiple($ids) as $pipeline) {
      if ((int) ($pipeline->get('parent_pipeline_id')->value ?? 0) !== 0) {
        continue;
      }
      $ctx = json_decode((string) ($pipeline->get('execution_context')->value ?? ''), TRUE) ?: [];
      if ((int) ($ctx['session']['session_id'] ?? $ctx['playground']['session_id'] ?? 0) === $sessionId) {
        $root = $pipeline;
        break;
      }
    }
    if ($root === NULL) {
      return ['', []];
    }
    $childIds = $storage->getQuery()->accessCheck(FALSE)
      ->condition('root_pipeline_id', (int) $root->id())
      ->sort('id')->execute();
    $draft = '';
    $evidence = [];
    foreach ($storage->loadMultiple(array_merge([(int) $root->id()], array_values($childIds))) as $pipeline) {
      foreach ($pipeline->getJobs() as $job) {
        $raw = (string) ($job->get('output_data')->value ?? '');
        if ((string) $job->getMetadataValue('node_type_id', '') === 'aincient_reason') {
          $out = $job->getOutputData();
          if (($out['text'] ?? '') !== '') {
            $draft = (string) $out['text'];
          }
        }
        if (!str_contains($raw, 'tool_results') && !str_contains($raw, 'tool_messages')) {
          continue;
        }
        $data = json_decode($raw, TRUE);
        $rows = is_array($data) ? ($data['tool_results'] ?? $data['tool_messages'] ?? []) : [];
        foreach ((array) $rows as $row) {
          $content = is_array($row) ? ($row['content'] ?? NULL) : NULL;
          if (!is_string($content)) {
            continue;
          }
          $inner = json_decode($content, TRUE);
          $candidate = is_array($inner) && is_string($inner['message'] ?? NULL) ? $inner['message'] : $content;
          foreach (['Docs sections matching', 'No docs section matches', 'Docs page: '] as $marker) {
            if (str_starts_with(ltrim($candidate), $marker) && !in_array($candidate, $evidence, TRUE)) {
              $evidence[] = $candidate;
            }
          }
        }
      }
    }
    return [$draft, $evidence];
  }

}
