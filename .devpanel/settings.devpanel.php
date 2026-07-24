<?php

/**
 * @file
 * DevPanel (Drupal Forge) settings overrides for Atelier.
 *
 * Included from settings.php (gated on DP_APP_ID) only when the site runs as a
 * DevPanel app — a normal atelier-cms clone never loads this. DevPanel provides
 * MySQL via the DB_* env vars below. Kept aligned with DevPanel's stock contract.
 */

use Symfony\Component\HttpFoundation\Request;

$databases['default']['default']['database'] = getenv('DB_NAME');
$databases['default']['default']['username'] = getenv('DB_USER');
$databases['default']['default']['password'] = getenv('DB_PASSWORD');
$databases['default']['default']['host'] = getenv('DB_HOST');
$databases['default']['default']['port'] = getenv('DB_PORT');
$databases['default']['default']['driver'] = getenv('DB_DRIVER');
$databases['default']['default']['isolation_level'] = 'READ COMMITTED';

if (empty($settings['hash_salt'])) {
  $settings['hash_salt'] = hash('sha256', serialize($databases));
}

// Atelier ships its config as code; config/sync sits beside the docroot.
$settings['config_sync_directory'] ??= '../config/sync';
$settings['file_private_path'] ??= $app_root . '/../private';
$realpath = realpath($settings['file_private_path']);
if (!empty($realpath)) {
  $settings['file_private_path'] = $realpath;
}

$settings['trusted_host_patterns'][] = getenv('DP_HOSTNAME') ?: '.*';
$settings['enable_html5_validation'] = FALSE;

// Trust reverse-proxy headers when running behind DevPanel's dev container proxy.
if (getenv('DRUPALFORGE_DEVCONTAINER') && isset($_SERVER['HTTP_X_FORWARDED_HOST'], $_SERVER['REMOTE_ADDR'])) {
  $settings['reverse_proxy'] = TRUE;
  $settings['reverse_proxy_addresses'] = [$_SERVER['REMOTE_ADDR']];
  $settings['reverse_proxy_trusted_headers'] =
    Request::HEADER_X_FORWARDED_HOST |
    Request::HEADER_X_FORWARDED_PROTO |
    Request::HEADER_X_FORWARDED_PORT;
}
