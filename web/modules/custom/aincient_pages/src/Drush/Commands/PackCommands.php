<?php

declare(strict_types=1);

namespace Drupal\aincient_pages\Drush\Commands;

use Consolidation\AnnotatedCommand\CommandResult;
use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\aincient_pages\Catalog\KindCheck;
use Drupal\aincient_pages\Catalog\PackValidator;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush commands for component packs (plans/byo-components.md W4 + §6.3).
 *
 * The client-CI seam: `pack-validate` runs the EXACT admission gate a boot
 * runs ({@see AdmissionGate} is pure static, one code path), so a pack fails
 * in the client's pipeline before an image ever ships; `kind-check` is the
 * dry run for catalog changes against live content — report only, a human
 * decides what happens to content. Both exit non-zero on findings (via
 * {@see CommandResult}, the drush pattern that keeps --format=json intact
 * alongside the exit code) so they gate a CI job as-is.
 */
final class PackCommands extends DrushCommands {

  public function __construct(
    private readonly PackValidator $packValidator,
    private readonly KindCheck $kindCheck,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('aincient_pages.pack_validator'),
      $container->get('aincient_pages.kind_check'),
    );
  }

  /**
   * Run the component admission gate over the installed SDC definitions.
   */
  #[CLI\Command(name: 'atelier:pack-validate', aliases: ['apv'])]
  #[CLI\Argument(name: 'module', description: 'Only check components provided by this module (a pack validating itself). Omit to check every atelier-carrying component on the site.')]
  #[CLI\FieldLabels(labels: [
    'component' => 'Component',
    'tier' => 'Tier',
    'provider' => 'Provider',
    'status' => 'Status',
    'messages' => 'Messages',
  ])]
  #[CLI\DefaultTableFields(fields: ['component', 'tier', 'provider', 'status', 'messages'])]
  #[CLI\Usage(name: 'drush atelier:pack-validate acme_pack --format=json', description: 'Machine-readable gate verdicts for one pack (what a client CI parses).')]
  public function packValidate(string $module = ''): CommandResult|RowsOfFields {
    // One source with the dev-mode HTTP endpoint: the shared PackValidator
    // runs the gate over ALL definitions (collisions are namespace-wide),
    // the W5 CSS lint, and — when a module is named — its atelier.pack.yml.
    $report = $this->packValidator->validate($module);
    $rows = $report['rows'];

    // The pack manifest verdict rides as pseudo-rows so --format=json carries
    // it and a table run shows it beside the components it governs.
    // Emitted even with NO manifest, because the pack-level checks that do not
    // need one (the capability fence) must never be a silent rejection.
    if ($report['pack'] !== NULL && ($report['pack']['found'] || $report['pack']['errors'] !== [] || $report['pack']['warnings'] !== [])) {
      $rows[] = [
        'component' => $report['pack']['found'] ? 'atelier.pack.yml' : 'pack',
        'tier' => '',
        'provider' => $module,
        'status' => $report['pack']['errors'] !== [] ? 'REJECTED' : ($report['pack']['warnings'] !== [] ? 'WARN' : 'OK'),
        'messages' => implode(' ', array_merge($report['pack']['errors'], $report['pack']['warnings'])),
      ];
    }

    if ($report['rejected'] > 0) {
      $this->logger()->error(dt('@count finding(s) REJECTED by the admission gate — a boot would exclude them from the catalog.', ['@count' => $report['rejected']]));
      return CommandResult::dataWithExitCode(new RowsOfFields($rows), self::EXIT_FAILURE);
    }
    $this->logger()->success(dt('@count row(s) checked — all admitted.', ['@count' => count($rows)]));
    return new RowsOfFields($rows);
  }

  /**
   * Dry-run the current catalog against every stored page (§6.3): report the
   * slots a kind/constraint change would orphan. Report only — never rewrites.
   */
  #[CLI\Command(name: 'atelier:kind-check', aliases: ['akc'])]
  #[CLI\FieldLabels(labels: [
    'nid' => 'Nid',
    'title' => 'Title',
    'langcode' => 'Lang',
    'status' => 'Status',
    'kind' => 'Kind',
    'slot' => 'Slot',
    'component' => 'Component',
    'impact' => 'Impact',
    'detail' => 'Detail',
  ])]
  #[CLI\DefaultTableFields(fields: ['nid', 'title', 'langcode', 'status', 'kind', 'slot', 'component', 'impact', 'detail'])]
  #[CLI\Usage(name: 'drush atelier:kind-check --format=json', description: 'Machine-readable impact report (the CI gate against a content snapshot).')]
  public function kindCheck(): CommandResult|RowsOfFields {
    $report = $this->kindCheck->run();
    $summary = dt('@pages pages checked, @impacts breaking impacts, @warnings catalog warnings.', [
      '@pages' => $report['pages'],
      '@impacts' => $report['impacts'],
      '@warnings' => $report['warnings'],
    ]);

    if ($report['impacts'] > 0) {
      $this->logger()->error(dt('BREAKING: @summary A human decides — nothing was rewritten.', ['@summary' => $summary]));
      return CommandResult::dataWithExitCode(new RowsOfFields($report['rows']), self::EXIT_FAILURE);
    }
    $this->logger()->success(dt('SAFE: @summary', ['@summary' => $summary]));
    return new RowsOfFields($report['rows']);
  }

}
