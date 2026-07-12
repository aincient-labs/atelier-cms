<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Kernel;

use Drupal\ai\Service\FunctionCalling\FunctionCallPluginManager;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the page agent's preview_page capability.
 *
 * Sibling of BrandAgentTest's preview_brand coverage: the page agent never
 * writes server-side — preview_page emits a generative-UI ops envelope that
 * persists NOTHING (the studio's Publish is the only write). Here we cover the
 * structural validation + envelope shape; the authoritative ops clamp lives in
 * PageStore::applyOps (PageOpsTest).
 *
 * @group aincient
 */
#[RunTestsInSeparateProcesses]
final class PreviewPageTest extends KernelTestBase {

  use UserCreationTrait;

  protected static $modules = [
    'system',
    'user',
    'node',
    'field',
    'text',
    'key',
    'ai',
    'aincient_core',
    'workflows', 'content_moderation', 'aincient_pages',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->setCurrentUser($this->createUser(['administer aincient pages']));
  }

  private function manager(): FunctionCallPluginManager {
    return $this->container->get('plugin.manager.ai.function_calls');
  }

  private function runPreview(string $opsJson): string {
    /** @var \Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface $tool */
    $tool = $this->manager()->createInstance('aincient_pages:preview_page');
    $tool->setContextValue('ops', $opsJson);
    $tool->execute();
    return $tool->getReadableOutput();
  }

  public function testEmitsOpsEnvelope(): void {
    $out = $this->runPreview(json_encode([
      ['op' => 'set_meta', 'title' => 'Lumen'],
      ['op' => 'add_section', 'component' => 'hero', 'props' => ['heading' => 'Hi', 'variant' => 'split']],
      ['op' => 'add_section', 'component' => 'cta', 'props' => ['tone' => 'brand']],
    ]));
    $envelope = json_decode($out, TRUE);
    $this->assertIsArray($envelope);
    $this->assertSame('page_preview', $envelope['__widget__']);
    $this->assertNotEmpty($envelope['summary']);
    $this->assertCount(3, $envelope['payload']['ops']);
    $this->assertSame([], $envelope['payload']['rejected']);
    // Well-shaped sections trigger no structural advisories.
    $this->assertSame([], $envelope['payload']['warnings']);
    // preview_page is draft-only — it never returns a save/apply URL.
    $this->assertArrayNotHasKey('saveUrl', $envelope['payload']);
  }

  public function testSurfacesStructuralWarningsToTheAgent(): void {
    // The reported bug: quote rows under the wrong prop name. The op still
    // applies (ops non-empty), but the agent is told to fix it.
    $out = $this->runPreview(json_encode([
      ['op' => 'add_section', 'component' => 'testimonials', 'props' => [
        'heading' => 'Loved by cooks',
        'testimonials' => [['quote' => 'Great!', 'author' => 'Sam', 'title' => 'Chef']],
      ]],
    ]));
    $envelope = json_decode($out, TRUE);
    $this->assertCount(1, $envelope['payload']['ops']);
    $this->assertNotEmpty($envelope['payload']['warnings']);
    $this->assertStringContainsString('quotes', implode(' ', $envelope['payload']['warnings']));
    $this->assertStringContainsString('Fix and re-send', $envelope['summary']);
  }

  public function testRejectsUnknownOpsAndComponentsButKeepsValid(): void {
    $out = $this->runPreview(json_encode([
      ['op' => 'add_section', 'component' => 'malware'],
      ['op' => 'frobnicate'],
      ['op' => 'update_section'],
      ['op' => 'update_section', 'index' => 0, 'props' => ['heading' => 'Kept']],
    ]));
    $envelope = json_decode($out, TRUE);
    $this->assertSame('page_preview', $envelope['__widget__']);
    // Only the well-formed update_section survives.
    $this->assertCount(1, $envelope['payload']['ops']);
    $this->assertSame('update_section', $envelope['payload']['ops'][0]['op']);
    $this->assertCount(3, $envelope['payload']['rejected']);
    $this->assertStringContainsString('Skipped', $envelope['summary']);
  }

  public function testRejectsTeaserFieldsNestedOnSetMeta(): void {
    // The reported bug: the agent reached for set_meta and nested the teaser
    // fields under a "teaser" key. set_meta silently dropped them (no effect),
    // so the agent got a false "done". Now the mis-shaped op is REJECTED with a
    // message pointing at set_teaser, and the valid sibling still applies.
    $out = $this->runPreview(json_encode([
      ['op' => 'set_meta', 'teaser' => ['title' => 'Meet the Studio', 'description' => 'Build fast.']],
      ['op' => 'set_teaser', 'title' => 'Meet the Studio', 'description' => 'Build fast.'],
    ]));
    $envelope = json_decode($out, TRUE);
    $this->assertSame('page_preview', $envelope['__widget__']);
    // Only the correctly-shaped set_teaser survives.
    $this->assertCount(1, $envelope['payload']['ops']);
    $this->assertSame('set_teaser', $envelope['payload']['ops'][0]['op']);
    // The rejection names the offending key and steers to set_teaser.
    $this->assertCount(1, $envelope['payload']['rejected']);
    $reason = $envelope['payload']['rejected'][0];
    $this->assertStringContainsString('set_meta', $reason);
    $this->assertStringContainsString('teaser', $reason);
    $this->assertStringContainsString('set_teaser', $reason);
    $this->assertStringContainsString('Skipped', $envelope['summary']);
  }

  public function testRejectsFieldlessMetadataOps(): void {
    // A set_meta / set_teaser carrying no value-bearing field is a no-op that
    // would report a false success — reject it, keeping the effective sibling.
    $out = $this->runPreview(json_encode([
      ['op' => 'set_meta'],
      ['op' => 'set_teaser'],
      ['op' => 'set_meta', 'description' => 'A real override.'],
    ]));
    $envelope = json_decode($out, TRUE);
    $this->assertCount(1, $envelope['payload']['ops']);
    $this->assertSame('set_meta', $envelope['payload']['ops'][0]['op']);
    $this->assertCount(2, $envelope['payload']['rejected']);
    $this->assertStringContainsString('at least one field', implode(' ', $envelope['payload']['rejected']));
  }

  public function testAcceptsWellShapedMetadataOps(): void {
    // The happy path: flat set_meta (SEO) + flat set_teaser both pass clean.
    $out = $this->runPreview(json_encode([
      ['op' => 'set_meta', 'description' => 'Search summary.', 'og_title' => 'Share title'],
      ['op' => 'set_teaser', 'title' => 'Card title', 'image' => 'media:42'],
    ]));
    $envelope = json_decode($out, TRUE);
    $this->assertCount(2, $envelope['payload']['ops']);
    $this->assertSame([], $envelope['payload']['rejected']);
  }

  public function testAllBadOpsErrorOutWithoutEnvelope(): void {
    $out = $this->runPreview(json_encode([
      ['op' => 'nope'],
      ['op' => 'add_section', 'component' => 'evil'],
    ]));
    $this->assertStringStartsWith('Error:', $out);
    $this->assertStringNotContainsString('__widget__', $out);
  }

  public function testEmptyOpsErrors(): void {
    $this->assertStringStartsWith('Error:', $this->runPreview('[]'));
    $this->assertStringStartsWith('Error:', $this->runPreview(''));
    $this->assertStringStartsWith('Error:', $this->runPreview('not json'));
  }

  public function testRefusesWithoutPermission(): void {
    $this->setCurrentUser($this->createUser());
    $out = $this->runPreview(json_encode([['op' => 'add_section', 'component' => 'hero']]));
    $this->assertStringStartsWith('Error:', $out);
    $this->assertStringNotContainsString('__widget__', $out);
  }

}
