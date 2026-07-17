<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_core\Unit;

use Drupal\aincient_core\ModelRecommendations;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Tests\UnitTestCase;

/**
 * Unit-tests the model/provider recommendation registry against the shipped
 * model-recommendations.yml.
 *
 * @group aincient
 * @covers \Drupal\aincient_core\ModelRecommendations
 */
final class ModelRecommendationsTest extends UnitTestCase {

  private ModelRecommendations $recommendations;

  protected function setUp(): void {
    parent::setUp();
    // Point getPath() at the real module root so the shipped YAML is exercised
    // (this also fails the build if the file stops parsing).
    // tests/src/Unit -> aincient_core.
    $moduleRoot = dirname(__DIR__, 3);
    $moduleList = $this->createMock(ModuleExtensionList::class);
    $moduleList->method('getPath')->with('aincient_core')->willReturn($moduleRoot);
    $this->recommendations = new ModelRecommendations($moduleList);
  }

  /**
   * A model is labelled by its longest matching needle, else "untested".
   */
  public function testLabelForModel(): void {
    // Recommended needles — the narrow backed set: Sonnet + Haiku.
    $this->assertSame('recommended', $this->recommendations->labelForModel('anthropic', 'claude-sonnet-4-20250101'));
    $this->assertSame('recommended', $this->recommendations->labelForModel('anthropic', 'claude-haiku-4'));
    $this->assertSame('recommended', $this->recommendations->labelForModel('nanobanana', 'gemini-2.5-flash-image'));

    // Tested needles — connectable but not actively backed.
    $this->assertSame('tested', $this->recommendations->labelForModel('anthropic', 'claude-opus-4'));
    $this->assertSame('tested', $this->recommendations->labelForModel('openai', 'gpt-4o'));
    $this->assertSame('tested', $this->recommendations->labelForModel('openai', 'gpt-4o-mini'));

    // Not-recommended.
    $this->assertSame('not-recommended', $this->recommendations->labelForModel('openai', 'gpt-3.5-turbo'));

    // Case-insensitive.
    $this->assertSame('recommended', $this->recommendations->labelForModel('anthropic', 'Claude-SONNET-4'));

    // No needle match, and unknown provider → untested.
    $this->assertSame('untested', $this->recommendations->labelForModel('anthropic', 'some-experimental-model'));
    $this->assertSame('untested', $this->recommendations->labelForModel('unknown_provider', 'anything'));
  }

  /**
   * Provider recommendation reads the providers map; unknown → ''.
   */
  public function testProviderRecommendation(): void {
    $this->assertSame('recommended', $this->recommendations->providerRecommendation('anthropic'));
    $this->assertSame('tested', $this->recommendations->providerRecommendation('openai'));
    $this->assertSame('', $this->recommendations->providerRecommendation('unknown_provider'));
  }

  /**
   * Rank orders recommended → tested → untested → not-recommended.
   */
  public function testRank(): void {
    $this->assertSame(0, $this->recommendations->rank('recommended'));
    $this->assertSame(1, $this->recommendations->rank('tested'));
    $this->assertSame(2, $this->recommendations->rank('untested'));
    $this->assertSame(3, $this->recommendations->rank('not-recommended'));
    // Unknown label sorts as untested.
    $this->assertSame(2, $this->recommendations->rank('bogus'));
  }

}
