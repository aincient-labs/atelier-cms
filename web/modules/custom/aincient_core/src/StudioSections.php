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
      // Atelier's own dashboard. It replaced a contrib metering dashboard that
      // has since been removed outright. The description changed with the page:
      // the headline breakdown is now the
      // CALL SITE (which part of Atelier spent the money), which is the question
      // an appliance with one administrator actually has and the one a
      // per-editor report cannot answer. Key kept as `metering` because it is
      // the icon key both the landing template and the sidebar switch on.
      'metering' => [
        'route' => 'aincient_core.usage',
        'label' => t('AI usage'),
        'description' => t('What the AI work costs, and which part of Atelier is spending it.'),
      ],
      // Atelier's rate sheet. It replaced a contrib metering settings form that
      // has since been removed outright — almost nothing on it could still
      // affect this site. The label says what this room IS: a read-only price
      // list, not a place to
      // configure metering. Key kept as `metering_settings` because it is the
      // icon key both the landing template and the sidebar switch on.
      'metering_settings' => [
        'route' => 'aincient_core.pricing',
        'label' => t('Model rates'),
        'description' => t('What each model costs per million tokens, and which roles are charged at it.'),
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
