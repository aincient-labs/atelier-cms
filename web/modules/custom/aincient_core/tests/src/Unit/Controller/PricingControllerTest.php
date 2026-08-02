<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_core\Unit\Controller;

use Drupal\aincient_core\Controller\PricingController;
use Drupal\aincient_core\Usage\ModelPricing;
use Drupal\aincient_core\Usage\RateFreshness;
use Drupal\aincient_core\Usage\UnpricedNotice;
use Drupal\aincient_core\ModelRoleResolver;
use Drupal\aincient_core\ModelRoles;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Tests\UnitTestCase;

/**
 * The rate sheet's data pass: which rate each ROLE is charged at.
 *
 * Four things in that mapping can be wrong without looking wrong, which is why
 * the rows are assembled in a method of their own rather than inline in the
 * render pass:
 *
 * - the row ORDER, which is the page's entire claim to being comparable against
 *   the onboarding screen;
 * - a wildcard entry claiming a role that an exact entry prices — the table
 *   would then state a rate the call is not actually billed at;
 * - an absent rate arriving as a 0.0 — the exact collapse of "unpriced" into
 *   "free" that {@see ModelPricing} exists to end, undone in the last step
 *   before the operator reads it;
 * - an unbound role reading as anything other than unbound.
 *
 * @group aincient_core
 */
final class PricingControllerTest extends UnitTestCase {

  /**
   * The rate table as `aincient_core.pricing` actually ships it, trimmed.
   *
   * @return list<array<string, mixed>>
   *   Rate entries.
   */
  private static function models(): array {
    return [
      [
        'provider' => 'anthropic',
        'model' => 'claude-sonnet-5',
        'input_per_mtok' => 2.0,
        'output_per_mtok' => 10.0,
        'cache_read_per_mtok' => 0.2,
        'cache_write_per_mtok' => 2.5,
        'source' => 'https://platform.claude.com/docs/en/pricing',
        'checked' => '2026-08-02',
      ],
      [
        'provider' => 'nanobanana',
        'model' => 'gemini-2.5-flash-image',
        'input_per_mtok' => 0.3,
        'output_per_mtok' => 30.0,
      ],
      [
        'provider' => 'ollama',
        'model' => '*',
        'input_per_mtok' => 0.0,
        'output_per_mtok' => 0.0,
        'free' => TRUE,
      ],
      [
        'provider' => 'ollama',
        'model' => 'llama3',
        'input_per_mtok' => 0.5,
        'output_per_mtok' => 1.5,
      ],
    ];
  }

  /**
   * A controller reading the given role bindings against the shipped rates.
   *
   * @param array<string, mixed> $bindings
   *   The `roles` map as `aincient_core.model_roles` would hold it.
   */
  private function controller(array $bindings): PricingController {
    $configFactory = $this->getConfigFactoryStub([
      ModelPricing::CONFIG => ['models' => self::models()],
      ModelRoleResolver::CONFIG => ['roles' => $bindings],
    ]);
    $pricing = new ModelPricing($configFactory);

    // A fixed clock, on the date the fixture rates say they were checked, so the
    // freshness verdict here is always "current". This class asserts the ROLE →
    // RATE mapping; whether a rate has lapsed is {@see
    // \Drupal\Tests\aincient_core\Unit\Usage\RateFreshnessTest}'s subject, and
    // letting the wall clock leak in would make these assertions start failing on
    // a date nobody chose.
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(
      (int) (new \DateTimeImmutable('2026-08-02 12:00:00', new \DateTimeZone('UTC')))->format('U'),
    );

    return new PricingController(
      $configFactory,
      new ModelRoleResolver($configFactory, $this->createMock(ModuleHandlerInterface::class)),
      $pricing,
      new UnpricedNotice($pricing),
      new RateFreshness($configFactory, $time),
    );
  }

  /**
   * One row per role, in the order the onboarding screen shows them.
   *
   * THE PAGE'S WHOLE PREMISE. An operator is meant to read this table against
   * the wizard screen they configured the site on; a reordered or missing row
   * quietly stops it being that comparison while still looking like one.
   */
  public function testThereIsOneRowPerRoleInDisplayOrder(): void {
    $rows = $this->controller([])->roleRows();

    $this->assertSame(
      array_keys(ModelRoles::pickerDefinitions()),
      array_column($rows, 'role'),
    );
    $this->assertSame(
      ['High thinking', 'Task executor', 'Fast', 'Image description', 'Image generation'],
      array_column($rows, 'label'),
    );
  }

  /**
   * A bound role carries its model's rates and provenance verbatim.
   */
  public function testABoundRoleCarriesItsModelsRates(): void {
    $rows = $this->controller([
      'reasoning' => ['provider_id' => 'anthropic', 'model_id' => 'claude-sonnet-5'],
    ])->roleRows();

    $this->assertTrue($rows[0]['bound']);
    $this->assertTrue($rows[0]['priced']);
    $this->assertSame('claude-sonnet-5', $rows[0]['model']);
    // Per MILLION tokens — the unit the config stores and vendors publish in,
    // not the per-token value the cost arithmetic uses.
    $this->assertSame(2.0, $rows[0]['input']);
    $this->assertSame(2.5, $rows[0]['cache_write']);
    $this->assertSame('2026-08-02', $rows[0]['checked']);
    $this->assertFalse($rows[0]['free']);
  }

  /**
   * A rate the entry does not publish stays NULL — it must never read as zero.
   *
   * The whole reason Atelier owns this table: a missing rate and a free model
   * are different facts, and a page that renders both as $0 hides the first.
   */
  public function testAnUnpublishedRateIsNullNotZero(): void {
    $rows = $this->controller([
      'image' => ['provider_id' => 'nanobanana', 'model_id' => 'gemini-2.5-flash-image'],
    ])->roleRows();

    $image = end($rows);
    $this->assertTrue($image['priced']);
    $this->assertSame(0.3, $image['input']);
    $this->assertNull($image['cache_read']);
    $this->assertNull($image['cache_write']);
  }

  /**
   * A bound model with no entry at all is flagged, not silently zeroed.
   */
  public function testABoundModelWithNoEntryIsFlaggedUnpriced(): void {
    $rows = $this->controller([
      'task' => ['provider_id' => 'openai', 'model_id' => 'gpt-4o'],
    ])->roleRows();

    $this->assertTrue($rows[1]['bound']);
    $this->assertFalse($rows[1]['priced']);
    $this->assertNull($rows[1]['input']);
  }

  /**
   * An exact entry prices the role even when a wildcard could also match it.
   *
   * THE ATTRIBUTION TRAP. `ModelPricing::rate()` prefers the exact entry, so a
   * page showing `ollama:llama3` at the `ollama:*` free rate would be telling
   * the operator about a price that call is not billed at.
   */
  public function testAnExactEntryOutranksAWildcard(): void {
    $rows = $this->controller([
      'task' => ['provider_id' => 'ollama', 'model_id' => 'llama3'],
      'fast' => ['provider_id' => 'ollama', 'model_id' => 'mistral-small'],
    ])->roleRows();

    $this->assertSame(0.5, $rows[1]['input']);
    $this->assertFalse($rows[1]['free']);
    // Only the model with no entry of its own falls through to the wildcard.
    $this->assertSame(0.0, $rows[2]['input']);
    $this->assertTrue($rows[2]['free']);
  }

  /**
   * An unbound — or malformed — role reads as unconfigured, not as a fault.
   *
   * The normal state of a keyless install, and of both optional image roles on
   * a site that never connected an image provider.
   */
  public function testAnUnboundRoleIsUnconfigured(): void {
    $rows = $this->controller([
      'task' => ['provider_id' => '', 'model_id' => ''],
      'fast' => 'nonsense',
    ])->roleRows();

    foreach ($rows as $row) {
      $this->assertFalse($row['bound'], $row['role'] . ' should read as unbound');
      // Unbound is not unpriced: there is no model to have failed to price.
      $this->assertFalse($row['priced']);
    }
  }

  /**
   * Vision is reported on its EXPLICIT binding, not the fallback it would use.
   *
   * `resolve()` would answer the task model here, which is true of the CALL and
   * false of the CONFIGURATION — and this page reports configuration. An
   * operator shown an inherited model goes looking for a binding they never
   * made.
   */
  public function testVisionIsNotShownAsBoundByFallback(): void {
    $rows = $this->controller([
      'task' => ['provider_id' => 'anthropic', 'model_id' => 'claude-sonnet-5'],
    ])->roleRows();

    $byRole = array_column($rows, NULL, 'role');
    $this->assertTrue($byRole[ModelRoles::TASK]['bound']);
    $this->assertFalse($byRole[ModelRoles::VISION]['bound']);
  }

}
