<?php

/**
 * @file
 * AIncient appliance settings — all state comes from the environment.
 *
 * The image ships no secrets and no DB credentials; the container injects them.
 * This is what lets the same image run anywhere and converge to the mounted DB.
 */

declare(strict_types=1);

// Database connection from DATABASE_URL, e.g. pgsql://user:pass@db:5432/aincient.
// The driver is taken from the URL scheme so the same image runs on Postgres
// (the shipped default) or MySQL/MariaDB without an image rebuild.
if ($url = getenv('DATABASE_URL')) {
  $p = parse_url($url);
  $scheme = $p['scheme'] ?? 'pgsql';
  // Normalise common scheme aliases onto the two core drivers we support.
  $driver = in_array($scheme, ['mysql', 'mysqli', 'mariadb'], TRUE) ? 'mysql' : 'pgsql';
  $databases['default']['default'] = [
    'driver' => $driver,
    'namespace' => "Drupal\\{$driver}\\Driver\\Database\\{$driver}",
    'autoload' => "core/modules/{$driver}/src/Driver/Database/{$driver}/",
    'database' => ltrim($p['path'] ?? '', '/'),
    'username' => $p['user'] ?? '',
    'password' => $p['pass'] ?? '',
    'host' => $p['host'] ?? 'db',
    'port' => (string) ($p['port'] ?? ($driver === 'mysql' ? 3306 : 5432)),
    'prefix' => '',
  ];
  // utf8mb4 collation is a MySQL/MariaDB concept; Postgres uses the DB encoding.
  if ($driver === 'mysql') {
    $databases['default']['default']['collation'] = 'utf8mb4_general_ci';
  }
}

$settings['hash_salt'] = getenv('HASH_SALT') ?: 'aincient-insecure-dev-salt';
$settings['config_sync_directory'] = '../config/sync';
$settings['file_private_path'] = '/opt/drupal/private';
$settings['update_free_access'] = FALSE;

// aincient_demo seeds throwaway showcase content (a branded homepage) and is
// deliberately kept OUT of the config-as-code baseline — converge enables it on
// fresh install, exactly as dev's settings.php excludes it from `cex`. Mirror
// that exclusion here so `config:import` on every appliance UPGRADE does NOT
// uninstall the demo just because it's absent from config/sync/core.extension.
// Without this, the first upgrade strips the demo homepage (and tripped the
// converge health gate). Listing not-installed modules is a harmless no-op.
$settings['config_exclude_modules'] = ['aincient_demo'];

// The appliance sits behind whatever proxy the operator fronts it with.
// Production deployments MUST scope the trusted hosts: set AINCIENT_TRUSTED_HOSTS
// to a comma-separated list of PHP regex patterns, e.g.
//   AINCIENT_TRUSTED_HOSTS='^cms\.example\.com$,^www\.example\.com$'
// When unset we fall back to accept-any so a first boot / bare evaluation still
// works — but we log a runtime warning so a production box is loud, not silently
// open to host-header attacks.
if ($trusted = getenv('AINCIENT_TRUSTED_HOSTS')) {
  $settings['trusted_host_patterns'] = array_values(array_filter(array_map('trim', explode(',', $trusted))));
}
else {
  $settings['trusted_host_patterns'] = ['.*'];
  // Surfaced in the webserver log and Drupal's logger on every request bootstrap.
  @trigger_error('AINCIENT_TRUSTED_HOSTS is not set — trusted_host_patterns accepts ANY host. Set it before exposing this site to the internet.', E_USER_WARNING);
}

// When fronted by a TLS-terminating reverse proxy (Traefik via Scotty, or any
// operator-supplied proxy), trust the X-Forwarded-* headers so Drupal generates
// https:// URLs and resolves real client IPs instead of the proxy's. Off by
// default — a direct-to-container deploy must NOT trust forwarded headers (they
// would be client-spoofable). Set AINCIENT_REVERSE_PROXY=1 to enable.
if (getenv('AINCIENT_REVERSE_PROXY')) {
  $settings['reverse_proxy'] = TRUE;
  // The proxy is the upstream peer; in a container network its address isn't
  // known ahead of time, so trust the immediate gateway. Override with
  // AINCIENT_REVERSE_PROXY_ADDRESSES (comma-separated) to pin it.
  if ($addrs = getenv('AINCIENT_REVERSE_PROXY_ADDRESSES')) {
    $settings['reverse_proxy_addresses'] = array_values(array_filter(array_map('trim', explode(',', $addrs))));
  }
}

if (file_exists($app_root . '/' . $site_path . '/services.yml')) {
  $settings['container_yamls'][] = $app_root . '/' . $site_path . '/services.yml';
}
