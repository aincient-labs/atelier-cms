<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Kernel;

use Drupal\aincient_pages\DesignTokens;
use Drupal\aincient_pages\PresetCatalog;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the preset catalogue: group shape, expansion, legality, default parity.
 *
 * @group aincient
 */
#[RunTestsInSeparateProcesses]
final class PresetCatalogTest extends KernelTestBase {

  protected static $modules = ['system', 'workflows', 'content_moderation', 'aincient_pages'];

  private function presets(): PresetCatalog {
    return $this->container->get('aincient_pages.preset_catalog');
  }

  private function tokens(): DesignTokens {
    return $this->container->get('aincient_pages.design_tokens');
  }

  public function testGroupsAreWellFormed(): void {
    $groups = $this->presets()->groups();
    $this->assertNotEmpty($groups);
    $ids = [];
    foreach ($groups as $group) {
      foreach (['id', 'label', 'description', 'options'] as $field) {
        $this->assertArrayHasKey($field, $group, "group missing $field");
      }
      $ids[] = $group['id'];
      $this->assertNotEmpty($group['options'], "{$group['id']} has no options");
      foreach ($group['options'] as $opt) {
        foreach (['id', 'label', 'blurb', 'tokens', 'fonts'] as $field) {
          $this->assertArrayHasKey($field, $opt, "option in {$group['id']} missing $field");
        }
        $this->assertNotEmpty($opt['tokens'], "{$group['id']}/{$opt['id']} expands to no tokens");
      }
    }
    // The pairing group plus the scale groups are all present.
    $this->assertSame(
      ['pairing', 'roundness', 'direction', 'density', 'text_size', 'heading_weight'],
      $ids,
    );
  }

  public function testEveryPresetTokenIsALegalWrite(): void {
    // A preset must never be able to smuggle in a value the registry would
    // reject — every token it writes passes the same gate as a manual edit.
    foreach ($this->presets()->groups() as $group) {
      foreach ($group['options'] as $opt) {
        foreach ($opt['tokens'] as $name => $value) {
          $this->assertTrue(
            $this->tokens()->validate($name, $value),
            "{$group['id']}/{$opt['id']} writes illegal {$name} = {$value}",
          );
        }
      }
    }
  }

  public function testDefaultOptionsMatchRegistryDefaults(): void {
    // One option per scale group must equal the shipped registry defaults, so a
    // fresh brand reads as that option rather than "Custom".
    $defaults = $this->tokens()->defaults();
    $expectations = [
      'roundness' => 'pill',
      'direction' => 'bottom',
      'density' => 'comfortable',
      'text_size' => 'default',
      'heading_weight' => 'bold',
    ];
    foreach ($expectations as $group => $optionId) {
      $expanded = $this->presets()->expand($group, $optionId);
      $this->assertNotNull($expanded, "$group/$optionId did not expand");
      foreach ($expanded['tokens'] as $name => $value) {
        $this->assertSame(
          $defaults[$name] ?? NULL,
          $value,
          "$group/$optionId token $name drifted from the registry default",
        );
      }
    }
  }

  public function testExpandPairingStagesFontsAndStacks(): void {
    $editorial = $this->presets()->expand('pairing', 'editorial');
    $this->assertNotNull($editorial);
    $this->assertArrayHasKey('font_family_display', $editorial['tokens']);
    $this->assertArrayHasKey('font_family_base', $editorial['tokens']);
    $this->assertContains('Playfair Display', $editorial['fonts']);
    // The stacks are legal font-family writes.
    $this->assertTrue($this->tokens()->validate('font_family_display', $editorial['tokens']['font_family_display']));
  }

  public function testUnknownGroupOrOptionReturnsNull(): void {
    $p = $this->presets();
    $this->assertNull($p->expand('nope', 'sharp'));
    $this->assertNull($p->expand('roundness', 'nope'));
    $this->assertNull($p->expand('pairing', 'nope'));
    $this->assertFalse($p->has('roundness', 'nope'));
    $this->assertTrue($p->has('roundness', 'sharp'));
    $this->assertTrue($p->has('pairing', 'inter'));
  }

}
