<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_core\Unit\Inference;

use Drupal\aincient_core\Inference\Adapter\LiveModelCatalog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\Gemini\Gemini;
use Symfony\AI\Platform\Bridge\Gemini\ModelCatalog as GeminiModelCatalog;
use Symfony\AI\Platform\Bridge\Mistral\Mistral;
use Symfony\AI\Platform\Bridge\Mistral\ModelCatalog as MistralModelCatalog;
use Symfony\AI\Platform\Bridge\OpenAi\Gpt;
use Symfony\AI\Platform\Bridge\OpenAi\ModelCatalog as OpenAiModelCatalog;
use Symfony\AI\Platform\Capability;

/**
 * The decorator that stops a stale vendor list from rejecting a live model.
 *
 * Every bridge ships a hand-maintained catalogue that is behind its vendor by the
 * time it reaches us, and our adapters offer whatever the credential can actually
 * see. Without this, binding a model the picker showed fails as
 * `ModelNotFoundException` on the first turn — which reads like a broken key.
 *
 * Run across all three bridges that use it, built exactly as the adapters build
 * it, so an adapter changing its construction without this test mirroring the
 * change shows up as a failure rather than as untested production code.
 */
#[CoversClass(LiveModelCatalog::class)]
final class LiveModelCatalogTest extends TestCase {

  /**
   * One case per bridge using the decorator.
   *
   * @return iterable<string, array{LiveModelCatalog, string, string, class-string}>
   *   Case name => [catalogue, an id the bridge knows, one it does not, the
   *   class that bridge's model client requires].
   */
  public static function cataloguesProvider(): iterable {
    yield 'gemini' => [
      new LiveModelCatalog(
        new GeminiModelCatalog(),
        static fn (string $n, array $o): Gemini => new Gemini($n, Capability::cases(), $o),
      ),
      'gemini-2.5-flash',
      'gemini-9.9-flash-from-the-future',
      Gemini::class,
    ];
    yield 'openai' => [
      new LiveModelCatalog(
        new OpenAiModelCatalog(),
        static fn (string $n, array $o): Gpt => new Gpt($n, Capability::cases(), $o),
      ),
      'gpt-4.1',
      'gpt-9.9-from-the-future',
      Gpt::class,
    ];
    yield 'mistral' => [
      new LiveModelCatalog(
        new MistralModelCatalog(),
        static fn (string $n, array $o): Mistral => new Mistral($n, Capability::cases(), $o),
      ),
      'mistral-large-latest',
      'mistral-enormous-from-the-future',
      Mistral::class,
    ];
  }

  /**
   * A model the bridge knows keeps the bridge's own metadata.
   *
   * @param \Drupal\aincient_core\Inference\Adapter\LiveModelCatalog $catalog
   *   The decorated catalogue.
   * @param string $known
   *   An id the bridge's static list carries.
   */
  #[DataProvider('cataloguesProvider')]
  public function testKnownModelComesFromTheBridgeCatalogue(LiveModelCatalog $catalog, string $known): void {
    $model = $catalog->getModel($known);

    $this->assertSame($known, $model->getName());
    $this->assertNotEmpty($model->getCapabilities());
  }

  /**
   * A model the vendor serves but the bridge has not listed yet still resolves.
   *
   * And it MUST resolve as the bridge's OWN subclass — every model client gates
   * on `instanceof`, so a plain `Model` would resolve and then find no client,
   * which is a stranger failure than the one being fixed.
   *
   * @param \Drupal\aincient_core\Inference\Adapter\LiveModelCatalog $catalog
   *   The decorated catalogue.
   * @param string $known
   *   Unused here; the provider supplies it for the sibling case.
   * @param string $unknown
   *   An id the bridge's static list does not carry.
   * @param string $expected
   *   The class the bridge's model client requires.
   */
  #[DataProvider('cataloguesProvider')]
  public function testUnknownModelResolvesAsTheBridgeClass(LiveModelCatalog $catalog, string $known, string $unknown, string $expected): void {
    $model = $catalog->getModel($unknown);

    $this->assertInstanceOf($expected, $model);
    $this->assertSame($unknown, $model->getName());
  }

  /**
   * Options carried on an unknown id are parsed, not baked into the model name.
   *
   * @param \Drupal\aincient_core\Inference\Adapter\LiveModelCatalog $catalog
   *   The decorated catalogue.
   * @param string $known
   *   Unused here.
   * @param string $unknown
   *   An id the bridge's static list does not carry.
   */
  #[DataProvider('cataloguesProvider')]
  public function testUnknownModelKeepsItsOptionsOutOfTheName(LiveModelCatalog $catalog, string $known, string $unknown): void {
    $model = $catalog->getModel($unknown . '?temperature=0.2');

    $this->assertSame($unknown, $model->getName());
    $this->assertSame(['temperature' => '0.2'], $model->getOptions());
  }

}
