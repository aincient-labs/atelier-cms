<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_studio_content\Kernel;

use Drupal\aincient_studio_content\Controller\PageController;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * A blank page title is a 400, not a 500 from the node's NOT NULL title column
 * (contract suite, lean m1-3). An absent title still defaults.
 *
 * @group aincient
 */
#[RunTestsInSeparateProcesses]
final class PageWriteInputTest extends KernelTestBase {

  protected static $modules = [
    'system',
    'user',
    'workflows', 'content_moderation', 'aincient_core', 'aincient_pages',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['workflows', 'content_moderation', 'aincient_pages']);
  }

  private function post(string $method, array $body): array {
    $request = Request::create('/atelier/page/' . $method, 'POST', content: json_encode($body));
    $response = PageController::create($this->container)->{$method}($request);
    return [$response->getStatusCode(), json_decode((string) $response->getContent(), TRUE)];
  }

  public static function blankTitles(): array {
    return [
      'empty' => [''],
      'whitespace' => ['   '],
      'false' => [FALSE],
    ];
  }

  #[DataProvider('blankTitles')]
  public function testSaveRejectsBlankTitle(mixed $title): void {
    [$status, $body] = $this->post('save', ['schema' => ['type' => 'landing', 'title' => $title]]);
    $this->assertSame(400, $status);
    $this->assertSame('A page needs a title.', $body['error']);
  }

  #[DataProvider('blankTitles')]
  public function testPublishRejectsBlankTitle(mixed $title): void {
    [$status, $body] = $this->post('publish', ['node_id' => '1', 'schema' => ['type' => 'landing', 'title' => $title]]);
    $this->assertSame(400, $status);
    $this->assertSame('A page needs a title.', $body['error']);
  }

}
