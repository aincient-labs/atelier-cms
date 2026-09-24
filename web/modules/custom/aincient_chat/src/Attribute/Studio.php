<?php

declare(strict_types=1);

namespace Drupal\aincient_chat\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;

/**
 * Declares one console studio — a chat/editor workspace the console can be in.
 *
 * WHY THIS EXISTS. The studio set used to be a PHP enum, which said out loud
 * that the set was ours and closed. It no longer is: a component pack may ship
 * a studio (plans/console-extension-point.md, DECISIONS 0424), so the set has
 * to be discoverable per install rather than compiled into one file. This is
 * the same move `aincient_core` made for capabilities
 * ({@see \Drupal\aincient_core\Attribute\Capability}), for the same reason, and
 * the attribute is deliberately the same size: a discovery key and the handful
 * of facts the server needs, nothing that belongs to the front end.
 *
 * PLUGIN IDS ARE A PUBLIC CONTRACT, AND APPEND-ONLY. The id is the studio KEY —
 * the shared word between this plugin, the `studios` map in
 * `aincient_chat.settings`, the `use aincient studio <id>` permission, the
 * front-end registry (`chat-ui/src/studios.ts`) and every stored console deep
 * link (`?page`/`?block`, memory/console-open-doc-url-reflection). Renaming one
 * silently breaks saved links and un-configures a studio. The built-in ids
 * (`general`, `design_system`, `globals`, `settings`, `components`, `content`,
 * `library`, `media`, `checks`) are frozen.
 *
 * A THIRD-PARTY STUDIO MUST PREFIX ITS ID WITH ITS MODULE NAME
 * (`acme_reports`, not `reports`): ids share one flat namespace, and the id
 * travels into a permission name and a URL value, so it stays a plain machine
 * name — no colon, unlike the capability ids. `atelier:pack-validate` grades
 * this.
 *
 * WHAT IS NOT HERE. No icon, no component names, no editor/preview wiring: the
 * browser half of a studio is registered in the front end, and duplicating it
 * server-side would give us two registries to keep in step. No `agent_backed`
 * either — whether a studio runs agents is answered by the admin's `studios`
 * config ({@see \Drupal\aincient_chat\Chat\WorkflowCatalog}), and a second
 * answer to the same question is how they drift.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Studio extends Plugin {

  /**
   * Constructs a Studio attribute.
   *
   * @param string $id
   *   The studio key. Frozen once shipped — see the class docblock.
   * @param string $label
   *   The admin-facing label (permissions page, settings form). The console's
   *   own display name lives in the front-end registry; these track it.
   * @param int $weight
   *   Display order, low first. Built-ins are spaced by 10 so a pack studio can
   *   sit between two of them without a renumber.
   * @param bool $open
   *   TRUE when the studio needs no permission beyond console access — the
   *   default landing workspace, and nothing else. Every other studio is gated
   *   by its own minted `use aincient studio <id>` permission
   *   ({@see \Drupal\aincient_chat\StudioPermissions}), so a pack studio gets
   *   a permission for free and cannot ship itself open by omission.
   */
  public function __construct(
    public readonly string $id,
    public readonly string $label,
    public readonly int $weight = 0,
    public readonly bool $open = FALSE,
  ) {}

}
