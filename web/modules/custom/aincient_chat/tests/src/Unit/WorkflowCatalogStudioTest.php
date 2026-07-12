<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_chat\Unit;

use Drupal\aincient_chat\Chat\WorkflowCatalog;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the studio → agents resolution, bucketing, and run allowlist.
 *
 * @coversDefaultClass \Drupal\aincient_chat\Chat\WorkflowCatalog
 * @group aincient
 */
final class WorkflowCatalogStudioTest extends UnitTestCase {

  /**
   * A catalog over a studios config + the workflow ids that actually exist.
   *
   * @param array<string, mixed> $studios
   *   The stored `studios` config.
   * @param list<string> $existingIds
   *   The flowdrop_workflow entity ids present in storage.
   */
  private function catalog(array $studios, array $existingIds, string $defaultStudio = 'general'): WorkflowCatalog {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(
      static fn(string $key): mixed => match ($key) {
        'studios' => $studios,
        'default_studio' => $defaultStudio,
        default => NULL,
      },
    );
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->with('aincient_chat.settings')->willReturn($config);

    $entities = [];
    foreach ($existingIds as $id) {
      $entity = $this->createMock(EntityInterface::class);
      $entity->method('id')->willReturn($id);
      $entity->method('label')->willReturn(ucfirst($id));
      $entities[$id] = $entity;
    }
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('loadMultiple')->willReturn($entities);

    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('hasDefinition')->with('flowdrop_workflow')->willReturn(TRUE);
    $etm->method('getStorage')->with('flowdrop_workflow')->willReturn($storage);

    return new WorkflowCatalog($configFactory, $etm);
  }

  /**
   * A representative two-studio config (general + design_system).
   *
   * Studio keys must be {@see \Drupal\aincient_chat\Studio} cases, since
   * studios() only considers enum keys.
   *
   * @return array<string, mixed>
   */
  private function sampleStudios(): array {
    return [
      'general' => ['agents' => ['op', 'weather'], 'default' => 'op'],
      'design_system' => ['agents' => ['brand_agent'], 'default' => 'brand_agent'],
    ];
  }

  /**
   * Unknown agents are dropped, and a studio left empty is disabled.
   *
   * @covers ::studios
   */
  public function testStudiosValidatesAgentsAndDisablesEmpty(): void {
    $studios = $this->sampleStudios();
    // 'content' maps only to a non-existent agent → it must be disabled.
    $studios['content'] = ['agents' => ['ghost'], 'default' => 'ghost'];
    // 'weather' does not exist → dropped from general, leaving just 'op'.
    $catalog = $this->catalog($studios, ['op', 'brand_agent']);

    $resolved = $catalog->studios();
    $this->assertSame(['general', 'design_system'], array_keys($resolved));
    $this->assertSame(['op'], array_keys($resolved['general']['agents']));
    $this->assertArrayNotHasKey('content', $resolved);
  }

  /**
   * A studio's default falls back to its first agent when invalid.
   *
   * @covers ::studios
   */
  public function testStudioDefaultFallsBackToFirstAgent(): void {
    $studios = ['general' => ['agents' => ['op', 'weather'], 'default' => 'gone']];
    $catalog = $this->catalog($studios, ['op', 'weather']);
    // 'op' sorts before 'weather' (asort by label "Op"/"Weather").
    $this->assertSame('op', $catalog->studios()['general']['default']);
  }

  /**
   * studioOf buckets an agent to its owning studio; unknown agents are NULL.
   *
   * @covers ::studioOf
   */
  public function testStudioOfBuckets(): void {
    $catalog = $this->catalog($this->sampleStudios(), ['op', 'weather', 'brand_agent']);
    $this->assertSame('general', $catalog->studioOf('op'));
    $this->assertSame('design_system', $catalog->studioOf('brand_agent'));
    $this->assertNull($catalog->studioOf('nope'));
  }

  /**
   * resolve() honours any studio's agent and falls back to the default.
   *
   * @covers ::resolve
   * @covers ::defaultWorkflow
   */
  public function testResolveAllowsUnionAndFallsBack(): void {
    $catalog = $this->catalog($this->sampleStudios(), ['op', 'weather', 'brand_agent']);
    // An agent of any studio is allowed through.
    $this->assertSame('brand_agent', $catalog->resolve('brand_agent'));
    $this->assertSame('weather', $catalog->resolve('weather'));
    // Unknown/stale/probing ids fall back to the default studio's default.
    $this->assertSame('op', $catalog->resolve('not-a-workflow'));
    $this->assertSame('op', $catalog->resolve(NULL));
    $this->assertSame('op', $catalog->defaultWorkflow());
  }

  /**
   * The default studio is the configured one when enabled, else the first.
   *
   * @covers ::defaultStudio
   */
  public function testDefaultStudio(): void {
    $catalog = $this->catalog($this->sampleStudios(), ['op', 'weather', 'brand_agent'], 'design_system');
    $this->assertSame('design_system', $catalog->defaultStudio());

    // A configured-but-disabled default studio falls back to the first enabled.
    $missing = $this->catalog($this->sampleStudios(), ['op', 'weather', 'brand_agent'], 'content');
    $this->assertSame('general', $missing->defaultStudio());
  }

}
