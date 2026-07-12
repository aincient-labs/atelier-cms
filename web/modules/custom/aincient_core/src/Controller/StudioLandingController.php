<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Controller;

use Drupal\aincient_core\StudioSections;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * The Studio Backend landing (/admin) + legacy-path redirects.
 *
 * Replaces core's SystemController::overview via AdminLandingRouteSubscriber:
 * the default overview paints the whole admin menu tree, and an
 * administrator's `link to any page` permission short-circuits per-link
 * access checks — so it lists rooms aincient_deny has locked, and every one
 * of those links dry-fires a 403 (Law 12). This landing renders only the
 * curated sections (StudioSections — the same registry the sidebar uses),
 * access-filtered, plus the way back to the console.
 */
final class StudioLandingController extends ControllerBase {

  /**
   * The curated /admin landing.
   */
  public function landing(): array {
    $sections = [];
    foreach (StudioSections::sections() as $key => $section) {
      try {
        $url = Url::fromRoute($section['route']);
      }
      catch (\Throwable $e) {
        // Provider module uninstalled — the room doesn't exist; skip it.
        continue;
      }
      if (!$url->access()) {
        continue;
      }
      $sections[] = [
        'key' => $key,
        'label' => $section['label'],
        'description' => $section['description'],
        'url' => $url->toString(),
      ];
    }

    $console_url = NULL;
    try {
      $console = Url::fromRoute('aincient_chat.console');
      if ($console->access()) {
        $console_url = $console->toString();
      }
    }
    catch (\Throwable $e) {
      // aincient_chat uninstalled — no console card.
    }

    return [
      '#theme' => 'aincient_studio_landing',
      '#sections' => $sections,
      '#console_url' => $console_url,
      // Which cards render varies by what the account may reach.
      '#cache' => ['contexts' => ['user.permissions']],
    ];
  }

  /**
   * Redirects the retired /admin/people listing to the simple /admin/users
   * view (302 — deliberate, so the old room can come back without browsers
   * having pinned a 301).
   */
  public function peopleRedirect(): RedirectResponse {
    return new RedirectResponse(Url::fromRoute('view.aincient_users.page_1')->toString(), 302);
  }

}
