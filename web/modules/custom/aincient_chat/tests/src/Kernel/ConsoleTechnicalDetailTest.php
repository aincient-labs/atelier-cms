<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_chat\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\aincient_chat\Controller\ConsoleController;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The console-wide technical-detail flag the work trail reads.
 *
 * An OPERATOR DEBUG knob with no UI: the console names outcomes, and the engine
 * view (wiring steps, node/node-type ids, router status frames) is opt-in via
 * config. What matters here is the default — a site owner must never be handed
 * the machinery by accident.
 *
 * @group aincient
 * @covers \Drupal\aincient_chat\Controller\ConsoleController
 */
#[RunTestsInSeparateProcesses]
final class ConsoleTechnicalDetailTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'node',
    'key',
    'aincient_core',
    'workflows',
    'content_moderation',
    'aincient_pages',
    'aincient_chat',
  ];

  /**
   * The reflected private flag of a container-resolved controller.
   */
  private function technicalDetail(): bool {
    $controller = $this->container->get('class_resolver')
      ->getInstanceFromDefinition(ConsoleController::class);
    $ref = new \ReflectionMethod($controller, 'technicalDetail');
    $ref->setAccessible(TRUE);
    return (bool) $ref->invoke($controller);
  }

  /**
   * Unset config means OFF — no engine detail on a plain install.
   */
  public function testDefaultsOff(): void {
    $this->assertFalse($this->technicalDetail());
  }

  /**
   * The operator can turn it on, and only config decides.
   */
  public function testConfigTurnsItOn(): void {
    $this->config('aincient_chat.settings')
      ->set('features.technical_detail', TRUE)
      ->save();
    $this->assertTrue($this->technicalDetail());

    $this->config('aincient_chat.settings')
      ->set('features.technical_detail', FALSE)
      ->save();
    $this->assertFalse($this->technicalDetail());
  }

}
