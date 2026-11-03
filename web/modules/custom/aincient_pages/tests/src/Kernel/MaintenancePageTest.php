<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Maintenance mode renders the site-branded card, not core's bare stack.
 *
 * Core's template printed the theme logo (a file that is not the site's logo)
 * and nothing styled it. aincient_theme now ships maintenance-page.html.twig,
 * the same site-owned card as sign-in (DECISIONS 0422).
 *
 * @group aincient
 */
#[RunTestsInSeparateProcesses]
final class MaintenancePageTest extends KernelTestBase {

  protected static $modules = [
    'system', 'field', 'text', 'file', 'image', 'media', 'user', 'workflows', 'content_moderation', 'aincient_core', 'aincient_pages',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installConfig(['field', 'media', 'workflows', 'content_moderation', 'aincient_pages']);
    $this->container->get('theme_installer')->install(['aincient_theme']);
    $this->config('system.theme')->set('default', 'aincient_theme')->save();
  }

  public function testMaintenancePageWearsTheSiteIdentity(): void {
    $this->container->get('aincient_pages.site_identity')->update(['name' => 'Northwind Porto', 'tagline' => 'A hotel on the Douro']);
    $theme = $this->container->get('theme.initialization')->initTheme('aincient_theme');
    $this->container->get('theme.manager')->setActiveTheme($theme);

    $response = $this->container->get('bare_html_page_renderer')->renderBarePage(
      ['#markup' => 'Back shortly.'],
      'Site under maintenance',
      'maintenance_page',
    );
    $html = (string) $response->getContent();

    $this->assertStringContainsString('ain-auth-card', $html);
    $this->assertStringContainsString('<h1 class="ain-auth-heading">Site under maintenance</h1>', $html);
    $this->assertStringContainsString('Northwind Porto', $html);
    $this->assertStringContainsString('A hotel on the Douro', $html);
    $this->assertStringContainsString('Back shortly.', $html);
    // Core's bare template linked the theme logo; the site card never does.
    $this->assertStringNotContainsString('rel="home"', $html);
  }

}
