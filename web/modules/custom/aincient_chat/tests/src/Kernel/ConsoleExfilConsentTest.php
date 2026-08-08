<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_chat\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\aincient_chat\Controller\ConsoleController;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The two attachment-safety affordances the console shell carries.
 *
 * 1. A Content-Security-Policy that cuts the exfil channel (DECISIONS 0347): an
 *    attachment can carry instructions the model transcribes, so assistant output
 *    is untrusted — `img-src`/`connect-src` are pinned to 'self' so a remote
 *    `<img>` or fetch in that output cannot beacon to a third party.
 * 2. The provider name the composer consent line uses, resolved from the VISION
 *    role (where the image bytes go), NULL when nothing is bound.
 *
 * @group aincient
 * @covers \Drupal\aincient_chat\Controller\ConsoleController
 */
#[RunTestsInSeparateProcesses]
final class ConsoleExfilConsentTest extends KernelTestBase {

  use UserCreationTrait;

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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('user_role');
    $this->installSchema('user', ['users_data']);
    $this->installConfig(['user']);
    // A signed-in operator, so the shell renders as it would in the console.
    $account = $this->createUser();
    $this->setCurrentUser($account);
  }

  /**
   * A container-resolved controller (its private helpers reflected as needed).
   */
  private function controller(): ConsoleController {
    return $this->container->get('class_resolver')
      ->getInstanceFromDefinition(ConsoleController::class);
  }

  /**
   * The reflected `attachmentProviderName()` value.
   */
  private function providerName(): ?string {
    $ref = new \ReflectionMethod($this->controller(), 'attachmentProviderName');
    $ref->setAccessible(TRUE);
    return $ref->invoke($this->controller());
  }

  /**
   * The `window.aincientChat` settings the shell injects, decoded.
   *
   * @return array<string, mixed>
   */
  private function shellSettings(): array {
    $html = $this->controller()->app()->getContent();
    $this->assertMatchesRegularExpression('/window\.aincientChat = (\{.*\});/', (string) $html);
    preg_match('/window\.aincientChat = (\{.*\});/', (string) $html, $m);
    return json_decode($m[1], TRUE, flags: JSON_THROW_ON_ERROR);
  }

  /**
   * The CSP pins the two exfil vectors to 'self' — no third-party img/connect.
   */
  public function testCspCutsTheExfilChannel(): void {
    $csp = $this->controller()->app()->headers->get('Content-Security-Policy');
    $this->assertNotNull($csp, 'The console shell must carry a CSP.');
    $this->assertStringContainsString("default-src 'self'", $csp);
    $this->assertStringContainsString("img-src 'self' data: blob:", $csp);
    $this->assertStringContainsString("connect-src 'self'", $csp);
    $this->assertStringContainsString("object-src 'none'", $csp);
    // The exfil vectors must not be wildcarded open.
    $this->assertStringNotContainsString('img-src *', $csp);
    $this->assertStringNotContainsString('connect-src *', $csp);
  }

  /**
   * Nothing bound ⇒ NULL, and the consent line stays generic (client-side).
   */
  public function testProviderNameNullWhenUnbound(): void {
    $this->assertNull($this->providerName());
    $this->assertArrayHasKey('provider', $this->shellSettings());
    $this->assertNull($this->shellSettings()['provider']);
  }

  /**
   * A VISION binding names the real provider — the image's actual destination.
   */
  public function testProviderNameFromVisionBinding(): void {
    $this->config('aincient_core.model_roles')
      ->set('roles', ['vision' => ['provider_id' => 'anthropic', 'model_id' => 'claude-x']])
      ->save();
    $this->assertSame('Anthropic', $this->providerName());
    $this->assertSame('Anthropic', $this->shellSettings()['provider']);
  }

}
