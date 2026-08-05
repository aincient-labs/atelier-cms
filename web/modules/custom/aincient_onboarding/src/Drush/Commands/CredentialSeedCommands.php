<?php

declare(strict_types=1);

namespace Drupal\aincient_onboarding\Drush\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\aincient_onboarding\ProviderConnector;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Applies the `ATELIER_DEFAULT_<PROVIDER>_…` credential seeds.
 *
 * Called by `docker/converge.sh` on every boot, and safe to run by hand on an
 * install that does not converge (a local checkout, a manual deployment). It is
 * a COMMAND rather than a hook on purpose: writing a secret should be something
 * a log line can point at, not a side effect of a cache rebuild — and a hook at
 * install time would miss a seed added to an existing deployment.
 *
 * Idempotent by design ({@see ProviderConnector::seedFromEnvironment()}): each
 * provider is seeded once, ever, so an operator's Disconnect is not undone by
 * the next restart.
 */
final class CredentialSeedCommands extends DrushCommands {

  public function __construct(
    private readonly ProviderConnector $connector,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('aincient_onboarding.provider_connector'),
    );
  }

  /**
   * Apply any provider credential seeds supplied by the environment.
   */
  #[CLI\Command(name: 'aincient:seed-credentials', aliases: ['asc'])]
  #[CLI\FieldLabels(labels: [
    'provider' => 'Provider',
    'result' => 'Result',
  ])]
  #[CLI\DefaultTableFields(fields: ['provider', 'result'])]
  #[CLI\Usage(
    name: 'ATELIER_DEFAULT_ANTHROPIC_API_KEY=sk-… drush aincient:seed-credentials',
    description: 'Store that key for Anthropic if the site has never been seeded for it.',
  )]
  public function seed(): RowsOfFields {
    $rows = [];
    foreach ($this->connector->seedFromEnvironment() as $providerId => $result) {
      $rows[] = ['provider' => $providerId, 'result' => $result];
    }

    if ($rows === []) {
      // Not a warning: no seed set is the ordinary case, and converge calls this
      // unconditionally.
      $this->logger()->notice('No ATELIER_DEFAULT_* credential seeds are set.');
    }

    return new RowsOfFields($rows);
  }

}
