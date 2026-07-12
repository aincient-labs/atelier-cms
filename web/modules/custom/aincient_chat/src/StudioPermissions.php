<?php

declare(strict_types=1);

namespace Drupal\aincient_chat;

use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Mints one dynamic permission per specialised studio.
 *
 * The studio set is code-owned ({@see Studio}); rather than hand-list a
 * `use aincient studio <key>` permission per case in *.permissions.yml (which
 * would drift the moment a studio is added or renamed), we derive them from the
 * enum. Wired via `permission_callbacks` in `aincient_chat.permissions.yml`.
 *
 * General is intentionally absent — it is the open default landing studio, gated
 * only by `use aincient operator console` ({@see Studio::permission}).
 */
final class StudioPermissions {

  use StringTranslationTrait;

  /**
   * Builds the per-studio access permissions.
   *
   * @return array<string, array{title: \Drupal\Core\StringTranslation\TranslatableMarkup, description: \Drupal\Core\StringTranslation\TranslatableMarkup, restrict access: bool}>
   *   Keyed by permission name.
   */
  public function permissions(): array {
    $permissions = [];
    foreach (Studio::cases() as $studio) {
      $permission = $studio->permission();
      if ($permission === NULL) {
        // General — open to any console user, no dedicated permission.
        continue;
      }
      $permissions[$permission] = [
        'title' => $this->t('Use the @studio studio', ['@studio' => $studio->label()]),
        'description' => $this->t('Open the @studio workspace in the operator console. The studio switcher only shows studios the user can access; the matching save/load endpoints enforce the same permission.', ['@studio' => $studio->label()]),
        'restrict access' => TRUE,
      ];
    }
    return $permissions;
  }

}
