<?php

declare(strict_types=1);

namespace Drupal\aincient_flows\Drush\Commands;

use Consolidation\AnnotatedCommand\CommandResult;
use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\aincient_flows\Eval\BrandEvalAssertions;
use Drupal\aincient_flows\Eval\BrandEvalCase;
use Drupal\aincient_flows\Eval\BrandEvalFingerprint;
use Drupal\aincient_flows\Eval\BrandEvalRunner;
use Drupal\Core\Extension\ModuleExtensionList;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * `drush aincient:brand-eval` — the brand agent's live-turn regression net.
 *
 * Runs the case corpus in `aincient_flows/evals/brand/` as REAL `brand_studio`
 * turns (≈ 2–3 model calls each; needs a bound provider) and asserts on the
 * job trail. Not part of `phpunit-parallel`: run it before any brand-agent
 * deploy and after any change to a `flowdrop_workflow.*brand*` YAML or
 * BrandState — see apps/cms/docs/testing.md "Live-turn evals (brand)".
 *
 * Exit is non-zero on any failing case that is not marked `expected_fail`.
 * Marked cases report XFAIL (still broken, as documented) or XPASS (fixed —
 * drop the marker). The run is recorded in `evals/brand/.last-run` so a
 * build-time guard can ask "was the corpus run since the prompt changed?".
 */
final class BrandEvalCommands extends DrushCommands {

  public function __construct(
    private readonly BrandEvalRunner $runner,
    private readonly ModuleExtensionList $moduleList,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('aincient_flows.brand_eval_runner'),
      $container->get('extension.list.module'),
    );
  }

  /**
   * Run the brand agent's live-turn eval corpus (real model calls).
   */
  #[CLI\Command(name: 'aincient:brand-eval', aliases: ['abe'])]
  #[CLI\Argument(name: 'case', description: 'One case name (file stem) to run. Omit to run the whole corpus.')]
  #[CLI\Option(name: 'cases-dir', description: 'Directory of case YAMLs (default: aincient_flows/evals/brand).')]
  #[CLI\Option(name: 'delete-sessions', description: 'Delete the eval sessions afterwards (default: archive them, hidden from the console, kept for forensics).')]
  #[CLI\Option(name: 'verbose-assertions', description: 'Print every assertion, not only the failing ones.')]
  #[CLI\FieldLabels(labels: [
    'case' => 'Case',
    'verdict' => 'Verdict',
    'written' => 'Written',
    'contrast' => 'Contrast',
    'delegations' => 'Delegations',
    'model' => 'Model',
    'cost' => 'Cost $',
    'seconds' => 's',
    'pipeline' => 'Pipeline',
    'failed' => 'Failed assertions',
  ])]
  #[CLI\DefaultTableFields(fields: ['case', 'verdict', 'written', 'contrast', 'delegations', 'cost', 'seconds', 'pipeline', 'failed'])]
  #[CLI\Usage(name: 'ddev drush aincient:brand-eval', description: 'Run the whole corpus; non-zero exit on an unexpected failure.')]
  #[CLI\Usage(name: 'ddev drush aincient:brand-eval darker-from-swatch-after-hand-edit --verbose-assertions', description: 'One case, every assertion printed.')]
  public function brandEval(
    string $case = '',
    array $options = ['cases-dir' => '', 'delete-sessions' => FALSE, 'verbose-assertions' => FALSE, 'format' => 'table'],
  ): CommandResult|RowsOfFields {
    $dir = (string) ($options['cases-dir'] ?: $this->moduleList->getPath('aincient_flows') . '/evals/brand');
    $cases = BrandEvalCase::loadDirectory($dir);
    if ($case !== '') {
      // By case NAME (files carry an order prefix: `06-<name>.yml`).
      $cases = array_values(array_filter($cases, static fn (BrandEvalCase $c) => $c->name === $case || basename($c->file, '.yml') === $case));
    }
    if ($cases === []) {
      $this->logger()->error(dt('No case @case found in @dir.', ['@case' => $case !== '' ? "`$case`" : '', '@dir' => $dir]));
      return CommandResult::exitCode(self::EXIT_FAILURE);
    }

    $blocked = $this->runner->preflight();
    if ($blocked !== NULL) {
      $this->logger()->error($blocked);
      return CommandResult::exitCode(self::EXIT_FAILURE);
    }

    $rows = [];
    $unexpected = 0;
    $passes = 0;
    $totalCost = 0.0;
    foreach ($cases as $c) {
      $this->io()->writeln(sprintf('<comment>▶ %s</comment> — %s%s', $c->name, $c->ask, $c->issue ? " (cms #{$c->issue})" : ''));
      try {
        $run = $this->runner->run($c, (bool) $options['delete-sessions']);
      }
      catch (\Throwable $e) {
        $unexpected += $c->expectedFail ? 0 : 1;
        $rows[] = [
          'case' => $c->name,
          'verdict' => $c->expectedFail ? 'XFAIL' : 'ERROR',
          'written' => '',
          'contrast' => '',
          'delegations' => '',
          'model' => '',
          'cost' => '',
          'seconds' => '',
          'pipeline' => '',
          'failed' => get_class($e) . ': ' . $e->getMessage(),
        ];
        continue;
      }
      $o = $run['observed'];
      $results = BrandEvalAssertions::evaluate($c->expect, $o);
      $failed = array_values(array_filter($results, static fn (array $r) => !$r['pass']));
      $ok = $failed === [];

      $verdict = match (TRUE) {
        $ok && $c->expectedFail => 'XPASS',
        $ok => 'PASS',
        $c->expectedFail => 'XFAIL',
        default => 'FAIL',
      };
      if ($verdict === 'FAIL') {
        $unexpected++;
      }
      if ($ok) {
        $passes++;
      }
      $totalCost += (float) ($o['cost_usd'] ?? 0);

      foreach ($results as $r) {
        if (!$r['pass'] || $options['verbose-assertions']) {
          $this->io()->writeln(sprintf('   %s %s: expected %s, got %s',
            $r['pass'] ? '<info>✓</info>' : '<error>✗</error>', $r['key'], $r['expected'], $r['actual']));
        }
      }
      if (!$ok || $options['verbose-assertions']) {
        $this->io()->writeln('   prose: ' . str_replace("\n", ' ', (string) ($o['prose'] ?? '')));
      }

      $rows[] = [
        'case' => $c->name,
        'verdict' => $verdict,
        'written' => $this->written($o),
        'contrast' => $this->contrastSummary($o),
        'delegations' => sprintf('c%d s%d t%d', $o['delegations.colour'] ?? 0, $o['delegations.shape'] ?? 0, $o['delegations.typography'] ?? 0),
        'model' => (string) ($o['model'] ?? ''),
        'cost' => number_format((float) ($o['cost_usd'] ?? 0), 4),
        'seconds' => (string) round($run['seconds'], 1),
        'pipeline' => (string) ($run['pipeline_id'] ?? '—'),
        'failed' => implode('; ', array_map(static fn (array $r) => $r['key'], $failed)),
      ];
    }

    if ($case === '' && !$options['cases-dir']) {
      // Only a FULL run of the shipped corpus is a record the guard may trust.
      $this->recordRun($dir, count($cases), $passes, $unexpected);
    }
    $this->io()->writeln(sprintf('%d case(s), %d green, %d unexpected failure(s), $%s.', count($cases), $passes, $unexpected, number_format($totalCost, 4)));
    if ($unexpected > 0) {
      $this->logger()->error(dt('@n case(s) failed that were expected to pass.', ['@n' => $unexpected]));
      return CommandResult::dataWithExitCode(new RowsOfFields($rows), self::EXIT_FAILURE);
    }
    return new RowsOfFields($rows);
  }

  /**
   * The colour-ish tokens the specialists wrote, compact.
   */
  private function written(array $o): string {
    $parts = [];
    foreach ($o as $k => $v) {
      if (str_starts_with($k, 'slice.') && !str_starts_with($k, 'slice.fonts')) {
        $parts[] = substr($k, 6) . '=' . $v;
      }
    }
    return implode("\n", array_slice($parts, 0, 6));
  }

  private function contrastSummary(array $o): string {
    $parts = [];
    foreach ($o as $k => $v) {
      if (str_starts_with($k, 'contrast.') && str_starts_with($k, 'contrast.brand_')) {
        $parts[] = substr($k, 9) . ' ' . $v . ':1';
      }
    }
    return implode("\n", $parts);
  }

  /**
   * `evals/brand/.last-run` — what a build-time guard compares against.
   */
  private function recordRun(string $dir, int $cases, int $passes, int $unexpected): void {
    $hash = trim((string) @shell_exec('git -C ' . escapeshellarg(DRUPAL_ROOT . '/..') . ' rev-parse HEAD 2>/dev/null'));
    $line = implode("\t", [
      date('c'),
      $hash !== '' ? $hash : 'unknown',
      "cases=$cases",
      "green=$passes",
      "unexpected=$unexpected",
      // What BrandEvalFreshnessGuardTest compares against: the agent's
      // behaviour-bearing sources as they were when this corpus ran.
      'fingerprint=' . BrandEvalFingerprint::compute(),
    ]) . "\n";
    @file_put_contents(rtrim($dir, '/') . '/.last-run', $line);
  }

}
