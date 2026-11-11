<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Kernel;

use Drupal\aincient_pages\Controller\LockController;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Lock acquire rejects keys its row can't hold (langcode varchar 12, studio
 * varchar 32) with a 400 instead of a 500 from the INSERT (lean m1-3).
 *
 * @group aincient
 */
#[RunTestsInSeparateProcesses]
final class LockControllerInputTest extends KernelTestBase {

  protected static $modules = [
    'system',
    'user',
    'workflows', 'content_moderation', 'aincient_core', 'aincient_pages',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['workflows', 'content_moderation', 'aincient_pages']);
  }

  private function acquire(array $body): array {
    $request = Request::create('/atelier/page/lock/acquire', 'POST', content: json_encode($body));
    $response = LockController::create($this->container)->acquire($request);
    return [$response->getStatusCode(), json_decode((string) $response->getContent(), TRUE)];
  }

  public function testOverlongLangcodeIs400(): void {
    [$status, $body] = $this->acquire(['node_id' => '1', 'langcode' => 'abcdefghijklmnop']);
    $this->assertSame(400, $status);
    $this->assertSame('Expected a langcode.', $body['error']);
  }

  public function testNonAsciiLangcodeIs400(): void {
    [$status] = $this->acquire(['node_id' => '1', 'langcode' => "\u{85}Å"]);
    $this->assertSame(400, $status);
  }

  public function testOverlongStudioIs400(): void {
    [$status, $body] = $this->acquire(['node_id' => '1', 'studio' => str_repeat('content', 6)]);
    $this->assertSame(400, $status);
    $this->assertSame('Expected a studio id.', $body['error']);
  }

}
