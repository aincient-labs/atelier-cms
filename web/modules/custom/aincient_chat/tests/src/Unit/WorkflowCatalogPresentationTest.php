<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_chat\Unit;

use Drupal\aincient_chat\Chat\WorkflowCatalog;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the per-flow welcome-screen overrides served to the console.
 *
 * @coversDefaultClass \Drupal\aincient_chat\Chat\WorkflowCatalog
 * @group aincient
 */
final class WorkflowCatalogPresentationTest extends UnitTestCase {

  /**
   * Build a catalog whose config returns the given workflow_metadata.
   *
   * @param array<string, mixed> $metadata
   *   The stored workflow_metadata value.
   */
  private function catalogWithMetadata(array $metadata): WorkflowCatalog {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(
      static fn(string $key): mixed => $key === 'workflow_metadata' ? $metadata : NULL,
    );
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->with('aincient_chat.settings')->willReturn($config);

    return new WorkflowCatalog(
      $configFactory,
      $this->createMock(EntityTypeManagerInterface::class),
    );
  }

  /**
   * An unconfigured flow yields no overrides (console falls back to defaults).
   *
   * @covers ::presentation
   */
  public function testUnconfiguredFlowHasNoOverrides(): void {
    $catalog = $this->catalogWithMetadata([]);
    $this->assertSame([], $catalog->presentation('aincient_operator_agent_loop'));
  }

  /**
   * Only the keys an admin actually set are returned, camelCased.
   *
   * @covers ::presentation
   */
  public function testReturnsOnlySetKeys(): void {
    $catalog = $this->catalogWithMetadata([
      'aincient_operator_agent_loop' => [
        'welcome' => 'What shall we build?',
        'sample_asks' => ['Make a blog', 'Make a shop'],
      ],
    ]);

    $this->assertSame([
      'welcomeText' => 'What shall we build?',
      'sampleAsks' => ['Make a blog', 'Make a shop'],
    ], $catalog->presentation('aincient_operator_agent_loop'));
  }

  /**
   * A freeform-only flow reports the flag so the console drops the chips.
   *
   * @covers ::presentation
   */
  public function testFreeformOnlyFlag(): void {
    $catalog = $this->catalogWithMetadata([
      'aincient_brand_agent' => [
        'welcome' => 'Describe your brand',
        'freeform_only' => TRUE,
      ],
    ]);

    $this->assertSame([
      'welcomeText' => 'Describe your brand',
      'freeformOnly' => TRUE,
    ], $catalog->presentation('aincient_brand_agent'));
  }

  /**
   * Blank/whitespace-only strings are treated as unset.
   *
   * @covers ::presentation
   */
  public function testBlankStringsAreOmitted(): void {
    $catalog = $this->catalogWithMetadata([
      'aincient_brand_agent' => [
        'welcome' => '   ',
        'description' => '',
        'sample_asks' => [],
      ],
    ]);

    $this->assertSame([], $catalog->presentation('aincient_brand_agent'));
  }

}
