<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Kernel;

use Drupal\aincient_pages\ComponentMixException;
use Drupal\aincient_pages\Entity\PageKind;
use Drupal\aincient_pages\PageStore;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * One component per short name per page (DECISIONS 0539).
 *
 * With atelier_test_pair_pack, `testimonials` is a same-name pair. The landing
 * kind offers every component, so it offers the pack's; a page that still
 * holds the built-in is "outdated": component edits wait for the owner's
 * switch, and no save may leave both on one page.
 *
 * @group aincient
 */
#[RunTestsInSeparateProcesses]
final class SameNamePairEditTest extends KernelTestBase {

  use EditorialWorkflowTestTrait;

  protected static $modules = [
    'system', 'user', 'field', 'text', 'node', 'workflows', 'content_moderation',
    'aincient_core', 'atelier', 'aincient_pages', 'atelier_test_pair_pack',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('content_moderation_state');
    $this->installSchema('node', ['node_access']);
    NodeType::create(['type' => 'aincient_page', 'name' => 'Page', 'new_revision' => TRUE])->save();
    foreach (['field_page_structure' => 'string_long', 'field_page_content' => 'string_long', 'field_page_type' => 'string'] as $name => $type) {
      FieldStorageConfig::create(['field_name' => $name, 'entity_type' => 'node', 'type' => $type, 'translatable' => $type === 'string_long'])->save();
      FieldConfig::create(['field_name' => $name, 'entity_type' => 'node', 'bundle' => 'aincient_page', 'label' => $name])->save();
    }
    $this->setUpEditorialWorkflow(['aincient_page']);
  }

  private function store(): PageStore {
    return $this->container->get('aincient_pages.store');
  }

  /**
   * Stored component ids, straight from the table.
   */
  private function storedIds(string $id): array {
    $json = $this->container->get('database')->select('node__field_page_structure', 'f')
      ->fields('f', ['field_page_structure_value'])->condition('entity_id', $id)->execute()->fetchField();
    return array_column(json_decode((string) $json, TRUE)['slots'] ?? [], 'component');
  }

  /**
   * A stored landing page whose testimonials section was saved as the
   * built-in before the pack arrived — rewritten in the table, as 0.30.0
   * left it. Returns the node id.
   */
  private function outdatedPage(): string {
    $id = $this->store()->store(['type' => 'landing', 'title' => 'Proof', 'sections' => [
      ['id' => 'hero01', 'component' => 'hero', 'props' => ['heading' => 'Hi']],
      ['id' => 'quote01', 'component' => 'testimonials', 'props' => ['heading' => 'Kind words']],
    ]]);
    $db = $this->container->get('database');
    foreach (['node__field_page_structure', 'node_revision__field_page_structure'] as $table) {
      $json = $db->select($table, 'f')->fields('f', ['field_page_structure_value'])->condition('entity_id', $id)->execute()->fetchField();
      $db->update($table)->fields(['field_page_structure_value' => str_replace('atelier_test_pair_pack:testimonials', 'atelier:testimonials', (string) $json)])->condition('entity_id', $id)->execute();
    }
    $this->container->get('entity_type.manager')->getStorage('node')->resetCache();
    return $id;
  }

  /**
   * A landing page that still holds the built-in testimonials.
   */
  private function outdatedSchema(): array {
    return ['type' => 'landing', 'title' => 'Proof', 'sections' => [
      ['id' => 'hero01', 'component' => 'hero', 'props' => ['heading' => 'Hi']],
      ['id' => 'quote01', 'component' => 'atelier:testimonials', 'props' => ['heading' => 'Kind words', 'quotes' => [['quote' => 'Solid.', 'author' => 'Grace']]]],
    ]];
  }

  /**
   * Short names store as what the kind offers; the hidden one of the pair
   * stores and reads back by its full id; everything else reads short.
   */
  public function testEachOfThePairRoundTrips(): void {
    $id = $this->outdatedPage();
    $this->assertSame(['atelier:hero', 'atelier:testimonials'], $this->storedIds($id));
    $this->assertSame(['hero', 'atelier:testimonials'], array_column($this->store()->load($id)['sections'], 'component'));

    // An ordinary save keeps the section the page already holds (0455).
    $this->assertTrue($this->store()->update($id, $this->store()->load($id)));
    $this->assertSame(['atelier:hero', 'atelier:testimonials'], $this->storedIds($id));

    $fresh = $this->store()->store(['type' => 'landing', 'title' => 'New', 'sections' => [
      ['id' => 'quote01', 'component' => 'testimonials', 'props' => ['quote' => 'It just works.']],
      ['id' => 'quote02', 'component' => 'atelier:testimonials', 'props' => ['heading' => 'Never placed']],
    ]]);
    $this->assertSame(['atelier_test_pair_pack:testimonials'], $this->storedIds($fresh), 'A new placement of the hidden one is dropped, as any component the kind does not offer.');
    $this->assertSame(['testimonials'], array_column($this->store()->load($fresh)['sections'], 'component'));
  }

  /**
   * A save that would hold both of the pair is refused, never repaired.
   */
  public function testASaveMixingThePairIsRefused(): void {
    $id = $this->outdatedPage();
    $schema = $this->store()->load($id);
    $schema['sections'][] = ['id' => 'quote02', 'component' => 'testimonials', 'props' => ['quote' => 'New']];
    $this->expectException(ComponentMixException::class);
    $this->expectExceptionMessage('"testimonials"');
    $this->store()->update($id, $schema);
  }

  /**
   * On an outdated page, component edits are refused with the switch step and
   * what it would drop; other ops (remove, meta) still apply.
   */
  public function testComponentEditsWaitForTheSwitch(): void {
    $result = $this->store()->applyOps($this->outdatedSchema(), [
      ['op' => 'add_section', 'component' => 'testimonials', 'props' => ['quote' => 'New']],
      ['op' => 'update_section', 'id' => 'hero01', 'props' => ['heading' => 'Changed']],
      ['op' => 'set_meta', 'title' => 'Renamed'],
    ], TRUE);
    $this->assertCount(2, $result['rejected']);
    $reason = $result['rejected'][0]['reason'];
    $this->assertStringContainsString('older version of 1 "testimonials" section (the switch drops: quotes)', $reason);
    $this->assertStringContainsString('{"op":"switch_components"}', $reason);
    $this->assertStringNotContainsString('atelier:', $reason, 'The agent never sees a full id.');
    $this->assertSame('Renamed', $result['schema']['title']);
    $this->assertSame(['hero', 'atelier:testimonials'], array_column($result['schema']['sections'], 'component'));
    $this->assertSame('Hi', $result['schema']['sections'][0]['props']['heading']);
  }

  /**
   * The switch converts every outdated section in place (same id, carried
   * props clamped to the new component); later ops in the batch then apply.
   */
  public function testTheSwitchConvertsInPlaceThenEditsApply(): void {
    $result = $this->store()->applyOps($this->outdatedSchema(), [
      ['op' => 'switch_components'],
      ['op' => 'update_section', 'id' => 'quote01', 'props' => ['quote' => 'It just works.', 'author' => 'Ada']],
    ], TRUE);
    $this->assertSame([], $result['rejected']);
    $switched = $result['schema']['sections'][1];
    $this->assertSame('quote01', $switched['id']);
    $this->assertSame('atelier_test_pair_pack:testimonials', $switched['component'], 'The switch marks the owner\'s choice with the full id until the save.');
    $this->assertSame('Kind words', $switched['props']['heading']);
    $this->assertSame('It just works.', $switched['props']['quote']);
    $this->assertArrayNotHasKey('quotes', $switched['props']);

    $id = $this->store()->store($result['schema']);
    $this->assertSame(['atelier:hero', 'atelier_test_pair_pack:testimonials'], $this->storedIds($id));
    $this->assertSame(['hero', 'testimonials'], array_column($this->store()->load($id)['sections'], 'component'));

    // On an existing page: the switched draft saves as the pack's, too.
    $page = $this->outdatedPage();
    $result = $this->store()->applyOps($this->store()->load($page), [['op' => 'switch_components']], TRUE);
    $this->assertTrue($this->store()->update($page, $result['schema']));
    $this->assertSame(['atelier:hero', 'atelier_test_pair_pack:testimonials'], $this->storedIds($page));
  }

  /**
   * A draft loaded before the pair existed says `testimonials` for the slot
   * that stores the built-in. Saving it keeps the built-in: no save switches
   * a section the owner did not agree to switch.
   */
  public function testAStaleShortNameDraftKeepsTheStoredComponent(): void {
    $page = $this->outdatedPage();
    $stale = $this->store()->load($page);
    $stale['sections'][1]['component'] = 'testimonials';
    $stale['sections'][1]['props']['heading'] = 'Edited in an old tab';
    $this->assertTrue($this->store()->update($page, $stale));
    $this->assertSame(['atelier:hero', 'atelier:testimonials'], $this->storedIds($page));
    $this->assertSame('Edited in an old tab', $this->store()->load($page)['sections'][1]['props']['heading']);
  }

  /**
   * A switch with nothing to switch says so.
   */
  public function testASwitchWithNothingToSwitchIsRefused(): void {
    $result = $this->store()->applyOps(['type' => 'landing', 'title' => 'T', 'sections' => []], [['op' => 'switch_components']], TRUE);
    $this->assertStringContainsString('nothing to switch', $result['rejected'][0]['reason']);
  }

  /**
   * A kind that names the built-in exactly keeps offering it: there, the
   * short name means the built-in, and such a page is not outdated.
   */
  public function testAKindThatNamesTheBuiltinKeepsIt(): void {
    PageKind::create([
      'id' => 'pressroom', 'label' => 'Press room', 'mode' => 'composition', 'include_new' => FALSE,
      'components' => ['atelier:hero' => [], 'atelier:testimonials' => []],
    ])->save();
    $this->container->get('aincient_pages.catalog')->reset();
    $schema = ['type' => 'pressroom', 'title' => 'Press', 'sections' => [
      ['id' => 'quote01', 'component' => 'testimonials', 'props' => ['heading' => 'Kind words']],
    ]];
    $id = $this->store()->store($schema);
    $this->assertSame(['atelier:testimonials'], $this->storedIds($id));
    $this->assertSame(['testimonials'], array_column($this->store()->load($id)['sections'], 'component'));

    $result = $this->store()->applyOps($this->store()->load($id), [['op' => 'update_section', 'id' => 'quote01', 'props' => ['heading' => 'Press']]], TRUE);
    $this->assertSame([], $result['rejected']);
  }

}
