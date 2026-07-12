<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Kernel;

use Drupal\aincient_pages\ChromePreviewApplier;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the chrome-preview applier: validation + the chrome_preview envelope.
 *
 * The chrome parallel of the brand applier — the single source of truth that
 * turns a preview_chrome slice (identity_json / layout_json / reset) into the
 * validated `chrome_preview` widget envelope the Globals studio applies.
 *
 * @group aincient
 */
#[RunTestsInSeparateProcesses]
final class ChromePreviewApplierTest extends KernelTestBase {

  protected static $modules = [
    'system',
    'user',
    'link',
    'menu_link_content',
    'workflows', 'content_moderation', 'aincient_pages',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['workflows', 'content_moderation', 'aincient_pages']);
  }

  private function applier(): ChromePreviewApplier {
    return $this->container->get('aincient_pages.chrome_preview_applier');
  }

  public function testIdentityAndLayoutEnvelope(): void {
    $env = $this->applier()->apply([
      'identity_json' => json_encode([
        'name' => 'Lumen',
        'tagline' => 'Light, organised',
        'imagery_style' => 'soft natural light',
        'footer_note' => '© 2026 Lumen',
      ]),
      'layout_json' => json_encode([
        'header' => ['logo_position' => 'center', 'sticky' => FALSE],
        'footer' => ['layout' => 'stacked'],
      ]),
    ]);

    $this->assertSame('chrome_preview', $env['__widget__']);
    $payload = $env['payload'];
    // Identity guidelines + footer note passed through (whitelisted keys only).
    $this->assertSame('Lumen', $payload['identity']['guidelines']['name']);
    $this->assertSame('Light, organised', $payload['identity']['guidelines']['tagline']);
    $this->assertSame('soft natural light', $payload['identity']['guidelines']['imagery_style']);
    $this->assertSame('© 2026 Lumen', $payload['identity']['footer_note']);
    // Layout coerced against the registry (bool stays bool, enum stays string),
    // and ONLY the keys the agent sent appear (a true partial).
    $this->assertSame('center', $payload['layout']['header']['logo_position']);
    $this->assertFalse($payload['layout']['header']['sticky']);
    $this->assertSame('stacked', $payload['layout']['footer']['layout']);
    $this->assertArrayNotHasKey('nav_alignment', $payload['layout']['header']);
    $this->assertSame([], $payload['rejected']);
    $this->assertFalse($payload['reset']);
  }

  public function testRejectsUnknownKeysAndInvalidValues(): void {
    $env = $this->applier()->apply([
      'identity_json' => json_encode(['name' => 'Ok', 'colour' => 'blue']),
      'layout_json' => json_encode([
        'header' => ['logo_position' => 'diagonal', 'bogus' => 1],
        'sidebar' => ['x' => 'y'],
      ]),
    ]);

    $payload = $env['payload'];
    // The valid identity field survived.
    $this->assertSame('Ok', $payload['identity']['guidelines']['name']);
    // Unknown identity key, invalid enum value, unknown setting, unknown section
    // are all rejected (and absent from the applied payload).
    $this->assertContains('identity.colour', $payload['rejected']);
    $this->assertContains('header.logo_position', $payload['rejected']);
    $this->assertContains('header.bogus', $payload['rejected']);
    $this->assertContains('layout.sidebar', $payload['rejected']);
    $this->assertArrayNotHasKey('logo_position', $payload['layout']['header'] ?? []);
  }

  public function testReset(): void {
    $env = $this->applier()->apply(['reset' => TRUE]);
    $this->assertSame('chrome_preview', $env['__widget__']);
    $this->assertTrue($env['payload']['reset']);
    $this->assertStringContainsString('Reverted', $env['summary']);
  }

  public function testNothingToApplyIsAnError(): void {
    $env = $this->applier()->apply([]);
    $this->assertArrayHasKey('error', $env);
    $this->assertArrayNotHasKey('__widget__', $env);
  }

  public function testMalformedJsonIsAnError(): void {
    $this->assertArrayHasKey('error', $this->applier()->apply(['identity_json' => 'not json']));
    $this->assertArrayHasKey('error', $this->applier()->apply(['layout_json' => '[1,2,3]']));
  }

}
