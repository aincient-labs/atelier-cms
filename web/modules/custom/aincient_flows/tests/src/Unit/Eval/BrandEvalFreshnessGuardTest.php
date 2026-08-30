<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_flows\Unit\Eval;

use Drupal\aincient_flows\Eval\BrandEvalFingerprint;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tripwire: the brand agent's prompts changed and the live corpus was not re-run.
 *
 * The 144-suite gate cannot see the orchestrator↔specialist loop; the live eval
 * can, but only when someone runs it. This test is the "you owe a step" alarm
 * (same posture as CapabilityRosterTaintGuardTest): it compares the content
 * fingerprint of the watched sources (BrandEvalFingerprint::WATCHED) with the
 * one `drush aincient:brand-eval` recorded in `evals/brand/.last-run`. No
 * Drupal bootstrap, no git — it reads files off disk, so a shallow CI checkout
 * and an amended commit both still trip it.
 *
 * To make it green: `ddev drush aincient:brand-eval` (real model calls, ≈ $0.75,
 * see docs/testing.md "Live-turn evals (brand)") and commit `.last-run`.
 */
#[Group('aincient_flows')]
final class BrandEvalFreshnessGuardTest extends UnitTestCase {

  public function testWatchedSourcesExist(): void {
    $files = BrandEvalFingerprint::watchedFiles();
    $this->assertNotEmpty($files);
    $this->assertContains('config/sync/flowdrop_workflow.flowdrop_workflow.brand_studio.yml', $files);
    $this->assertContains('web/modules/custom/aincient_flows/src/Plugin/FlowDropNodeProcessor/BrandState.php', $files);
    $this->assertGreaterThanOrEqual(3, count(preg_grep('#aincient_brand_specialist_#', $files)), 'the three specialist workflows');
    $this->assertGreaterThanOrEqual(9, count(preg_grep('#evals/brand/.*\.yml$#', $files)), 'the corpus');
  }

  public function testCorpusWasRunSinceTheAgentLastChanged(): void {
    $recorded = BrandEvalFingerprint::recorded();
    $this->assertNotNull($recorded, "No fingerprint in " . BrandEvalFingerprint::LAST_RUN . ". Run `ddev drush aincient:brand-eval` and commit the file.");
    $this->assertSame(
      $recorded,
      BrandEvalFingerprint::compute(),
      "The brand agent's behaviour-bearing sources (a brand workflow YAML, BrandState.php, or an eval case) "
      . "changed since the live corpus was last run. The unit gate cannot see the orchestrator↔specialist loop — "
      . "run `ddev drush aincient:brand-eval` (≈ $0.75, docs/testing.md → Live-turn evals) and commit "
      . BrandEvalFingerprint::LAST_RUN . ". Widen or narrow what is watched in BrandEvalFingerprint::WATCHED."
    );
  }

}
