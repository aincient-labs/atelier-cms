<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Kernel;

use Drupal\aincient_pages\ChromeRepository;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the chrome layout store: defaults, validation, and non-persisting draft.
 *
 * @group aincient
 */
#[RunTestsInSeparateProcesses]
final class ChromeRepositoryTest extends KernelTestBase {

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

  private function repo(): ChromeRepository {
    return $this->container->get('aincient_pages.chrome_repository');
  }

  public function testShippedDefaults(): void {
    // The shipped install config matches the registry defaults (a fresh site is
    // unchanged from the pre-variant look).
    $this->assertSame(
      ['logo_position' => 'left', 'sticky' => TRUE, 'nav_alignment' => 'end'],
      $this->repo()->header(),
    );
    $this->assertSame(['layout' => 'inline', 'show_tagline' => TRUE], $this->repo()->footer());
  }

  public function testUpdateValidatesAndDropsUnknown(): void {
    $applied = $this->repo()->update([
      'header' => [
        'nav_alignment' => 'center',
        'logo_position' => 'nonsense',
        'unknown_key' => 'x',
      ],
      'footer' => ['show_tagline' => FALSE],
    ]);

    // The valid changes persisted; the invalid enum value + unknown key dropped.
    $this->assertContains('header.nav_alignment → center', $applied);
    $this->assertContains('footer.show_tagline → false', $applied);
    $header = $this->repo()->header();
    $this->assertSame('center', $header['nav_alignment']);
    $this->assertSame('left', $header['logo_position'], 'Invalid enum left at default.');
    $this->assertArrayNotHasKey('unknown_key', $header);
    $this->assertFalse($this->repo()->footer()['show_tagline']);
  }

  public function testBooleanStringsCoerce(): void {
    $this->repo()->update(['header' => ['sticky' => 'false']]);
    $this->assertFalse($this->repo()->header()['sticky']);
    $this->repo()->update(['header' => ['sticky' => 'true']]);
    $this->assertTrue($this->repo()->header()['sticky']);
  }

  public function testApplyDraftDoesNotPersist(): void {
    $draft = $this->repo()->applyDraft(['header' => ['logo_position' => 'center']]);
    $this->assertSame('center', $draft['header']['logo_position']);
    // Nothing was written — the saved value is still the default.
    $this->assertSame('left', $this->repo()->header()['logo_position']);
  }

  public function testNoChangeDoesNotReport(): void {
    // Re-applying the existing value yields no "applied" entry.
    $this->assertSame([], $this->repo()->update(['header' => ['nav_alignment' => 'end']]));
  }

}
