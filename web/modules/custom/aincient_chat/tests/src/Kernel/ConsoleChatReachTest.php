<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_chat\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\aincient_chat\Controller\ConsoleController;
use Drupal\aincient_pages\ChromeRepository;
use Drupal\aincient_pages\SiteIdentity;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The chat-reachable field set the console injects for the hands-on marker.
 *
 * The marker's whole point is that it CANNOT drift from what the agent does: the
 * set is derived from the same whitelists the preview tools enforce
 * ({@see SiteIdentity::GUIDELINE_KEYS} + footer_note for identity prose,
 * {@see ChromeRepository::REGISTRY} for layout). This locks that derivation — add
 * a guideline/registry key and it must appear; the human-only fields must never.
 *
 * @group aincient
 * @covers \Drupal\aincient_chat\Controller\ConsoleController
 */
#[RunTestsInSeparateProcesses]
final class ConsoleChatReachTest extends KernelTestBase {

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
   * The reflected private reachable-set of a container-resolved controller.
   *
   * @return list<string>
   */
  private function chatReach(): array {
    $controller = $this->container->get('class_resolver')
      ->getInstanceFromDefinition(ConsoleController::class);
    $ref = new \ReflectionMethod($controller, 'chatReach');
    $ref->setAccessible(TRUE);
    return $ref->invoke($controller);
  }

  /**
   * Every whitelisted identity + layout key is reachable — derived, not typed.
   */
  public function testDerivedFromWhitelists(): void {
    $reach = $this->chatReach();

    foreach (SiteIdentity::GUIDELINE_KEYS as $key) {
      $this->assertContains('identity.' . $key, $reach);
    }
    // footer_note is whitelisted by ChromePreviewApplier alongside the guidelines.
    $this->assertContains('identity.footer_note', $reach);

    foreach (ChromeRepository::REGISTRY as $section => $settings) {
      foreach (array_keys($settings) as $key) {
        $this->assertContains('chrome.' . $section . '.' . $key, $reach);
      }
    }
  }

  /**
   * The human-only fields have no preview tool → never reachable → stay marked.
   */
  public function testHumanOnlyFieldsAreNotReachable(): void {
    $reach = $this->chatReach();
    foreach ([
      'identity.logo',
      'identity.favicon',
      'site.mail',
      'site.front',
      'site.page_403',
      'site.page_404',
      'menus.main',
      'menus.footer',
      'privacy.font_delivery',
    ] as $key) {
      $this->assertNotContains($key, $reach);
    }
  }

}
