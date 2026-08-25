<?php

declare(strict_types=1);

namespace Drupal\aincient_pages\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Site\Settings;

/**
 * Route requirement `_atelier_dev: 'TRUE'` — open ONLY in appliance dev mode.
 *
 * Dev mode is a deployment posture, not a permission: the appliance sets
 * `$settings['atelier_dev']` from the AINCIENT_DEV env var, which only the
 * `atelier pack dev` compose overlay ever sets. The gated routes are the pack
 * developer's ground-truth surface (catalog, gate, prompt manifest, gallery)
 * proxied by the `atelier mcp` stdio server — machine endpoints on a
 * localhost-only dev stack, deliberately session-less so a coding agent can
 * call them without Drupal credentials. The prod image never sets the flag,
 * so in production these routes 403 for everyone, admins included.
 *
 * SECURITY FLOOR: never derive this from config or state — a site setting a
 * server-side flag must not be able to open machine endpoints on a production
 * appliance. Settings are code/env only.
 */
final class AtelierDevAccessCheck implements AccessInterface {

  public function access(): AccessResultInterface {
    // setCacheMaxAge(0) — never let a cached allow outlive the flag; the
    // flag itself can't change within a container's life, but the access
    // result must not be shared into any persistent cache.
    return AccessResult::allowedIf((bool) Settings::get('atelier_dev', FALSE))
      ->setCacheMaxAge(0);
  }

}
