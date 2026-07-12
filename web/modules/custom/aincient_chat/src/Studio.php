<?php

declare(strict_types=1);

namespace Drupal\aincient_chat;

use Drupal\Core\Session\AccountInterface;

/**
 * The console's studios — the code-owned set of chat workspaces.
 *
 * The console is always in exactly one studio (default {@see self::General}).
 * A studio is a workspace: it scopes the conversation history, the new-chat
 * agent, and — for the specialised studios — a live-preview editor split-pane.
 *
 * The SET of studios is fixed in code because each studio needs bespoke
 * front-end editor/preview components ({@see chat-ui/src/studios.ts}); the
 * AGENTS behind each studio are admin-configured (a 1:N studio→workflow map in
 * `aincient_chat.settings`, see {@see \Drupal\aincient_chat\Chat\WorkflowCatalog}).
 *
 * Adding a studio (Menu, Homepage, …) = a new case here + its components and a
 * registry entry in the front-end + a config row. Nothing else enumerates the
 * set. The backed value is the shared key between this enum, the config map,
 * and the front-end registry.
 */
enum Studio: string {

  /**
   * The default, catch-all studio: full-width chat, no editor pane.
   *
   * Holds the general-purpose agents (operator, weather, …) and is where an
   * agent that maps to no studio lands.
   */
  case General = 'general';

  /**
   * The Design System studio: the live design-token (Foundations) editor +
   * preview split-pane. (Was "Brand" — renamed to its design-system layer; the
   * brand IDENTITY — logo/name/tagline — moved to the Globals studio.)
   */
  case DesignSystem = 'design_system';

  /**
   * The Globals studio: site-wide chrome, tabbed (Brand identity / Header /
   * Footer). An EDITOR-ONLY studio — it has no chat agent yet (deterministic
   * rails + live preview only), so it never appears in the server agent-catalog;
   * the front-end enables it from its editor components alone.
   */
  case Globals = 'globals';

  /**
   * The Content studio: the live page composer + preview split-pane (pages,
   * and later structured content). (Was "Page".)
   */
  case Content = 'content';

  /**
   * The Library studio: the reusable-ingredient shelf (media + global blocks,
   * one `block` media substrate). An EDITOR-ONLY studio like Globals — no chat
   * agent — and unlike every other it has no live preview either: its editor IS
   * a full-width browse canvas over the unified reference catalog. Ingredients
   * are authored elsewhere (uploads here; blocks in the Content studio).
   */
  case Library = 'library';

  /**
   * The Media studio: edit ONE image-media item — its non-AI editor rail (name,
   * alt text, replace file) beside a preview of the image. Reached by opening an
   * item from the Library shelf, not from the top nav. Editor-only in v1 (no chat
   * agent → surfaced from its editor components alone, like Globals/Library); the
   * OPTIONAL chat rail (Nano Banana image generation / editing) attaches here once
   * an image provider is configured (plan `plans/media-studio.md`, DECISIONS 0144).
   */
  case Media = 'media';

  /**
   * The Checks studio: read-only page health audits (SEO, meta tags, internal-
   * link integrity). UNLIKE the others it has no live preview — the panel shows
   * structured findings directly. Audit-only: the agent reports, never writes.
   */
  case Checks = 'checks';

  /**
   * The studio a fresh console session opens in.
   */
  public static function default(): self {
    return self::General;
  }

  /**
   * A human-readable label for admin forms (the front-end owns its own names).
   */
  public function label(): string {
    return match ($this) {
      self::General => 'General',
      self::DesignSystem => 'Design System',
      self::Globals => 'Globals',
      self::Content => 'Content',
      self::Library => 'Library',
      self::Media => 'Media',
      self::Checks => 'Checks',
    };
  }

  /**
   * All studio keys, in display order.
   *
   * @return list<string>
   */
  public static function keys(): array {
    return array_map(static fn(self $s): string => $s->value, self::cases());
  }

  /**
   * Resolve a (possibly stale) studio key to a case, or NULL if unknown.
   */
  public static function tryFromKey(?string $key): ?self {
    return $key === NULL ? NULL : self::tryFrom($key);
  }

  /**
   * The permission that gates entering this studio.
   *
   * Each SPECIALISED studio (Design System, Globals, Content, Checks) is gated
   * by its own `use aincient studio <key>` permission so a sitebuilder can grant
   * studio access per role on the normal permissions page. {@see General} is the
   * default landing workspace and is OPEN to anyone who can open the console
   * (`use aincient operator console`) — it returns NULL (no extra gate). The
   * permission set is minted dynamically from this enum
   * ({@see \Drupal\aincient_chat\StudioPermissions}) so it can never drift from
   * the studio cases.
   *
   * @return string|null
   *   The gating permission, or NULL when the studio needs no permission beyond
   *   console access.
   */
  public function permission(): ?string {
    return $this === self::General ? NULL : 'use aincient studio ' . $this->value;
  }

  /**
   * Whether the account may enter this studio.
   *
   * The single authoritative check — used by the console shell (to filter the
   * studio switcher) and mirrored by the per-studio HTTP routes (defence in
   * depth). General is always accessible; the rest require {@see self::permission}.
   */
  public function accessibleBy(AccountInterface $account): bool {
    $permission = $this->permission();
    return $permission === NULL || $account->hasPermission($permission);
  }

}
