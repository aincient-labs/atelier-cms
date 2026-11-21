<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_studio_components\Kernel;

use Drupal\aincient_studio_components\Controller\ConstraintController;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * A pack written before 0.30.0 overrides the hero by its OLD id.
 *
 * `atelier_test_legacy_pack` says `replaces: 'aincient_pages:hero'`. The
 * 0.30.0 rewrite stores the owner's "Use original instead" as atelier:hero, so
 * the rail must still report the override and a studio save must keep the
 * switch — before the fix the save intersected with the old key and silently
 * emptied overrides_off, bringing the pack's hero back on every page.
 *
 * @group aincient
 */
#[RunTestsInSeparateProcesses]
final class LegacyOverrideOffTest extends KernelTestBase {

  protected static $modules = [
    'system', 'user', 'file', 'image', 'media', 'link', 'menu_link_content',
    'field', 'filter', 'text', 'node', 'key',
    'workflows', 'content_moderation', 'aincient_core', 'atelier', 'aincient_pages',
    'aincient_chat', 'aincient_studio_components', 'atelier_test_legacy_pack',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installConfig(['system', 'workflows', 'content_moderation', 'aincient_pages']);
    if (!NodeType::load('aincient_page')) {
      NodeType::create(['type' => 'aincient_page', 'name' => 'Page'])->save();
    }
    FieldStorageConfig::create(['field_name' => 'field_page_type', 'entity_type' => 'node', 'type' => 'string'])->save();
    FieldConfig::create(['field_name' => 'field_page_type', 'entity_type' => 'node', 'bundle' => 'aincient_page', 'label' => 'Type'])->save();
  }

  private function save(array $payload): array {
    $request = new Request(content: json_encode($payload, JSON_THROW_ON_ERROR));
    $response = ConstraintController::create($this->container)->save($request);
    return json_decode((string) $response->getContent(), TRUE) + ['status' => $response->getStatusCode()];
  }

  public function testLegacyReplacesKeysByTheNewId(): void {
    $replacements = ConstraintController::replacements($this->container->get('plugin.manager.sdc')->getDefinitions());
    $this->assertArrayHasKey('atelier:hero', $replacements);
    $this->assertArrayNotHasKey('aincient_pages:hero', $replacements);
    $this->assertSame('atelier_test_legacy_pack', $replacements['atelier:hero']['provider']);
  }

  public function testSwitchedOffLegacyOverrideSurvivesAStudioSave(): void {
    // The state the post_update leaves: the switch stored under the new id.
    $this->config('aincient_pages.site_constraint')->set('overrides_off', ['atelier:hero'])->save();

    $result = $this->save(['overrides_off' => ['atelier:hero']]);
    $this->assertSame(200, $result['status']);
    $this->assertSame(['atelier:hero'], $result['constraint']['overrides_off']);
    $this->assertSame(['atelier:hero'], $this->config('aincient_pages.site_constraint')->get('overrides_off'));

    $hero = array_column($result['components'], NULL, 'name')['hero'];
    $this->assertTrue($hero['override_off']);
    $this->assertSame('atelier_test_legacy_pack', $hero['replaced_by']['provider']);
  }

}
