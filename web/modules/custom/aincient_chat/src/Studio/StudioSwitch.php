<?php

declare(strict_types=1);

namespace Drupal\aincient_chat\Studio;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * The studio ON/OFF switch: `aincient_chat.settings:disabled_studios`.
 *
 * A studio is switched off by CONFIG, never by uninstalling its module
 * (plans/studio-modules.md "On / off", DECISIONS 0430): uninstall deletes
 * config, including an owner's edited flows, so it stays a developer action.
 * The switch is a list of the studios that are OFF rather than a per-studio
 * `enabled` flag for one reason — absence means on, so every studio that
 * exists today, including the editor-only ones that have no row in the
 * `studios` agents map, keeps running with the config exactly as it is.
 *
 * Four consumers, one answer: the console shell (a disabled studio is absent
 * from the catalog and the access list, so it never renders), the per-studio
 * routes (403 via {@see \Drupal\aincient_chat\Access\StudioEnabledAccessCheck}),
 * the workflow catalog (its flows leave the switcher) and the capability gate
 * (its OWNED capabilities are refused at dispatch, so a turn in another studio
 * cannot call them). It is the ONLY off there is: the `features.checks_enabled`
 * release flag that once hid an unfinished studio UI-only was folded into it
 * (DECISIONS 0440) — a studio that ships off says `default_enabled: false` in
 * its manifest, which seeds this list at install.
 */
final class StudioSwitch {

  public const CONFIG = 'aincient_chat.settings';

  public const KEY = 'disabled_studios';

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * The studios that cannot be switched off.
   *
   * General is the landing workspace and the fallback every other read ends
   * in ({@see StudioManager::defaultId()}); a console with no studio to fall
   * back to has no answer to "where am I". Settings is the room the switches
   * themselves will live in (DECISIONS 0439): a console whose owner has
   * switched it off cannot recover from any other switch without a terminal,
   * and no site is better off without it. Whether a PERSON may open Settings
   * is the `use aincient studio settings` permission's question, not the
   * switch's — the switch removes studios a site does not need, and these two
   * are needed by every site.
   */
  public const ALWAYS_ON = [StudioManager::DEFAULT_ID, self::SETTINGS_ID];

  /**
   * The Settings studio's id (declared by `aincient_studio_site`).
   */
  public const SETTINGS_ID = 'settings';

  /**
   * Whether a studio is switched on. Unknown ids are "on": the switch only
   * ever subtracts, and whether a studio EXISTS is the manager's question.
   */
  public function isEnabled(string $id): bool {
    return in_array($id, self::ALWAYS_ON, TRUE) || !in_array($id, $this->disabled(), TRUE);
  }

  /**
   * The studio ids currently switched off.
   *
   * @return list<string>
   */
  public function disabled(): array {
    $raw = $this->configFactory->get(self::CONFIG)->get(self::KEY);
    return array_values(array_unique(array_map('strval', is_array($raw) ? $raw : [])));
  }

  /**
   * Switch a studio on or off, persisting the change.
   */
  public function setEnabled(string $id, bool $enabled): void {
    if (!$enabled && in_array($id, self::ALWAYS_ON, TRUE)) {
      throw new \InvalidArgumentException(sprintf('The %s studio cannot be switched off: %s.', $id, $id === self::SETTINGS_ID
        ? 'it is the room the switches live in — gate who may open it with the "use aincient studio settings" permission instead'
        : 'it is the console\'s landing workspace and fallback'));
    }
    $disabled = $this->disabled();
    $disabled = $enabled
      ? array_values(array_diff($disabled, [$id]))
      : array_values(array_unique([...$disabled, $id]));
    $this->configFactory->getEditable(self::CONFIG)->set(self::KEY, $disabled)->save();
  }

}
