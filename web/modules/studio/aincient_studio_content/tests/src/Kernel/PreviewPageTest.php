<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_studio_content\Kernel;

use Drupal\aincient_core\Capability\CapabilityManager;
use Drupal\aincient_studio_content\Plugin\AiCapability\PreviewPage;
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
    'aincient_core',
    'workflows', 'content_moderation', 'aincient_pages',
    // The verb is the Content studio's (Phase E.4): its module, and the studio
    // frame it depends on.
    'filter', 'aincient_chat', 'aincient_studio_content',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->setCurrentUser($this->createUser(['administer aincient pages']));
  }

  private function manager(): CapabilityManager {
    return $this->container->get('plugin.manager.aincient.capabilities');
  }

  private function runPreview(string $opsJson): string {
    /** @var \Drupal\aincient_core\Capability\ExecutableCapabilityInterface $tool */
    $tool = $this->manager()->createInstance('aincient_studio_content:preview_page');
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

  public function testAcceptsBlogSetContentOp(): void {
    // A blog post: flip type, then stage the body fields via set_content
    // (body_md is Markdown). Both ops pass the structural guardrail clean.
    $out = $this->runPreview(json_encode([
      ['op' => 'set_meta', 'type' => 'blog', 'title' => 'How we ship'],
      ['op' => 'set_content', 'author' => 'Ada', 'body_md' => "## Intro\n\nHello."],
    ]));
    $envelope = json_decode($out, TRUE);
    $this->assertSame('page_preview', $envelope['__widget__']);
    $this->assertCount(2, $envelope['payload']['ops']);
    $this->assertSame([], $envelope['payload']['rejected']);
    $this->assertSame('set_content', $envelope['payload']['ops'][1]['op']);
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

  /**
   * A stringified `props` is repaired, and the REPAIRED props is what ships.
   *
   * This tool's linter was gated on is_array($op['props']), so a stringified
   * props escaped the structural lint as well as the apply — the doubly-silent
   * half of the bug: no warning here, empty props at PageStore, and an agent
   * reporting a section it had really left blank (DECISIONS 0327).
   */
  public function testStringifiedPropsIsRepairedInTheEnvelope(): void {
    $out = $this->runPreview((string) json_encode([
      [
        'op' => 'add_section',
        'component' => 'hero',
        'props' => (string) json_encode(['heading' => 'Hello there', 'variant' => 'split']),
      ],
    ]));
    $envelope = json_decode($out, TRUE);

    $this->assertSame('page_preview', $envelope['__widget__']);
    $this->assertSame([], $envelope['payload']['rejected']);
    // The op that travels to the client (and back into applyOps) carries a real
    // object, so the studio applies the props the agent actually wrote.
    $this->assertSame(
      ['heading' => 'Hello there', 'variant' => 'split'],
      $envelope['payload']['ops'][0]['props'],
    );
  }

  /**
   * The repaired props is visible to the LINTER too, so advisories still fire.
   *
   * Proves the repair happens before the lint rather than only at apply time: a
   * stringified props carrying a misnamed row field must still earn its warning.
   */
  public function testLinterSeesRepairedProps(): void {
    $out = $this->runPreview((string) json_encode([
      [
        'op' => 'add_section',
        'component' => 'testimonials',
        'props' => (string) json_encode([
          'heading' => 'Loved by cooks',
          'testimonials' => [['quote' => 'Great!', 'author' => 'Sam']],
        ]),
      ],
    ]));
    $envelope = json_decode($out, TRUE);

    $this->assertCount(1, $envelope['payload']['ops']);
    $this->assertNotEmpty($envelope['payload']['warnings'], 'The lint must see through the repair.');
    $this->assertStringContainsString('quotes', implode(' ', $envelope['payload']['warnings']));
  }

  /**
   * A `props` that is not a JSON object is rejected by name, before it applies.
   */
  public function testUnusablePropsIsRejectedWithReason(): void {
    $out = $this->runPreview((string) json_encode([
      ['op' => 'add_section', 'component' => 'hero', 'props' => 'not json at all'],
      ['op' => 'add_section', 'component' => 'cta', 'props' => ['tone' => 'brand']],
    ]));
    $envelope = json_decode($out, TRUE);

    // The good op survives; the bad one is named, not silently emptied.
    $this->assertCount(1, $envelope['payload']['ops']);
    $this->assertSame('cta', $envelope['payload']['ops'][0]['component']);
    $this->assertCount(1, $envelope['payload']['rejected']);
    $this->assertStringContainsString('props', $envelope['payload']['rejected'][0]);
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
    $this->assertStringContainsString('REFUSED', $envelope['summary']);
    // No live draft wired: only the structural check ran, so nothing is
    // claimed as applied.
    $this->assertSame('unverified', $envelope['status']);
    $this->assertSame(3, $envelope['refused_count']);
    $this->assertArrayNotHasKey('sections_now', $envelope);
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
    $this->assertStringContainsString('REFUSED', $envelope['summary']);
    // No live draft wired: only the structural check ran, so nothing is
    // claimed as applied.
    $this->assertSame('unverified', $envelope['status']);
    $this->assertSame(1, $envelope['refused_count']);
    $this->assertArrayNotHasKey('sections_now', $envelope);
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

  /**
   * Runs the tool with the live draft wired in, as the workflow injects it.
   */
  private function runWired(array $ops, array $schema, ?int $nodeId = NULL): array {
    /** @var \Drupal\aincient_core\Capability\ExecutableCapabilityInterface $tool */
    $tool = $this->manager()->createInstance('aincient_studio_content:preview_page');
    $tool->setContextValue('ops', $ops);
    $tool->setContextValue('page_state', array_filter([
      'live_page_state' => (string) json_encode($schema),
      'working_node_id' => $nodeId,
      'context_policy' => 'irrelevant',
    ]));
    $tool->execute();
    $envelope = json_decode($tool->getReadableOutput(), TRUE);
    $this->assertIsArray($envelope, $tool->getReadableOutput());
    return $envelope;
  }

  /**
   * A three-section draft with stable ids.
   */
  private static function draft(): array {
    return [
      'type' => 'landing',
      'title' => 'Lumen',
      'sections' => [
        ['id' => 'aaaa1111', 'component' => 'hero', 'props' => ['heading' => 'Welcome']],
        ['id' => 'bbbb2222', 'component' => 'cta', 'props' => ['heading' => 'Join']],
        ['id' => 'cccc3333', 'component' => 'faq', 'props' => ['heading' => 'Questions']],
      ],
    ];
  }

  /**
   * Every op lands: `applied`, nothing refused, and the order as stored.
   */
  public function testWiredAllAppliedReportsTheResultingOrder(): void {
    PreviewPage::resetChain();
    $envelope = $this->runWired([
      ['op' => 'reorder', 'order' => ['aaaa1111', 'cccc3333', 'bbbb2222']],
    ], self::draft());

    $this->assertSame('applied', $envelope['status']);
    $this->assertSame(1, $envelope['applied']);
    $this->assertSame(0, $envelope['refused_count']);
    $this->assertSame([], $envelope['refused']);
    $this->assertSame(['aaaa1111', 'cccc3333', 'bbbb2222'], array_column($envelope['sections_now'], 'id'));
    $this->assertSame('Questions', $envelope['sections_now'][1]['label']);
    $this->assertArrayNotHasKey('note', $envelope);
    $this->assertStringContainsString('hero [aaaa1111], faq [cccc3333], cta [bbbb2222]', $envelope['summary']);
    // The widget payload the console reads is unchanged.
    $this->assertCount(1, $envelope['payload']['ops']);
    $this->assertSame([], $envelope['payload']['rejected']);
  }

  /**
   * One op lands, one is refused by the store: `partially_applied`, the
   * refusal named, and the order reflecting only what landed.
   */
  public function testWiredPartialNamesTheRefusalAndTheTrueOrder(): void {
    PreviewPage::resetChain();
    $envelope = $this->runWired([
      ['op' => 'remove_section', 'id' => 'bbbb2222'],
      // Not a permutation of the (now two) sections — refused.
      ['op' => 'reorder', 'order' => ['cccc3333', 'aaaa1111', 'bbbb2222']],
    ], self::draft());

    $this->assertSame('partially_applied', $envelope['status']);
    $this->assertSame(1, $envelope['applied']);
    $this->assertSame(1, $envelope['refused_count']);
    $this->assertSame('reorder', $envelope['refused'][0]['op']);
    $this->assertNotSame('', $envelope['refused'][0]['reason']);
    $this->assertSame(['aaaa1111', 'cccc3333'], array_column($envelope['sections_now'], 'id'));
    $this->assertStringStartsWith('PARTIALLY applied', $envelope['summary']);
    $this->assertArrayHasKey('note', $envelope);
  }

  /**
   * Nothing lands: `refused`, the page unchanged, and the summary leads with
   * it (bug B3 — the agent reported a move the studio had refused).
   */
  public function testWiredAllRefusedSaysThePageDidNotChange(): void {
    PreviewPage::resetChain();
    $envelope = $this->runWired([
      ['op' => 'reorder', 'order' => ['cccc3333', 'aaaa1111']],
      ['op' => 'remove_section', 'id' => 'zzzz9999'],
      ['op' => 'update_section', 'index' => 7, 'props' => ['heading' => 'x']],
    ], self::draft());

    $this->assertSame('refused', $envelope['status']);
    $this->assertSame(0, $envelope['applied']);
    $this->assertSame(3, $envelope['refused_count']);
    $this->assertSame(['reorder', 'remove_section', 'update_section'], array_column($envelope['refused'], 'op'));
    $this->assertSame(['aaaa1111', 'bbbb2222', 'cccc3333'], array_column($envelope['sections_now'], 'id'));
    $this->assertStringStartsWith('REFUSED', $envelope['summary']);
    $this->assertStringContainsString('did NOT change', $envelope['summary']);
  }

  /**
   * A structural rejection and a store refusal land in the same list.
   */
  public function testWiredMergesStructuralAndStoreRefusals(): void {
    PreviewPage::resetChain();
    $envelope = $this->runWired([
      ['op' => 'frobnicate'],
      ['op' => 'remove_section', 'id' => 'zzzz9999'],
    ], self::draft());

    $this->assertSame('refused', $envelope['status']);
    $this->assertSame(2, $envelope['refused_count']);
    $this->assertSame(['invalid', 'remove_section'], array_column($envelope['refused'], 'op'));
    // The console's string list of structural rejections is untouched.
    $this->assertCount(1, $envelope['payload']['rejected']);
  }

  /**
   * A second call in the same turn builds on the first (the injected state is
   * the turn's starting draft; the client applies the calls in order).
   */
  public function testWiredSecondCallInATurnChainsOnTheFirst(): void {
    PreviewPage::resetChain();
    $this->runWired([['op' => 'remove_section', 'id' => 'bbbb2222']], self::draft());
    $envelope = $this->runWired([
      ['op' => 'reorder', 'order' => ['cccc3333', 'aaaa1111']],
    ], self::draft());

    $this->assertSame('applied', $envelope['status']);
    $this->assertSame(['cccc3333', 'aaaa1111'], array_column($envelope['sections_now'], 'id'));
  }

  /**
   * A saved page's type flip is refused, exactly as the studio's apply does.
   */
  public function testWiredSavedPageRefusesATypeFlip(): void {
    PreviewPage::resetChain();
    $envelope = $this->runWired([['op' => 'set_meta', 'type' => 'blog']], self::draft(), 5);
    $this->assertSame('refused', $envelope['status']);
    $this->assertSame('set_meta', $envelope['refused'][0]['op']);
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
