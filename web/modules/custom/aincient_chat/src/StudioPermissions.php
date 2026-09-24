<?php

declare(strict_types=1);

namespace Drupal\aincient_chat;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\aincient_chat\Studio\StudioManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Mints one dynamic permission per specialised studio.
 *
 * The studio set is discovered ({@see \Drupal\aincient_chat\Studio\StudioManager});
 * rather than hand-list a `use aincient studio <key>` permission per studio in
 * *.permissions.yml (which would drift the moment a studio is added, renamed —
 * or installed with a pack), we derive them from the plugin definitions. Wired
 * via `permission_callbacks` in `aincient_chat.permissions.yml`.
 *
 * This is why a pack studio gets a permission for free and cannot ship itself
 * ungated: the permission is derived from the id, and only a studio that
 * declares `open: TRUE` has none. General is the one such studio — the open
 * default landing workspace, gated only by `use aincient operator console`.
 */
final class StudioPermissions implements ContainerInjectionInterface {

  use StringTranslationTrait;

  public function __construct(
    private readonly StudioManager $studios,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self($container->get('plugin.manager.aincient.studios'));
  }

  /**
   * Builds the per-studio access permissions.
   *
   * @return array<string, array{title: \Drupal\Core\StringTranslation\TranslatableMarkup, description: \Drupal\Core\StringTranslation\TranslatableMarkup, restrict access: bool}>
   *   Keyed by permission name.
   */
  public function permissions(): array {
    $permissions = [];
    foreach ($this->studios->studios() as $studio) {
      $permission = $studio->permission();
      if ($permission === NULL) {
        // An open studio (General) — no dedicated permission to grant.
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
