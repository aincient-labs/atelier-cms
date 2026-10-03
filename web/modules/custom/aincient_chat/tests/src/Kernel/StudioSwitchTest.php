<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_chat\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\aincient_chat\Studio\StudioSwitch;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The studio on/off switch — `aincient_chat.settings:disabled_studios`.
 *
 * Only the switch itself: absence means on, so the shipped config leaves every
 * studio running, and the list round-trips through a schema-checked save. What
 * a switched-off studio CAUSES (catalog, 403, capability refusal) is asserted
 * by the consumers' own tests.
 *
 * "Fresh" here is NO config object rather than `installConfig(['aincient_chat'])`:
 * the module's install config still carries `default_workflow` /
 * `exposed_workflows`, which have no schema (the known drift
 * ContextAttachTest documents), and strict schema checking rejects it. The
 * switch's own key is schema-declared, so every save below IS checked.
 *
 * @group aincient
 * @covers \Drupal\aincient_chat\Studio\StudioSwitch
 */
#[RunTestsInSeparateProcesses]
final class StudioSwitchTest extends KernelTestBase {

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
    'aincient_studio_test',
  ];

  private function switch(): StudioSwitch {
    $switch = $this->container->get('aincient_chat.studio_switch');
    $this->assertInstanceOf(StudioSwitch::class, $switch);
    return $switch;
  }

  /**
   * No config, nothing off: absence means on.
   */
  public function testFreshConfigHasEverythingOn(): void {
    $this->assertSame([], $this->switch()->disabled());
    foreach ($this->container->get('plugin.manager.aincient.studios')->keys() as $id) {
      $this->assertTrue($this->switch()->isEnabled($id), $id);
    }
  }

  /**
   * Off persists to config and touches only that studio; on removes it again.
   */
  public function testDisableThenReEnable(): void {
    $switch = $this->switch();

    $switch->setEnabled('studio_test', FALSE);
    $this->assertFalse($switch->isEnabled('studio_test'));
    $this->assertTrue($switch->isEnabled('general'), 'Switching one off leaves the others on.');
    $this->assertSame(['studio_test'], $switch->disabled());
    // Persisted, not just held in the service.
    $this->assertSame(['studio_test'], $this->config(StudioSwitch::CONFIG)->get(StudioSwitch::KEY));

    $switch->setEnabled('studio_test', TRUE);
    $this->assertTrue($switch->isEnabled('studio_test'));
    $this->assertSame([], $switch->disabled());
  }

  /**
   * Switching off twice records the id once.
   */
  public function testDoubleDisableIsIdempotent(): void {
    $switch = $this->switch();
    $switch->setEnabled('studio_test', FALSE);
    $switch->setEnabled('studio_test', FALSE);
    $this->assertSame(['studio_test'], $switch->disabled());
    $this->assertSame(['studio_test'], $this->config(StudioSwitch::CONFIG)->get(StudioSwitch::KEY));
  }

  /**
   * General is the floor: it cannot be switched off, and asking is an error.
   */
  public function testGeneralIsPinnedOn(): void {
    $switch = $this->switch();
    $this->expectException(\InvalidArgumentException::class);
    $switch->setEnabled('general', FALSE);
  }

  /**
   * Even a stray `general` in the config list does not switch it off.
   */
  public function testGeneralStaysOnDespiteStaleConfig(): void {
    $this->config(StudioSwitch::CONFIG)->set(StudioSwitch::KEY, ['general'])->save();
    $this->assertTrue($this->switch()->isEnabled('general'));
  }

  /**
   * Settings is pinned too (DECISIONS 0439): it is the room the switches live
   * in, so switching it off would lock the owner out of every other switch.
   * The refusal names the permission that answers the lock-down case.
   */
  public function testSettingsIsPinnedOn(): void {
    $switch = $this->switch();
    try {
      $switch->setEnabled(StudioSwitch::SETTINGS_ID, FALSE);
      $this->fail('Settings must refuse to switch off.');
    }
    catch (\InvalidArgumentException $e) {
      $this->assertStringContainsString('use aincient studio settings', $e->getMessage());
    }
    $this->config(StudioSwitch::CONFIG)->set(StudioSwitch::KEY, ['settings'])->save();
    $this->assertTrue($this->switch()->isEnabled('settings'), 'A stale `settings` in the list does not switch it off.');
    $this->assertSame(['general', 'settings'], StudioSwitch::ALWAYS_ON, 'Exactly two pins; a third is a decision.');
  }

}
