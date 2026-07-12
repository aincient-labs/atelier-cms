<?php

declare(strict_types=1);

namespace Drupal\aincient_audit\Entity;

use Drupal\Core\Config\Entity\ConfigEntityInterface;

/**
 * A stored page-health policy — the Phase-3 first-class artifact.
 *
 * DECISIONS 0129 fixed the representation (a policy IS a FlowDrop workflow);
 * DECISIONS 0134 (Phase 3) moves the policy DEFINITION out of code — the
 * hardcoded `PolicyEvaluator::POLICIES` registry, the bundle-only `applies()`
 * selector, and the thresholds baked into the checks — into this config entity,
 * so a policy is enable/disable-able, selector-scoped, and parameter-tunable
 * while shipping as a distribution default via `config/install`.
 *
 * A config (not content) entity by the brand precedent: the live brand is
 * config and {@see \Drupal\aincient_pages\Entity\BrandRevision} is a separate
 * snapshot log — so the policy artifact is config (ships in config, overridable,
 * deployable) and any future version history is a separate snapshot citizen.
 */
interface PolicyInterface extends ConfigEntityInterface {

  /**
   * Execution mode: a graph of native PHP nodes, run synchronously and DB-free.
   */
  public const KIND_DETERMINISTIC = 'deterministic';

  /**
   * Execution mode: the normal persisted (async, budgeted) LLM pipeline. Stored
   * forward-compatibly; NOT executed until Phase 5.
   */
  public const KIND_JUDGED = 'judged';

  /**
   * Enforcement: report-only. The human decides. The v1 default and only honored
   * mode.
   */
  public const ENFORCEMENT_ADVISORY = 'advisory';

  /**
   * Enforcement: gate Publish. Stored forward-compatibly; INERT until Phase 6
   * wires it to content_moderation / entity validation.
   */
  public const ENFORCEMENT_ENFORCING = 'enforcing';

  /**
   * The `flowdrop_workflow` config-entity id run to produce this policy's findings.
   */
  public function getWorkflow(): string;

  /**
   * The execution mode — `deterministic` (v1) or `judged` (Phase 5).
   */
  public function getKind(): string;

  /**
   * Whether this policy's findings advise (v1) or would gate Publish (Phase 6).
   */
  public function getEnforcement(): string;

  /**
   * The report weight — lower sorts first (seo before links).
   */
  public function getWeight(): int;

  /**
   * The tunable knobs passed into the workflow (e.g. `title_min`) — a flat map
   * the policy's check reads by key. Empty is valid (the check falls back to its
   * built-in defaults, preserving byte-identical output).
   *
   * @return array<string, mixed>
   */
  public function getParameters(): array;

  /**
   * The cheap PHP prefilter: does this policy apply to a node of this bundle /
   * moderation state / language? An empty list for an axis means "any".
   *
   * @return array{bundles: list<string>, moderation_states: list<string>, langcodes: list<string>}
   */
  public function getSelector(): array;

}
