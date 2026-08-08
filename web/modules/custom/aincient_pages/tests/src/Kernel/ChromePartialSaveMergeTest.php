<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Kernel;

use Drupal\aincient_pages\ChromeRepository;
use Drupal\aincient_pages\Controller\ChromeController;
use Drupal\aincient_pages\MenuRepository;
use Drupal\aincient_pages\SiteIdentity;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * The clobber-trap guard for the studio-seam split (DECISIONS 0372).
 *
 * After the split, three surfaces persist through ONE shared endpoint —
 * /atelier/chrome/save — each sending only its OWN slice: Identity the
 * guidelines/logo/logo-knobs, Navigation & Pages the menus/routing/arrangement,
 * Settings the site email + privacy. This proves the save MERGES by provided
 * key: publishing one surface's partial payload leaves every other surface's
 * fields untouched. If save ever regressed to replace-semantics, these fail.
 *
 * @group aincient
 */
#[RunTestsInSeparateProcesses]
final class ChromePartialSaveMergeTest extends KernelTestBase {

  protected static $modules = [
    'system',
    'user',
    'link',
    'menu_link_content',
    'workflows', 'content_moderation', 'aincient_core', 'aincient_pages',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('menu_link_content');
    $this->installConfig(['workflows', 'content_moderation', 'aincient_pages']);
  }

  private function controller(): ChromeController {
    return ChromeController::create($this->container);
  }

  private function identity(): SiteIdentity {
    return $this->container->get('aincient_pages.site_identity');
  }

  private function chrome(): ChromeRepository {
    return $this->container->get('aincient_pages.chrome_repository');
  }

  private function menus(): MenuRepository {
    return $this->container->get('aincient_pages.menu_repository');
  }

  /** POST a JSON slice to ChromeController::save(). */
  private function save(array $slice): void {
    $request = Request::create('/atelier/chrome/save', 'POST', [], [], [], [], json_encode($slice));
    $this->controller()->save($request);
  }

  /** Seed a full, mixed chrome state across all three surfaces' concerns. */
  private function seedFullState(): void {
    // Identity slice.
    $this->identity()->update(['name' => 'Lumen', 'tagline' => 'Light, organised'], '© 2026 Lumen');
    $this->identity()->setLogo('media:11');
    $this->identity()->setFavicon('media:12');
    // Settings + routing slices (both live on identity.site).
    $this->identity()->updateSite(['mail' => 'hello@lumen.test']);
    // Arrangement (Navigation) + logo knobs (Identity) both live on chrome.
    $this->chrome()->update([
      'header' => ['nav_alignment' => 'center', 'logo_size' => 'small'],
      'footer' => ['layout' => 'stacked'],
    ]);
    // A menu (Navigation).
    $this->menus()->sync('main', [
      ['title' => 'About', 'url' => '/about', 'enabled' => TRUE],
    ]);
  }

  /**
   * Publishing ONLY the Settings slice (mail) leaves Identity, logo knobs,
   * arrangement and the menu intact.
   */
  public function testSettingsSliceDoesNotClobberOtherSurfaces(): void {
    $this->seedFullState();

    $this->save(['identity' => ['site' => ['mail' => 'ops@lumen.test']]]);

    // The one field it owns changed.
    $this->assertSame('ops@lumen.test', $this->identity()->site()['mail']);

    // Everything the OTHER surfaces own is untouched.
    $this->assertSame('Lumen', $this->identity()->name());
    $this->assertSame('© 2026 Lumen', $this->identity()->footerNote());
    $this->assertSame('media:11', $this->identity()->logo());
    $this->assertSame('media:12', $this->identity()->favicon());
    $this->assertSame('center', $this->chrome()->header()['nav_alignment']);
    $this->assertSame('small', $this->chrome()->header()['logo_size']);
    $this->assertSame('stacked', $this->chrome()->footer()['layout']);
    $tree = $this->menus()->tree('main');
    $this->assertCount(1, $tree);
    $this->assertSame('About', $tree[0]['title']);
  }

  /**
   * Publishing ONLY the Identity slice (guidelines + logo + logo knobs) leaves
   * the Settings email, the routing, the arrangement and the menu intact.
   */
  public function testIdentitySliceDoesNotClobberOtherSurfaces(): void {
    $this->seedFullState();

    $this->save([
      'identity' => [
        'guidelines' => ['name' => 'Aurora'],
        'logo' => 'media:99',
      ],
      // The logo layout knobs are Identity's; arrangement keys are NOT sent.
      'chrome' => ['header' => ['logo_size' => 'large']],
    ]);

    // Identity's own fields changed.
    $this->assertSame('Aurora', $this->identity()->name());
    $this->assertSame('media:99', $this->identity()->logo());
    $this->assertSame('large', $this->chrome()->header()['logo_size']);

    // Untouched guidelines merge (tagline survives a name-only payload).
    $this->assertSame('Light, organised', $this->identity()->guidelines()['tagline']);
    // The other surfaces' fields survive.
    $this->assertSame('hello@lumen.test', $this->identity()->site()['mail']);
    $this->assertSame('center', $this->chrome()->header()['nav_alignment']);
    $this->assertSame('stacked', $this->chrome()->footer()['layout']);
    $tree = $this->menus()->tree('main');
    $this->assertCount(1, $tree);
    $this->assertSame('About', $tree[0]['title']);
  }

}
