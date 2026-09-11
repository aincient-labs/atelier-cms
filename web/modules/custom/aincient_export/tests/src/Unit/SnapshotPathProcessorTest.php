<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_export\Unit;

use Drupal\aincient_export\PathProcessor\SnapshotPathProcessor;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * @coversDefaultClass \Drupal\aincient_export\PathProcessor\SnapshotPathProcessor
 * @group aincient_export
 */
final class SnapshotPathProcessorTest extends UnitTestCase {

  /**
   * @covers ::processInbound
   * @dataProvider provider
   */
  public function testProcessInbound(string $in, string $expectedPath, ?string $expectedFile): void {
    $request = Request::create($in);
    $out = (new SnapshotPathProcessor())->processInbound($in, $request);
    $this->assertSame($expectedPath, $out);
    $this->assertSame($expectedFile, $request->query->get(SnapshotPathProcessor::QUERY_KEY));
  }

  public static function provider(): array {
    return [
      'deep asset' => [
        '/atelier/snapshots/20260910-1102-autumn/sites/default/files/css/x.css',
        '/atelier/snapshots/20260910-1102-autumn',
        'sites/default/files/css/x.css',
      ],
      'one segment' => ['/atelier/snapshots/20260910-1102/robots.txt', '/atelier/snapshots/20260910-1102', 'robots.txt'],
      'nested page' => ['/atelier/snapshots/20260910-1102/rooms/index.html', '/atelier/snapshots/20260910-1102', 'rooms/index.html'],
      'snapshot root untouched' => ['/atelier/snapshots/20260910-1102', '/atelier/snapshots/20260910-1102', NULL],
      'trailing slash untouched (route normalizer redirects)' => ['/atelier/snapshots/20260910-1102/', '/atelier/snapshots/20260910-1102/', NULL],
      'list untouched' => ['/atelier/snapshots', '/atelier/snapshots', NULL],
      'actions untouched' => ['/atelier/snapshots/freeze', '/atelier/snapshots/freeze', NULL],
      'bad id untouched' => ['/atelier/snapshots/current/index.html', '/atelier/snapshots/current/index.html', NULL],
      'elsewhere untouched' => ['/rooms/index.html', '/rooms/index.html', NULL],
    ];
  }

  /**
   * @covers ::processInbound
   */
  public function testDoesNotOverrideAnExplicitFileQuery(): void {
    $request = Request::create('/atelier/snapshots/20260910-1102/a/b.css?file=given');
    $out = (new SnapshotPathProcessor())->processInbound('/atelier/snapshots/20260910-1102/a/b.css', $request);
    $this->assertSame('/atelier/snapshots/20260910-1102/a/b.css', $out);
    $this->assertSame('given', $request->query->get('file'));
  }

}
