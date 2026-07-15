<?php

declare(strict_types=1);

namespace Drupal\aincient_core;

use Drupal\Core\Url;

/**
 * The curated Studio Backend sections — the single source of truth.
 *
 * Consumed by BOTH the /admin landing (StudioLandingController) and the
 * backend theme's sidebar (_aincient_studio_backend_sections()), so the two
 * can never drift. Kept tiny on purpose: this is the whole backend an
 * operator sees outside the chat console. Add a route here to add a room —
 * and whitelist it against aincient_deny's DISALLOWED_PATHS.
 */
final class StudioSections {

  /**
   * Returns the sections keyed by icon key: route, label, description.
   */
  public static function sections(): array {
    return [
      'people' => [
        'route' => 'view.aincient_users.page_1',
        'label' => t('People'),
        'description' => t('The accounts that work here — invite, bless, or block them.'),
      ],
      'metering' => [
        'route' => 'ai_metering.dashboard',
        'label' => t('AI metering'),
        'description' => t('What the AI work costs, editor by editor.'),
      ],
      'metering_settings' => [
        'route' => 'ai_metering.settings',
        'label' => t('Metering settings'),
        'description' => t('Pricing sources, quotas, and alert thresholds.'),
      ],
      'mail' => [
        'route' => 'aincient_mail.settings',
        'label' => t('Mail delivery'),
        'description' => t('How the site sends transactional email — SMTP, an API provider, or log-only.'),
      ],
      'language' => [
        'route' => 'entity.configurable_language.collection',
        'label' => t('Languages'),
        'description' => t('The languages this site speaks — add, order, or set the default.'),
      ],
    ];
  }

  /**
   * The URL back to the chat console, or NULL when it isn't reachable.
   *
   * The single "← Console" affordance both /admin surfaces consume — the
   * backend sidebar (_aincient_studio_backend_sections' theme) and the /admin
   * landing card (StudioLandingController) — so the way home can't drift or
   * dry-fire a 403. NULL when aincient_chat is uninstalled or the current user
   * can't reach the console.
   */
  public static function consoleLink(): ?string {
    try {
      $console = Url::fromRoute('aincient_chat.console');
    }
    catch (\Throwable $e) {
      // aincient_chat uninstalled — no console link.
      return NULL;
    }
    return $console->access() ? $console->toString() : NULL;
  }

}
