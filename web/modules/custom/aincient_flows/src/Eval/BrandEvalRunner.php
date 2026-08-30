<?php

declare(strict_types=1);

namespace Drupal\aincient_flows\Eval;

use Drupal\aincient_pages\BrandRepository;
use Drupal\aincient_pages\ColorContrast;
use Drupal\aincient_pages\TokenResolver;
use Drupal\Component\Utility\Crypt;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\user\Entity\User;

/**
 * Runs ONE brand eval case as a real `brand_studio` turn and reads the trail.
 *
 * The subject is the orchestrator↔specialist LOOP, so the turn goes through the
 * same entry point the console uses — `aincient_chat.processor` → the FlowDrop
 * dispatcher → the session's pinned orchestrator — never a direct model call.
 * Eight brand-agent defects were fixed in two days (cms #36–#44) with the
 * 144-suite phpunit gate green throughout; six were only findable by running a
 * turn. This is the net under that gap.
 *
 * Per case: snapshot `aincient_pages.brand`, install the fixture (tokens +
 * status), create the console session under uid 1 and seed its conversation
 * buffer with the case history, render the draft through the SAME
 * BrandTurnContext the studio uses, run the turn, then read the job trail
 * (pipeline → jobs, not the UI): the specialist executor's `slice`, the merge
 * node's applied envelope, the orchestrator's final prose. The fixture is
 * restored in `finally` — the dev brand is never left changed.
 *
 * Cross-module services (aincient_chat, flowdrop_*) are looked up at run time,
 * the way FlowDropDispatcher reaches FlowDrop: aincient_flows does not depend
 * on aincient_chat and a drush command class must not make it.
 */
final class BrandEvalRunner {

  public const WORKFLOW = 'brand_studio';

  /**
   * Specialist executor node-type prefix → axis name.
   */
  private const AXES = [
    'flowdrop_workflow_executor_flowdrop_workflow_aincient_brand_specialist_colour' => 'colour',
    'flowdrop_workflow_executor_flowdrop_workflow_aincient_brand_specialist_shape' => 'shape',
    'flowdrop_workflow_executor_flowdrop_workflow_aincient_brand_specialist_typography' => 'typography',
  ];

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountSwitcherInterface $accountSwitcher,
    private readonly BrandRepository $brand,
    private readonly TokenResolver $resolver,
    private readonly ColorContrast $contrast,
  ) {}

  /**
   * Whether a turn can run at all — a provider must be bound to the roles.
   */
  public function preflight(): ?string {
    foreach (['aincient_chat.processor', 'aincient_chat.brand_turn_context', 'flowdrop_session.service', 'flowdrop_memory.manager', 'aincient_core.usage_query'] as $sid) {
      if (!\Drupal::hasService($sid)) {
        return "Service $sid is missing — is aincient_chat enabled and FlowDrop installed?";
      }
    }
    if (!$this->entityTypeManager->getStorage('flowdrop_workflow')->load(self::WORKFLOW)) {
      return 'Workflow ' . self::WORKFLOW . ' is not installed.';
    }
    if (\Drupal::hasService('aincient_core.inference.gateway')
      && method_exists($gateway = \Drupal::service('aincient_core.inference.gateway'), 'canText')
      && !$gateway->canText('reasoning')) {
      return 'The reasoning role is unbound — connect a provider in onboarding before running evals.';
    }
    return NULL;
  }

  /**
   * Run one case; returns the observed map plus bookkeeping.
   *
   * @return array{observed: array<string, mixed>, thread_id: string, session_id: ?int, pipeline_id: ?int, seconds: float}
   */
  public function run(BrandEvalCase $case, bool $deleteSession = FALSE): array {
    $config = $this->configFactory->getEditable(BrandRepository::CONFIG);
    $snapshot = $config->getRawData();
    $uid1 = User::load(1);
    if ($uid1 === NULL) {
      throw new \RuntimeException('User 1 does not exist; the eval runs its turns as uid 1.');
    }
    $this->accountSwitcher->switchTo($uid1);
    $started = microtime(TRUE);
    // Entity `created` fields and the usage log stamp REQUEST time, which for
    // a drush process is its start — earlier than time() here. Window from the
    // earlier of the two, with slack, or a fast turn is "not found".
    $since = min(time(), (int) \Drupal::time()->getRequestTime()) - 5;
    $threadId = 'thr_eval_' . preg_replace('/[^a-z0-9]/', '', $case->name) . '_' . Crypt::randomBytesBase64(4);
    $sessionId = NULL;
    $pipelineId = NULL;
    $observed = ['status' => 'unknown'];

    try {
      // Fixture: the saved brand this scenario starts from. Tokens are the
      // case's map ({} = registry defaults); status is the design-intent stage.
      $config
        ->set('tokens', $case->savedTokens)
        ->set('status', [
          'stage' => $case->status === 'locked' ? BrandRepository::STAGE_POLISH : $case->status,
          'locked' => $case->status === 'locked',
        ])
        ->save();

      $session = $this->createSeededSession($threadId, $case);
      $sessionId = (int) $session->id();

      // The draft rides exactly as the studio sends it: through BrandTurnContext,
      // plus the context policy the controller adds to every turn (0379).
      $variables = \Drupal::service('aincient_chat.brand_turn_context')
        ->variables($case->draft === [] ? NULL : ['overrides' => $case->draft]) ?? [];
      if (class_exists('\Drupal\aincient_chat\Chat\ContextPolicy')) {
        $variables += ['context_policy' => \Drupal\aincient_chat\Chat\ContextPolicy::TEXT];
      }
      $clientContext = $variables === [] ? [] : ['variables' => $variables];

      $events = ['result' => '', 'error' => ''];
      $stream = \Drupal::service('aincient_chat.processor')
        ->processTurn($case->ask, $threadId, NULL, self::WORKFLOW, $clientContext);
      foreach ($stream as $event) {
        $type = $event->type->name ?? (string) $event->type->value;
        if ($type === 'RESULT') {
          $events['result'] .= (string) ($event->data['text'] ?? '');
        }
        elseif ($type === 'ERROR') {
          $events['error'] .= (string) ($event->data['message'] ?? '');
        }
        elseif ($type === 'INTERRUPT' && $events['result'] === '') {
          // A paused turn (a confirmation card) has no prose yet — the prompt
          // is what the user would read.
          $events['result'] = (string) ($event->data['prompt'] ?? '');
        }
      }

      $pipeline = $this->findPipeline($sessionId, $since);
      $pipelineId = $pipeline ? (int) $pipeline->id() : NULL;
      $observed = $this->observe($case, $pipeline, $events, $since);

      if ($deleteSession) {
        \Drupal::service('flowdrop_session.service')->deleteSession($session);
      }
      elseif (\Drupal::hasService('aincient_chat.thread_store')) {
        // Hidden from the console's thread list, kept for forensics
        // (pipeline + jobs stay either way).
        \Drupal::service('aincient_chat.thread_store')->archive($threadId, 1, TRUE);
      }
    }
    finally {
      // Never leave the dev brand changed — restore the exact snapshot.
      $this->configFactory->getEditable(BrandRepository::CONFIG)->setData($snapshot)->save();
      $this->accountSwitcher->switchBack();
    }

    return [
      'observed' => $observed,
      'thread_id' => $threadId,
      'session_id' => $sessionId,
      'pipeline_id' => $pipelineId,
      'seconds' => microtime(TRUE) - $started,
    ];
  }

  /**
   * Create the console session the dispatcher will reuse, with the case history
   * in BOTH places a turn reads it: the session-scoped conversation buffer (what
   * the model sees) and the session messages (what a person sees on inspection).
   */
  private function createSeededSession(string $threadId, BrandEvalCase $case): object {
    $sessions = \Drupal::service('flowdrop_session.service');
    $workflow = $this->entityTypeManager->getStorage('flowdrop_workflow')->load(self::WORKFLOW);
    // "console:<thread>" is the dispatcher's 1:1 thread→session key
    // (FlowDropDispatcher::sessionForThread); it re-pins its orchestrator
    // settings on reuse, so creating it here changes nothing about the turn.
    $session = $sessions->createSession($workflow, 'console:' . $threadId);
    if ($case->history === []) {
      return $session;
    }

    [$key, $backend] = $this->sessionBufferConfig($workflow);
    $messages = [];
    $t = time() - 600;
    foreach ($case->history as $i => $turn) {
      $messages[] = ['role' => $turn['role'], 'content' => $turn['content'], 'timestamp' => $t + $i];
      if ($turn['role'] === 'user') {
        $sessions->addUserMessage($session, $turn['content']);
      }
      else {
        $sessions->addAssistantMessage($session, $turn['content']);
      }
    }
    \Drupal::service('flowdrop_memory.manager')
      ->set('session', (string) $session->id(), $key, $messages, NULL, $backend);
    return $session;
  }

  /**
   * The conversation buffer's `key` + `backend` for the session scope, read
   * from the workflow so a renamed buffer cannot silently orphan the seed.
   *
   * @return array{0: string, 1: string}
   */
  private function sessionBufferConfig(object $workflow): array {
    foreach ((array) $workflow->getNodes() as $node) {
      $cfg = $node['data']['config'] ?? [];
      if (is_array($cfg) && ($cfg['scope'] ?? '') === 'session' && isset($cfg['key'])) {
        return [(string) $cfg['key'], (string) ($cfg['backend'] ?? 'entity')];
      }
    }
    throw new \RuntimeException(self::WORKFLOW . ' has no session-scoped conversation buffer — the eval cannot seed history.');
  }

  /**
   * The ROOT pipeline this turn ran (nested specialist pipelines have a parent).
   */
  private function findPipeline(int $sessionId, int $since): ?object {
    $storage = $this->entityTypeManager->getStorage('flowdrop_pipeline');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('workflow_id', self::WORKFLOW)
      ->condition('created', $since, '>=')
      ->sort('id', 'DESC')
      ->range(0, 20)
      ->execute();
    foreach ($storage->loadMultiple($ids) as $pipeline) {
      $parent = (int) ($pipeline->get('parent_pipeline_id')->value ?? 0);
      if ($parent !== 0) {
        continue;
      }
      $ctx = json_decode((string) ($pipeline->get('execution_context')->value ?? ''), TRUE) ?: [];
      if ((int) ($ctx['playground']['session_id'] ?? 0) === $sessionId) {
        return $pipeline;
      }
    }
    return NULL;
  }

  /**
   * Build the observed map from the job trail (see BrandEvalAssertions).
   *
   * @param array{result: string, error: string} $events
   *
   * @return array<string, mixed>
   */
  private function observe(BrandEvalCase $case, ?object $pipeline, array $events, int $since): array {
    $o = [
      'status' => $pipeline ? (string) $pipeline->getStatus() : 'unknown',
      'prose' => $events['result'] !== '' ? $events['result'] : $events['error'],
      'error' => $events['error'],
      'rejected' => [],
    ];
    $delegations = ['colour' => 0, 'shape' => 0, 'typography' => 0, 'total' => 0];
    $slice = [];
    $applied = [];
    $lastProse = '';
    $model = '';

    if ($pipeline !== NULL && method_exists($pipeline, 'getJobs')) {
      foreach ($pipeline->getJobs() as $job) {
        $type = (string) $job->getMetadataValue('node_type_id', '');
        $out = $job->getOutputData();
        if (isset(self::AXES[$type])) {
          $delegations[self::AXES[$type]]++;
          $delegations['total']++;
          $decoded = json_decode((string) ($out['slice'] ?? ''), TRUE);
          if (is_array($decoded)) {
            foreach (['tokens_json', 'presets_json'] as $k) {
              if (is_array($decoded[$k] ?? NULL)) {
                $slice = array_merge($slice, $decoded[$k]);
              }
            }
            if (isset($decoded['fonts'])) {
              $slice['fonts'] = $decoded['fonts'];
            }
          }
        }
        elseif ($type === 'aincient_flows_brand_apply_slices') {
          $envelope = json_decode((string) ($out['widget'] ?? ''), TRUE);
          $payload = is_array($envelope) ? ($envelope['payload'] ?? []) : [];
          if (is_array($payload['tokens'] ?? NULL)) {
            $applied = $payload['tokens'];
          }
          $o['rejected'] = array_values((array) ($payload['rejected'] ?? []));
        }
        elseif ($type === 'aincient_reason') {
          if (($out['text'] ?? '') !== '') {
            $lastProse = (string) $out['text'];
          }
          $model = (string) ($out['raw_result']['model'] ?? $model);
        }
      }
    }
    if ($o['prose'] === '' && $lastProse !== '') {
      $o['prose'] = $lastProse;
    }
    foreach ($delegations as $axis => $n) {
      $o["delegations.$axis"] = $n;
    }
    foreach ($slice as $name => $value) {
      $o["slice.$name"] = is_array($value) ? json_encode($value) : (string) $value;
    }
    $nonzero = [];
    foreach ($applied as $cssVar => $value) {
      $o["applied.$cssVar"] = (string) $value;
      if (!preg_match('/^(0|0px|0rem|none|var\(--radius-none\))$/i', trim((string) $value))) {
        $nonzero[] = $cssVar;
      }
    }
    // What the studio actually received, as one greppable line — a case can
    // ask "did SOME radius become non-zero" without knowing whether the
    // specialist answered with a preset (expanded to many tokens) or one knob.
    $o['applied_keys'] = implode(',', array_keys($applied));
    $o['applied_nonzero'] = implode(',', $nonzero);

    // Colour maths over BEFORE (draft over saved fixture) and AFTER (slice over
    // that). Both keyed by token name; the draft arrives by css_var — the
    // resolver owns that tolerance.
    $before = $this->resolver->byName($case->draft) + $case->savedTokens;
    $written = array_filter($slice, static fn ($v) => is_string($v));
    $after = $this->resolver->byName($written) + $before;
    foreach ($this->contrast->pairReport($after) as $pair) {
      if ($pair['ratio'] !== NULL) {
        $o['contrast.' . $pair['surface']] = round((float) $pair['ratio'], 2);
      }
    }
    foreach ($this->resolver->byName($written) as $name => $value) {
      $from = OklchMath::toOklch((string) ($this->resolver->resolve((string) $this->effectiveBefore($name, $before), $before) ?? ''));
      $to = OklchMath::toOklch((string) ($this->resolver->resolve($value, $after) ?? ''));
      if ($from === NULL || $to === NULL) {
        continue;
      }
      $o["lightness_delta.$name"] = round($to[0] - $from[0], 3);
      $o["chroma_delta.$name"] = round($to[1] - $from[1], 3);
      if ($from[2] !== NULL && $to[2] !== NULL) {
        $o["hue_delta.$name"] = round(OklchMath::hueDelta($from[2], $to[2]), 1);
      }
    }

    $usage = \Drupal::service('aincient_core.usage_query');
    $totals = $usage->totals($since);
    $o['calls'] = $totals['calls'];
    $o['cost_usd'] = round((float) $totals['spend'], 4);
    $o['model'] = $model;
    return $o;
  }

  /**
   * The value a token had before the turn: the draft/saved override when there
   * is one, else the registry default the brand renders.
   */
  private function effectiveBefore(string $name, array $before): string {
    if (isset($before[$name])) {
      return (string) $before[$name];
    }
    return $this->brand->effectiveValue($name);
  }

}
