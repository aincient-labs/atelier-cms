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
 * `atelier_test_legacy_pack` says `replaces: 'aincient_pages:hero'`. Since
 * 0.32.0 that overrides nothing (DECISIONS 0534), so the rail must not list it
 * as an override and "Use original instead" has nothing to switch: a stored
 * switch for it is dropped on the next studio save, which is harmless because
 * the original already renders.
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

  public function testOldReplacesIsNotListedAsAnOverride(): void {
    $replacements = ConstraintController::replacements($this->container->get('plugin.manager.sdc')->getDefinitions());
    $this->assertArrayNotHasKey('atelier:hero', $replacements);
    $this->assertArrayNotHasKey('aincient_pages:hero', $replacements);
  }

  public function testStudioSaveDropsASwitchForAnOldReplaces(): void {
    $this->config('aincient_pages.site_constraint')->set('overrides_off', ['atelier:hero'])->save();

    $result = $this->save(['overrides_off' => ['atelier:hero']]);
    $this->assertSame(200, $result['status']);
    $this->assertSame([], $result['constraint']['overrides_off']);

    $hero = array_column($result['components'], NULL, 'name')['hero'];
    $this->assertNull($hero['replaced_by'] ?? NULL);
  }

}
