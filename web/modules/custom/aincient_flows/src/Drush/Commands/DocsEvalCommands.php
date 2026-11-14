<?php

declare(strict_types=1);

namespace Drupal\aincient_flows\Drush\Commands;

use Consolidation\AnnotatedCommand\CommandResult;
use Drupal\aincient_flows\Eval\DocsEvalCase;
use Drupal\aincient_flows\Eval\DocsEvalRunner;
use Drupal\Core\Extension\ModuleExtensionList;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * `drush aincient:docs-eval` — does the General assistant answer from the docs?
 *
 * Runs `aincient_flows/evals/docs/*.yml` as REAL General-assistant turns (the
 * console path, runtime docs check included) and grades each answer with an
 * independent judge against whole docs pages. A case passes only when EVERY
 * run passes: it searched the docs, every claim is supported, an uncovered ask
 * says the docs don't cover it, and the case's own link/mention rules hold.
 * Real model calls — not part of phpunit-parallel. Run after any change to the
 * operator prompt, the docs tools, the verifier or the docs bundle.
 */
final class DocsEvalCommands extends DrushCommands {

  public function __construct(
    private readonly DocsEvalRunner $runner,
    private readonly ModuleExtensionList $moduleList,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('aincient_flows.docs_eval_runner'),
      $container->get('extension.list.module'),
    );
  }

  /**
   * Run the docs-answer eval corpus (real model calls).
   */
  #[CLI\Command(name: 'aincient:docs-eval', aliases: ['ade'])]
  #[CLI\Argument(name: 'case', description: 'One case name (file stem) to run. Omit to run the whole corpus.')]
  #[CLI\Option(name: 'runs', description: 'Runs per case; a case passes only if every run passes (default 1).')]
  #[CLI\Option(name: 'cases-dir', description: 'Directory of case YAMLs (default: aincient_flows/evals/docs).')]
  #[CLI\Option(name: 'delete-sessions', description: 'Delete the eval sessions afterwards (default: archive them, kept for forensics).')]
  #[CLI\Option(name: 'show-answers', description: 'Print every answer, not only failing ones.')]
  #[CLI\Usage(name: 'ddev drush aincient:docs-eval --runs=3', description: 'The whole corpus, three runs each.')]
  public function docsEval(
    string $case = '',
    array $options = ['runs' => 1, 'cases-dir' => '', 'delete-sessions' => FALSE, 'show-answers' => FALSE],
  ): CommandResult {
    $dir = (string) ($options['cases-dir'] ?: $this->moduleList->getPath('aincient_flows') . '/evals/docs');
    $cases = DocsEvalCase::loadDirectory($dir);
    if ($case !== '') {
      $cases = array_values(array_filter($cases, static fn(DocsEvalCase $c) => $c->name === $case || basename($c->file, '.yml') === $case));
    }
    if ($cases === []) {
      $this->logger()->error(dt('No case found in @dir.', ['@dir' => $dir]));
      return CommandResult::exitCode(self::EXIT_FAILURE);
    }
    $blocked = $this->runner->preflight();
    if ($blocked !== NULL) {
      $this->logger()->error($blocked);
      return CommandResult::exitCode(self::EXIT_FAILURE);
    }

    $runs = max(1, (int) $options['runs']);
    $casePasses = 0;
    $runTotal = 0;
    $runPasses = 0;
    $checks = ['supported' => 0, 'revised' => 0, 'error' => 0, 'skipped' => 0, 'none' => 0];
    $cost = 0.0;
    foreach ($cases as $c) {
      $this->io()->writeln(sprintf('<comment>▶ %s</comment> (%s) — %s', $c->name, $c->kind, $c->ask));
      $allPass = TRUE;
      for ($i = 1; $i <= $runs; $i++) {
        $runTotal++;
        try {
          $o = $this->runner->run($c, (bool) $options['delete-sessions']);
          $failed = DocsEvalRunner::failures($c, $o);
        }
        catch (\Throwable $e) {
          $o = ['answer' => '', 'draft' => '', 'docs_check' => 'none', 'removed' => [], 'cost_usd' => NULL, 'seconds' => 0, 'judge' => ['claims' => 0]];
          $failed = ['ERROR ' . get_class($e) . ': ' . $e->getMessage()];
        }
        $checks[$o['docs_check']] = ($checks[$o['docs_check']] ?? 0) + 1;
        $cost += (float) ($o['cost_usd'] ?? 0);
        $pass = $failed === [];
        $runPasses += $pass ? 1 : 0;
        $allPass = $allPass && $pass;
        $this->io()->writeln(sprintf('   run %d: %s · check %s%s · %d claims judged · $%s · %ss',
          $i,
          $pass ? '<info>PASS</info>' : '<error>FAIL</error>',
          $o['docs_check'],
          $o['removed'] === [] ? '' : ' (removed ' . count($o['removed']) . ')',
          (int) ($o['judge']['claims'] ?? 0),
          $o['cost_usd'] ?? '?',
          $o['seconds'],
        ));
        foreach ($failed as $f) {
          $this->io()->writeln('     ✗ ' . $f);
        }
        foreach ($o['removed'] as $r) {
          $this->io()->writeln('     − check removed: ' . $r);
        }
        if (!$pass || $options['show-answers']) {
          $this->io()->writeln('     answer: ' . str_replace("\n", "\n             ", trim((string) $o['answer'])));
        }
      }
      $casePasses += $allPass ? 1 : 0;
    }

    $this->io()->writeln('');
    $this->io()->writeln(sprintf('Cases %d/%d · runs %d/%d · check: %d supported, %d revised, %d error, %d not run · ≈ $%.3f',
      $casePasses, count($cases), $runPasses, $runTotal,
      $checks['supported'], $checks['revised'], $checks['error'], $checks['none'] + $checks['skipped'], $cost));
    return CommandResult::exitCode($casePasses === count($cases) ? self::EXIT_SUCCESS : self::EXIT_FAILURE);
  }

}
