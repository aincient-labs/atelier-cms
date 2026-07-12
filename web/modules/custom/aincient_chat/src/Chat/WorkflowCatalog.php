<?php

declare(strict_types=1);

namespace Drupal\aincient_chat\Chat;

use Drupal\aincient_chat\Studio;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * The studio → agents map the console runs.
 *
 * The console is a workspace switcher: it is always in exactly one studio
 * ({@see \Drupal\aincient_chat\Studio}), the default being General. Each studio
 * owns a set of FlowDrop workflows ("agents", a 1:N relationship) plus a default
 * agent a new conversation runs. Admin-owned at /admin/config/aincient-chat.
 *
 * Ids are validated against existing flowdrop_workflow entities, so a stale
 * config entry never reaches the console — and a user-supplied id never reaches
 * the dispatcher unless it belongs to some studio ({@see self::resolve()}). A
 * studio left with no valid agents is treated as disabled (dropped from
 * {@see self::studios()}).
 */
final class WorkflowCatalog {

  /**
   * Fallback workflow when config is absent/empty and no studio resolves one.
   */
  public const FALLBACK_WORKFLOW = 'aincient_operator_agent_loop';

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * The enabled studios, each with its valid agents and resolved default.
   *
   * Studios appear in the enum's display order. Agents are validated against
   * existing flowdrop_workflow entities and returned as id => label (sorted by
   * label). A studio with no valid agent is omitted (disabled). Each studio's
   * `default` is its configured default when still valid, else its first agent.
   *
   * @return array<string, array{agents: array<string, string>, default: string}>
   *   Studio key => {agents: [id => label], default: agent id}. Empty when
   *   FlowDrop isn't installed.
   */
  public function studios(): array {
    if (!$this->entityTypeManager->hasDefinition('flowdrop_workflow')) {
      return [];
    }
    $configured = (array) $this->config()->get('studios');
    $labels = $this->workflowLabels();
    $out = [];
    foreach (Studio::keys() as $key) {
      $entry = (array) ($configured[$key] ?? []);
      $agents = [];
      foreach (array_map('strval', (array) ($entry['agents'] ?? [])) as $id) {
        if (isset($labels[$id])) {
          $agents[$id] = $labels[$id];
        }
      }
      if ($agents === []) {
        // No valid agent — the studio is disabled.
        continue;
      }
      asort($agents);
      $default = (string) ($entry['default'] ?? '');
      if (!isset($agents[$default])) {
        $default = (string) array_key_first($agents);
      }
      $out[$key] = ['agents' => $agents, 'default' => $default];
    }
    return $out;
  }

  /**
   * The studio a fresh console session opens in.
   *
   * The configured `default_studio` when it's an enabled studio; otherwise the
   * first enabled studio; finally the enum default. Always returns a key that is
   * either enabled or the safe fallback.
   */
  public function defaultStudio(): string {
    $studios = $this->studios();
    $configured = (string) $this->config()->get('default_studio');
    if (isset($studios[$configured])) {
      return $configured;
    }
    return $studios === [] ? Studio::default()->value : (string) array_key_first($studios);
  }

  /**
   * Which studio owns a workflow id, or NULL if none does.
   *
   * Deterministic: studios are scanned in enum order, so even if an admin
   * mis-configures an agent into two studios (the form forbids it), bucketing
   * still resolves to a single studio.
   */
  public function studioOf(string $workflowId): ?string {
    foreach ($this->studios() as $key => $studio) {
      if (isset($studio['agents'][$workflowId])) {
        return $key;
      }
    }
    return NULL;
  }

  /**
   * Every agent any studio exposes, as id => label (the run allowlist).
   *
   * @return array<string, string>
   */
  public function allAgents(): array {
    $agents = [];
    foreach ($this->studios() as $studio) {
      $agents += $studio['agents'];
    }
    return $agents;
  }

  /**
   * The agent a conversation runs when the user makes no pick.
   *
   * The default studio's default agent, falling back to the hard-coded
   * {@see self::FALLBACK_WORKFLOW} when nothing is configured.
   */
  public function defaultWorkflow(): string {
    $studios = $this->studios();
    $default = $studios[$this->defaultStudio()]['default'] ?? '';
    return $default !== '' ? $default : self::FALLBACK_WORKFLOW;
  }

  /**
   * Per-flow presentation overrides for the console's fresh-thread welcome.
   *
   * Admin-owned at /admin/config/aincient-chat. Returns only the keys an admin
   * actually set, so the console falls back to its built-in defaults for the
   * rest. `freeformOnly` suppresses the sample-ask chips entirely (the brand
   * agent takes only custom requests).
   *
   * @return array{welcomeText?: string, description?: string, sampleAsks?: list<string>, freeformOnly?: true}
   */
  public function presentation(string $id): array {
    $metadata = (array) $this->config()->get('workflow_metadata');
    $entry = (array) ($metadata[$id] ?? []);
    $out = [];
    if (($welcome = trim((string) ($entry['welcome'] ?? ''))) !== '') {
      $out['welcomeText'] = $welcome;
    }
    if (($description = trim((string) ($entry['description'] ?? ''))) !== '') {
      $out['description'] = $description;
    }
    if (!empty($entry['sample_asks'])) {
      $out['sampleAsks'] = array_values(array_map('strval', (array) $entry['sample_asks']));
    }
    if (!empty($entry['freeform_only'])) {
      $out['freeformOnly'] = TRUE;
    }
    return $out;
  }

  /**
   * Resolve a user-requested workflow id to one the site actually allows.
   *
   * The security gate for the console's `workflow` POST field: anything that
   * isn't an agent of some studio (unknown, stale, or probing) falls back to the
   * default workflow.
   */
  public function resolve(?string $requested): string {
    if ($requested !== NULL && $requested !== '' && array_key_exists($requested, $this->allAgents())) {
      return $requested;
    }
    return $this->defaultWorkflow();
  }

  /**
   * All FlowDrop workflows as id => label.
   *
   * @return array<string, string>
   */
  private function workflowLabels(): array {
    $labels = [];
    foreach ($this->entityTypeManager->getStorage('flowdrop_workflow')->loadMultiple() as $workflow) {
      $labels[(string) $workflow->id()] = (string) $workflow->label();
    }
    return $labels;
  }

  /**
   * The chat settings config object.
   */
  private function config(): ImmutableConfig {
    return $this->configFactory->get('aincient_chat.settings');
  }

}
