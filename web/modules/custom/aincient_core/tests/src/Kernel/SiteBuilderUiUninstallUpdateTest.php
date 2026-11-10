<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_core\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Update 11014: the site-builder admin screens are uninstalled once.
 *
 * The appliance excludes these modules from config import, and an import never
 * uninstalls an excluded module that is still enabled — so this hook is the
 * only thing that removes them from a site upgrading from 0.24.
 *
 * @group aincient_core
 */
#[RunTestsInSeparateProcesses]
final class SiteBuilderUiUninstallUpdateTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'aincient_core'];

  protected function setUp(): void {
    parent::setUp();
    // Uninstalling a module clears its rows from users_data.
    $this->installSchema('user', ['users_data']);
    $this->container->get('module_handler')->loadInclude('aincient_core', 'install');
  }

  public function testUninstallsTheScreensAndIsANoOpAfterwards(): void {
    $this->container->get('module_installer')->install(['field_ui', 'menu_ui', 'contextual']);

    $message = aincient_core_update_11014();

    $handler = $this->container->get('module_handler');
    foreach (['field_ui', 'menu_ui', 'contextual'] as $module) {
      $this->assertFalse($handler->moduleExists($module), "$module is uninstalled.");
    }
    // Their dependencies are not ours to remove.
    $this->assertTrue($handler->moduleExists('field'));
    $this->assertTrue($handler->moduleExists('menu_link_content'));
    $this->assertSame('Uninstalled: field_ui, menu_ui, contextual.', $message);

    $this->assertSame('Nothing to uninstall.', aincient_core_update_11014());
  }

  public function testKeepsAModuleAnotherInstalledModuleNeeds(): void {
    // contextual_test stands in for an owner's module that depends on one.
    $this->container->get('module_installer')->install(['field_ui', 'contextual_test']);

    $message = aincient_core_update_11014();

    $handler = $this->container->get('module_handler');
    $this->assertFalse($handler->moduleExists('field_ui'));
    $this->assertTrue($handler->moduleExists('contextual'));
    $this->assertTrue($handler->moduleExists('contextual_test'));
    $this->assertSame('Uninstalled: field_ui. Kept, another installed module needs them: contextual.', $message);
  }

}
