<?php

declare(strict_types=1);

namespace Drupal\aincient_export\Drush\Commands;

use Drupal\aincient_export\Exporter;
use Drupal\aincient_export\ExportOptions;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush seam onto the static exporter.
 *
 * This is what the `aincient` manager CLI/GUI shells into
 * (`docker compose exec app drush aincient:export …`) and what deploy
 * adapters will build on.
 */
final class ExportCommands extends DrushCommands {

  /**
   * Menu/system paths that legitimately point outside a static export.
   */
  private const DEFAULT_CHECK_IGNORE = [
    '/user',
    '/user/*',
    '/admin/*',
    '/atelier/*',
    '/search/*',
    '/rss.xml',
    // Console routes — fixed product surface, never part of a static export,
    // but agent-authored page content may legitimately link to them.
    '/start',
    '/start/*',
    '/studio',
    '/studio/*',
  ];

  public function __construct(
    private readonly Exporter $exporter,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('aincient_export.exporter'),
    );
  }

  /**
   * Export the public site as a static snapshot.
   */
  #[CLI\Command(name: 'aincient:export', aliases: ['aex'])]
  #[CLI\Option(name: 'out', description: 'Output directory for the static site. Defaults to <project root>/aincient-export.')]
  #[CLI\Option(name: 'base-url', description: 'Scheme + host to render against. Defaults to the bootstrapped request host (drush --uri).')]
  #[CLI\Option(name: 'zip', description: 'Also package a zip at this path (site/ + extras). Pass without value for <project root>/aincient-export.zip.')]
  #[CLI\Option(name: 'include-config', description: 'Add config/sync to the zip.')]
  #[CLI\Option(name: 'include-users', description: 'Add users.json (accounts without password hashes) to the zip.')]
  #[CLI\Option(name: 'skip-link-check', description: 'Skip the post-export link check.')]
  #[CLI\Option(name: 'check-ignore', description: 'Comma-separated fnmatch patterns the link check may ignore, replacing the defaults.')]
  #[CLI\Usage(name: 'drush aincient:export', description: 'Export to <project root>/aincient-export and link-check the result.')]
  #[CLI\Usage(name: 'drush aincient:export --zip --include-config --include-users', description: 'Full "own your data" zip: static site + config + users.')]
  public function export(array $options = [
    'out' => NULL,
    'base-url' => NULL,
    'zip' => NULL,
    'include-config' => FALSE,
    'include-users' => FALSE,
    'skip-link-check' => FALSE,
    'check-ignore' => NULL,
  ]): int {
    $project_root = dirname(DRUPAL_ROOT);
    $out = $options['out'] ?? $project_root . '/aincient-export';
    $base_url = $options['base-url'] ?? \Drupal::request()->getSchemeAndHttpHost();

    $zip_path = NULL;
    if ($options['zip'] !== NULL && $options['zip'] !== FALSE) {
      $zip_path = is_string($options['zip']) && $options['zip'] !== ''
        ? $options['zip']
        : $project_root . '/aincient-export.zip';
    }

    $check_ignore = $options['check-ignore'] !== NULL
      ? array_filter(array_map('trim', explode(',', (string) $options['check-ignore'])))
      : self::DEFAULT_CHECK_IGNORE;

    $result = $this->exporter->export(
      new ExportOptions(
        outDir: $out,
        baseUrl: $base_url,
        zipPath: $zip_path,
        includeConfig: (bool) $options['include-config'],
        includeUsers: (bool) $options['include-users'],
        runLinkCheck: !$options['skip-link-check'],
        checkIgnore: $check_ignore,
      ),
      fn (string $message) => $this->io()->text($message),
    );

    $this->io()->success(sprintf(
      '%d pages, %d assets exported to %s (%d derivatives warmed).',
      count($result->pages),
      $result->assetsCopied,
      $result->outDir,
      $result->derivativesWarmed,
    ));
    if ($result->zipPath !== NULL) {
      $this->io()->success(sprintf('Zip with %d files at %s.', $result->zipFiles, $result->zipPath));
    }

    foreach ($result->skipped as $path => $status) {
      $this->io()->warning(sprintf('Skipped %s (HTTP %d).', $path, $status));
    }
    foreach ($result->failures as $path => $error) {
      $this->io()->error(sprintf('Failed %s: %s', $path, $error));
    }
    foreach ($result->brokenLinks as $broken) {
      $this->io()->error(sprintf('Broken reference in %s: %s', $broken['file'], $broken['ref']));
    }

    return $result->ok() ? self::EXIT_SUCCESS : self::EXIT_FAILURE;
  }

}
