<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_core\Unit\Usage;

use Drupal\aincient_core\Usage\ModelPricing;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Yaml\Yaml;

/**
 * Pins the one behaviour the predecessor got wrong: what an unknown model costs.
 *
 * `ai_metering`'s estimator answers 0.0 and offers no way to tell that from a
 * genuinely free call. Everything here exists to make the three cases distinct —
 * a model we priced, a model we did not, and a model that is free on purpose —
 * because a $0.0475 turn recorded as $0.00 is what happens when they collapse
 * into one.
 *
 * @group aincient_core
 */
#[CoversClass(ModelPricing::class)]
final class ModelPricingTest extends UnitTestCase {

  /**
   * A priced model returns per-token rates converted from the stored per-Mtok.
   */
  public function testAPricedModelResolves(): void {
    $rate = $this->pricing()->rate('anthropic', 'claude-sonnet-5');

    $this->assertNotNull($rate);
    // $2 per million input is $0.000002 per token — the conversion this class
    // exists to keep in exactly one place.
    $this->assertEqualsWithDelta(0.000002, $rate['input'], 1.0E-12);
    $this->assertEqualsWithDelta(0.00001, $rate['output'], 1.0E-12);
    $this->assertEqualsWithDelta(0.0000002, $rate['cache_read'], 1.0E-12);
    $this->assertEqualsWithDelta(0.0000025, $rate['cache_write'], 1.0E-12);
    $this->assertFalse($rate['free']);
    // Provenance is part of the answer, not decoration: the bug was a stale
    // rate nobody could date.
    $this->assertNotSame('', $rate['source']);
    $this->assertNotSame('', $rate['checked']);
  }

  /**
   * A model we never priced resolves to NOTHING — not to someone else's rate.
   *
   * The estimator's last-resort lookup matched a model id across ANY provider,
   * which is how a text rate could stand in for an image model. Answering NULL
   * is the point: it is what lets every caller say "unpriced" out loud.
   */
  public function testAnUnknownModelHasNoRateRatherThanASubstituteOne(): void {
    $this->assertNull($this->pricing()->rate('openai', 'gpt-5.6-terra'));
    // Not even when a DIFFERENT provider prices the same model id.
    $this->assertNull($this->pricing()->rate('vertex', 'claude-sonnet-5'));
  }

  /**
   * A whole provider can be priced at once, which is how "free" is declared.
   */
  public function testAProviderWildcardCoversEveryModelItServes(): void {
    $pricing = $this->pricing();

    $rate = $pricing->rate('ollama', 'llama4:70b');
    $this->assertNotNull($rate, 'A wildcard entry did not cover an arbitrary model.');
    $this->assertTrue($rate['free']);
    // An exact entry still wins over the wildcard.
    $this->assertSame('anthropic:claude-sonnet-5', $pricing->rate('anthropic', 'claude-sonnet-5')['key']);
  }

  /**
   * The four token classes are billed at four different rates.
   *
   * Cache write especially: it is 1.25x input, not 1x, and pricing it as plain
   * input understated the dominant term of a first turn by ~25%. The estimator
   * had no write rate to read; owning the table is what removed that excuse.
   */
  public function testCostChargesReadsAndWritesAtTheirOwnRates(): void {
    $cost = $this->pricing()->cost('anthropic', 'claude-sonnet-5', 100, 200, 1000, 500);

    $this->assertEqualsWithDelta(0.0002, $cost['input'], 1.0E-12);
    $this->assertEqualsWithDelta(0.002, $cost['output'], 1.0E-12);
    $this->assertEqualsWithDelta(0.0002, $cost['cache_read'], 1.0E-12);
    // 500 * $2.50/Mtok, NOT 500 * $2.00/Mtok.
    $this->assertEqualsWithDelta(0.00125, $cost['cache_write'], 1.0E-12);
    $this->assertEqualsWithDelta(0.00365, $cost['total'], 1.0E-12);
    $this->assertSame([], $cost['unpriced']);
    $this->assertFalse($cost['free']);
  }

  /**
   * An unpriced model costs zero AND says which tokens it could not charge for.
   */
  public function testAnUnpricedModelReportsTheGapItLeft(): void {
    $cost = $this->pricing()->cost('openai', 'gpt-5.6-terra', 900, 100, 0, 0);

    $this->assertSame(0.0, $cost['total']);
    $this->assertFalse($cost['free']);
    // Only the classes that actually carried tokens — a call that used no cache
    // must not be nagged about a missing cache rate.
    $this->assertSame(['input', 'output'], $cost['unpriced']);
  }

  /**
   * A partially priced model is loud about exactly the part that is missing.
   *
   * Google publishes no cache rate for the image model, so the entry omits one
   * rather than writing 0.0. A cached token there is an unknown cost, and the
   * difference between "unknown" and "free" is the entire subject of this class.
   */
  public function testAMissingRateOnOneClassIsReportedAlone(): void {
    $cost = $this->pricing()->cost('nanobanana', 'gemini-2.5-flash-image', 20, 1290, 40, 0);

    $this->assertSame(['cache_read'], $cost['unpriced']);
    // Input and output still bill: a gap in one class does not void the row.
    $this->assertEqualsWithDelta(0.038706, $cost['total'], 1.0E-9);
  }

  /**
   * A local model is free ON PURPOSE, and that zero raises nothing.
   *
   * The one silent zero the system allows. Without this case the loud-zero
   * warning would fire on every Ollama call and be muted within a day.
   */
  public function testADeliberatelyFreeProviderCostsNothingAndReportsNoGap(): void {
    $cost = $this->pricing()->cost('ollama', 'llama4:70b', 5000, 900, 0, 0);

    $this->assertSame(0.0, $cost['total']);
    $this->assertTrue($cost['free']);
    $this->assertSame([], $cost['unpriced']);
  }

  /**
   * Bound-but-unpriced is computed once, for both places that report it.
   */
  public function testUnpricedListsBoundRolesOnly(): void {
    $unpriced = $this->pricing()->unpriced([
      'reasoning' => ['provider_id' => 'anthropic', 'model_id' => 'claude-sonnet-5'],
      'task' => ['provider_id' => 'openai', 'model_id' => 'gpt-5.6-terra'],
      // Unbound: a role with no model is a configuration state, not a gap.
      'fast' => ['provider_id' => '', 'model_id' => ''],
      'image' => ['provider_id' => 'nanobanana', 'model_id' => 'gemini-2.5-flash-image'],
    ]);

    $this->assertSame(['task' => 'openai:gpt-5.6-terra'], $unpriced);
  }

  /**
   * The rates Atelier actually SHIPS are the ones this test claims they are.
   *
   * Reads the real `config/install` file, because every other test here runs on
   * a fixture and would happily pass with a typo in the shipped table. The two
   * anthropic rates are spot-checked against the published price sheet: haiku
   * 4.5 is $1/$5 per Mtok — `ai_metering.settings` shipped $0.25/$1.25, which
   * are Haiku *3.5* rates and 4x too low, and that is the entry this replaces.
   */
  public function testTheShippedTableCarriesTheRatesWeVerified(): void {
    // `model-pricing.yml`, not `config/install`: the shipped rates are
    // SUGGESTIONS now and the config object ships empty (DECISIONS 0304). This
    // test follows them, because what it guards — that the figures we publish
    // are the ones we verified — did not move.
    $entries = Yaml::parseFile(
      dirname(__DIR__, 4) . '/model-pricing.yml',
    )['models'];
    $shipped = [];
    foreach ($entries as $entry) {
      $shipped[$entry['provider'] . ':' . $entry['model']] = $entry;
    }

    $this->assertSame(1.0, $shipped['anthropic:claude-haiku-4-5-20251001']['input_per_mtok']);
    $this->assertSame(5.0, $shipped['anthropic:claude-haiku-4-5-20251001']['output_per_mtok']);
    $this->assertSame(2.0, $shipped['anthropic:claude-sonnet-5']['input_per_mtok']);
    $this->assertSame(10.0, $shipped['anthropic:claude-sonnet-5']['output_per_mtok']);
    // The image model's output is the IMAGE-token rate ($30/Mtok), not the
    // $2.50 text rate the LiteLLM snapshot carries for the same model id.
    $this->assertSame(30.0, $shipped['nanobanana:gemini-2.5-flash-image']['output_per_mtok']);

    // Every entry has to say where it came from and when — a rate without
    // provenance is the stale rate nobody could question.
    foreach ($shipped as $key => $entry) {
      $this->assertNotEmpty($entry['source'] ?? '', "$key ships without a source.");
      $this->assertNotEmpty($entry['checked'] ?? '', "$key ships without a checked date.");
    }
  }

  /**
   * Pricing over a small fixture table with one of each kind of entry.
   */
  private function pricing(): ModelPricing {
    return new ModelPricing(
      $this->getConfigFactoryStub([
      ModelPricing::CONFIG => [
        'models' => [
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
          // Deliberately no cache rates — the vendor publishes none.
          [
            'provider' => 'nanobanana',
            'model' => 'gemini-2.5-flash-image',
            'input_per_mtok' => 0.3,
            'output_per_mtok' => 30.0,
            'source' => 'https://ai.google.dev/gemini-api/docs/pricing',
            'checked' => '2026-08-02',
          ],
          [
            'provider' => 'ollama',
            'model' => '*',
            'input_per_mtok' => 0.0,
            'output_per_mtok' => 0.0,
            'free' => TRUE,
            'source' => 'Local inference.',
            'checked' => '2026-08-02',
          ],
        ],
      ],
      ]),
      $this->noSuggestions(),
    );
  }


  /**
   * A module list that locates NO bundled suggestions.
   *
   * These tests assert over a fixture rate table, so the bundled
   * `model-pricing.yml` must not leak into the answer: a suggestion silently
   * standing in for a fixture entry would make an assertion pass for the wrong
   * reason. Pointing at a path with no such file is the whole stub — the loader
   * treats an unreadable bundle as "no suggestions", never as an error.
   */
  private function noSuggestions(): ModuleExtensionList {
    $list = $this->createMock(ModuleExtensionList::class);
    $list->method('getPath')->willReturn(__DIR__ . '/no-such-module');
    return $list;
  }

}
