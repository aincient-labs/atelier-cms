<?php

declare(strict_types=1);

namespace Drupal\aincient_chat\Studio;

use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * What a console studio is, server-side: a key, a label, an order, an access
 * gate.
 *
 * Deliberately behaviour-free. A studio plugin does not render, does not run an
 * agent and does not know what its editor pane looks like — the browser half
 * lives in the front-end registry (`chat-ui/src/studio-registry.tsx`) and the
 * agent half is admin config read by
 * {@see \Drupal\aincient_chat\Chat\WorkflowCatalog}. What the SERVER needs to
 * know about a studio is exactly this: whether it exists, what to call it on
 * the permissions page, where it sits in the switcher, and who may enter it.
 *
 * Keeping it this small is what makes the type safe to open to packs: a studio
 * plugin cannot do anything at discovery time, so the worst a malformed one can
 * do is appear in a list.
 */
interface StudioInterface extends PluginInspectionInterface {

  /**
   * The studio key — the plugin id, frozen once shipped.
   */
  public function id(): string;

  /**
   * The admin-facing label (permissions page, settings form).
   */
  public function label(): string;

  /**
   * Display order, low first.
   */
  public function weight(): int;

  /**
   * Whether the studio is open to anyone who can open the console.
   */
  public function isOpen(): bool;

  /**
   * The permission that gates entering this studio, or NULL when open.
   *
   * Derived from the id, never declared: `use aincient studio <id>`. Derivation
   * is what makes the permission set impossible to drift from the studio set
   * ({@see \Drupal\aincient_chat\StudioPermissions} mints from the same rule),
   * and what gives a pack studio its own permission without asking for one.
   */
  public function permission(): ?string;

  /**
   * Whether the account may enter this studio.
   *
   * The single authoritative check — used by the console shell (to filter the
   * studio switcher) and mirrored by the per-studio HTTP routes (defence in
   * depth).
   */
  public function accessibleBy(AccountInterface $account): bool;

}
