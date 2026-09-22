<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * M1 showcase findings (2026-09): three component one-liner regressions
 * caught building real sites — cta's default-tone contrast, features'
 * missing card CTA, and unstyled `pre` blocks in the prose measure (the last
 * is a CSS-only fix, not covered here).
 *
 * @group aincient
 */
#[RunTestsInSeparateProcesses]
final class ComponentRenderTest extends KernelTestBase {

  protected static $modules = [
    'system',
    'user',
    'link',
    'menu_link_content',
    'workflows', 'content_moderation', 'aincient_core', 'aincient_pages',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['workflows', 'content_moderation', 'aincient_pages']);
  }

  /**
   * An unset `tone` must resolve to the brand band AND the on-dark button —
   * before the fix, tone_class defaulted to 'brand' while on_dark read the
   * raw (null) tone and picked the light-tone button, a 1.42:1 contrast
   * failure.
   */
  public function testCtaDefaultToneIsInternallyConsistent(): void {
    $catalog = $this->container->get('aincient_pages.catalog')->for('landing');
    $build = [
      '#type' => 'component',
      '#component' => $catalog->pluginId('cta'),
      '#props' => [
        'heading' => 'Fixture heading',
        'cta_label' => 'Get started',
        'cta_url' => '/start',
      ],
    ];
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    $this->assertStringContainsString('text-primary-foreground', $html, 'Section paints the brand band.');
    $this->assertStringContainsString('bg-background text-foreground', $html, 'Button uses the on-dark treatment.');
  }

  /**
   * A features card with cta_label + cta_url renders a link.
   */
  public function testFeaturesCardRendersCtaWhenPresent(): void {
    $catalog = $this->container->get('aincient_pages.catalog')->for('landing');
    $build = [
      '#type' => 'component',
      '#component' => $catalog->pluginId('features'),
      '#props' => [
        'heading' => 'Fixture heading',
        'features' => [
          ['title' => 'Card one', 'body' => 'Body one', 'cta_label' => 'Learn more', 'cta_url' => '/x'],
        ],
      ],
    ];
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    $this->assertStringContainsString('<a href="/x"', $html);
  }

  /**
   * A features card without a cta_label renders no link.
   */
  public function testFeaturesCardRendersNoCtaWhenAbsent(): void {
    $catalog = $this->container->get('aincient_pages.catalog')->for('landing');
    $build = [
      '#type' => 'component',
      '#component' => $catalog->pluginId('features'),
      '#props' => [
        'heading' => 'Fixture heading',
        'features' => [
          ['title' => 'Card one', 'body' => 'Body one'],
        ],
      ],
    ];
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    $this->assertStringNotContainsString('<a', $html);
  }

}
