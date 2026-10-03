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

  /**
   * One-line admin description (settings form, `atelier:studio-info`).
   */
  public function description(): string;

  /**
   * Tour / empty-state copy; empty when the studio declares none.
   */
  public function help(): string;

  /**
   * Whether a fresh install switches this studio ON.
   *
   * Seeds `aincient_chat.settings:disabled_studios` when the providing module
   * is installed; after that the config is the switch
   * ({@see \Drupal\aincient_chat\Studio\StudioSwitch}), never this flag.
   */
  public function defaultEnabled(): bool;

  /**
   * The console UI entry (`ui.entry`), relative to the providing module, or
   * NULL when the studio is chat-only (General, or a manifest with no entry).
   *
   * Compiled by the one console build into the studio's own lazy chunk; the
   * server never serves it. The manifest's `ui.name` / `ui.icon` are the
   * build's too and have no server read.
   */
  public function uiEntry(): ?string;

  /**
   * The pack studio's built browser module (`ui.script`), relative to the
   * providing module, or NULL for a built-in or chat-only studio.
   *
   * Served as a static file; the console `import()`s it and calls its
   * `mount(el, ctx)` (plans/console-extension-point.md Phase 4, DECISIONS 0448).
   * Mutually exclusive with {@see self::uiEntry()}.
   */
  public function uiScript(): ?string;

  /**
   * The pack studio's stylesheet (`ui.style`), relative to the providing
   * module, or NULL. Only ever set beside {@see self::uiScript()}.
   */
  public function uiStyle(): ?string;

  /**
   * The console's crumb name (`ui.name`, defaulting to the label).
   */
  public function uiName(): string;

  /**
   * The kit icon name (`ui.icon`), or NULL for the default chat glyph.
   */
  public function uiIcon(): ?string;

  /**
   * The `flowdrop_workflow` ids this studio ships in its `config/install`.
   *
   * @return list<string>
   */
  public function flows(): array;

  /**
   * The capability plugin ids this studio OWNS (`<provider>:<slug>`).
   *
   * A verb used by one studio lives in that studio and is refused server-side
   * while the studio is off; a verb used by more than one lives in core and is
   * never listed here (plans/studio-modules.md, capability ownership rule).
   *
   * @return list<string>
   */
  public function capabilities(): array;

  /**
   * The demo-content source directory, relative to the providing module, or
   * NULL when the studio ships none.
   */
  public function demoPath(): ?string;

}
