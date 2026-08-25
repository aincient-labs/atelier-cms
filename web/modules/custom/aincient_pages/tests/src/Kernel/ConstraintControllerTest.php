<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Kernel;

use Drupal\aincient_pages\Controller\ConstraintController;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * The Components governance seam (plans/byo-components.md W1b, decision #2).
 *
 * The site constraint is the ONE home for "this site never uses X" — so its
 * endpoint must merge by provided key (a partial publish can't clobber the
 * other axes), reflect a write in the SAME response (the pane re-seeds from
 * it), narrow the compiled palette for real, and refuse the one destructive
 * shape (removing every tone) instead of silently degrading.
 *
 * @group aincient
 */
#[RunTestsInSeparateProcesses]
final class ConstraintControllerTest extends KernelTestBase {

  protected static $modules = [
    'system',
    'user',
    'workflows', 'content_moderation', 'aincient_core', 'aincient_pages',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['workflows', 'content_moderation', 'aincient_pages']);
  }

  private function controller(): ConstraintController {
    return ConstraintController::create($this->container);
  }

  private function save(array $payload): array {
    $request = new Request(content: json_encode($payload, JSON_THROW_ON_ERROR));
    $response = $this->controller()->save($request);
    return json_decode((string) $response->getContent(), TRUE) + ['status' => $response->getStatusCode()];
  }

  /**
   * The manifest pairs the constraint with the UNDIMINISHED vocabulary.
   */
  public function testManifestCarriesTheFullVocabulary(): void {
    $state = json_decode((string) $this->controller()->manifest()->getContent(), TRUE);
    $names = array_column($state['vocabulary']['components'], 'name');
    $this->assertContains('hero', $names);
    $this->assertContains('block', $names, 'The virtual (SDC-less) placeable is offered too.');
    $this->assertSame(['default', 'muted', 'brand', 'inverted'], $state['vocabulary']['tones']);
    $this->assertSame([], $state['constraint']['components']);
    $this->assertContains('newsletter', $state['effective']['placeable']);
  }

  /**
   * A save narrows the palette and the SAME response already reflects it.
   */
  public function testSaveNarrowsAndReflectsInTheSameResponse(): void {
    $result = $this->save(['components' => ['newsletter'], 'tones' => ['inverted']]);
    $this->assertSame(200, $result['status']);
    $this->assertSame(['components', 'tones'], $result['applied']);
    $this->assertNotContains('newsletter', $result['effective']['placeable']);
    $this->assertSame(['default', 'muted', 'brand'], $result['effective']['tones']);

    // And it stuck: a fresh manifest agrees.
    $state = json_decode((string) $this->controller()->manifest()->getContent(), TRUE);
    $this->assertSame(['newsletter'], $state['constraint']['components']);
  }

  /**
   * Merge by provided key: publishing one axis leaves the others untouched.
   */
  public function testSaveMergesByProvidedKey(): void {
    $this->save(['components' => ['newsletter']]);
    $result = $this->save(['tones' => ['inverted']]);
    $this->assertSame(['newsletter'], $result['constraint']['components'], 'A tones-only publish must not clobber the components axis.');
  }

  /**
   * A variants removal narrows the compiled enum and the prompt signature.
   */
  public function testVariantRemovalNarrowsTheCompiledEnum(): void {
    $result = $this->save(['variants' => ['hero' => ['split']]]);
    $catalog = $this->container->get('aincient_pages.catalog');
    $catalog->reset();
    $landing = $catalog->for('landing');
    $this->assertSame(['centered'], $landing->variantsFor('hero'));
    $this->assertStringContainsString('variant(centered)', $landing->signature('hero'));
    $this->assertSame([], $result['effective']['warnings']);
  }

  /**
   * Removing every tone is refused (422), not silently degraded.
   */
  public function testRemovingEveryToneIsRefused(): void {
    $result = $this->save(['tones' => ['default', 'muted', 'brand', 'inverted']]);
    $this->assertSame(422, $result['status']);
    $state = json_decode((string) $this->controller()->manifest()->getContent(), TRUE);
    $this->assertSame([], $state['constraint']['tones'], 'The refused write must not persist.');
  }

  /**
   * An unknown component removal persists (a pack may be temporarily absent)
   * and surfaces as a compile warning — never fatal (decision #4).
   */
  public function testUnknownComponentRemovalIsStoredAndWarned(): void {
    $result = $this->save(['components' => ['spotlight']]);
    $this->assertSame(['spotlight'], $result['constraint']['components']);
    $this->assertNotEmpty($result['effective']['warnings']);
    $this->assertContains('hero', $result['effective']['placeable'], 'The palette survives the unknown removal.');
  }

}
