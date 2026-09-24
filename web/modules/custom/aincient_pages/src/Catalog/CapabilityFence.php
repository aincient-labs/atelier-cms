<?php

declare(strict_types=1);

namespace Drupal\aincient_pages\Catalog;

/**
 * A pack may not ship agent VERBS (plans/console-extension-point.md Phase 3b).
 *
 * WHY THIS EXISTS, AND WHY IT IS AN ERROR RATHER THAN A WARNING. Drupal plugin
 * discovery is namespace-based: the capability manager
 * ({@see \Drupal\aincient_core\Capability\CapabilityManager}) scans
 * `src/Plugin/AiCapability` in EVERY enabled module, and converge bakes a
 * production pack into `web/modules/custom`. So a capability shipped by a pack
 * is discovered, derived into a FlowDrop node type, and reachable by an agent —
 * on a client's live site, with nobody having reviewed it.
 *
 * That breaks the premise DECISIONS 0368 rested on when it DEFERRED the
 * attachment taint + tool gate: every capability on this install is reversible
 * AND internal (preview, propose, read, draft), so there is nothing autonomous
 * and dangerous for tainted content to reach. A client-authored capability
 * breaks that premise by construction — one that can reach an ERP, send mail or
 * publish is exactly the case 0368 said did not yet exist. 0368 also named the
 * trigger: the roster changing is the signal to build the gate.
 *
 * Our other guard cannot see this. `CapabilityRosterTaintGuardTest` freezes the
 * roster by globbing our own `modules/custom`, and it runs in OUR gate, never
 * inside a client image. This check runs where the pack is, in the validator a
 * pack author and their CI already run.
 *
 * WHEN A CLIENT GENUINELY NEEDS A CUSTOM VERB: that is the 0368 trigger. Build
 * the taint + tool gate first, then open the contract — not the other way
 * round. Do not soften this to a warning in the meantime; silence here is the
 * bug.
 */
final class CapabilityFence {

  /**
   * The plugin directory a pack must not have.
   *
   * Matches the capability manager's own scan directory. If that ever moves,
   * this moves with it — a fence pointed at the wrong door is worse than none.
   */
  public const CAPABILITY_DIR = 'src/Plugin/AiCapability';

  /**
   * The Atelier modules that legitimately own capabilities.
   *
   * The roster's owners, and the ONLY exemption. A new capability-bearing
   * module of ours has to be added here — deliberately, in the same pass that
   * updates `CapabilityRosterTaintGuardTest`, which is the point: two edits,
   * both asking the same question.
   *
   * This is a name check, so a pack that names itself `aincient_brand` walks
   * through. That is acceptable because this fence is a CI aid for honest
   * authors, not the security control: the control is the roster review, and
   * a pack impersonating one of our modules is not a mistake anyone makes by
   * accident.
   *
   * @var array<int, string>
   */
  public const ATELIER_MODULES = [
    'aincient_audit',
    'aincient_brand',
    'aincient_onboarding',
    'aincient_pages',
  ];

  /**
   * Check one module's source tree.
   *
   * @param string $modulePath
   *   The module's path, as \Drupal\Core\Extension\ModuleExtensionList gives it.
   * @param string $module
   *   The module's machine name.
   *
   * @return string[]
   *   Errors — empty when the pack ships no capabilities.
   */
  public static function check(string $modulePath, string $module): array {
    if (in_array($module, self::ATELIER_MODULES, TRUE)) {
      return [];
    }
    $dir = rtrim($modulePath, '/') . '/' . self::CAPABILITY_DIR;
    if (!is_dir($dir)) {
      return [];
    }
    return [sprintf(
      'ships %s/ — a pack may not add agent capabilities. Atelier\'s capability roster is a security floor, not an extension point (DECISIONS 0368): every shipped capability is reversible and internal, and the attachment taint + tool gate that a client-authored verb would require does not exist yet. A pack brings components, page kinds, providers and studios — a studio composes FlowDrop workflows over the capabilities Atelier ships. If you need a new verb, that is a conversation with AIncient Labs, not a directory.',
      self::CAPABILITY_DIR,
    )];
  }

}
