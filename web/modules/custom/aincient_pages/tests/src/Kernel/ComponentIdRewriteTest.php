<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\aincient_pages\Update\ComponentIdRewrite;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The 0.30.0 rewrite of stored component names (DECISIONS 0535/0536, m0-6).
 *
 * Pages are written the way 0.29.0 stored them — bare names, straight to the
 * fields — in several revisions and two languages, with accordion children in
 * the content layer, a prop value equal to a component name, and a section
 * whose component no installed module provides. The rewrite must reach every
 * row (failure modes S2.3), walk known paths only (S2.5), keep the unknown
 * as `unresolved:` (S2.4), change nothing the page renders, change nothing on
 * a second run, and give the unknown its id back once its pack is installed
 * (S6.3).
 *
 * @group aincient
 */
#[RunTestsInSeparateProcesses]
final class ComponentIdRewriteTest extends KernelTestBase {

  protected static $modules = [
    'system', 'user', 'field', 'text', 'node', 'language', 'workflows', 'content_moderation',
    'aincient_core', 'atelier', 'aincient_pages',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['language']);
    ConfigurableLanguage::createFromLangcode('de')->save();
    NodeType::create(['type' => 'aincient_page', 'name' => 'Page', 'new_revision' => TRUE])->save();
    foreach (array_keys(\Drupal\aincient_pages\Update\ComponentIdRewrite::FIELDS) as $name) {
      FieldStorageConfig::create(['field_name' => $name, 'entity_type' => 'node', 'type' => 'string_long', 'translatable' => TRUE])->save();
      FieldConfig::create(['field_name' => $name, 'entity_type' => 'node', 'bundle' => 'aincient_page', 'label' => $name, 'translatable' => TRUE])->save();
    }
    // The derived usage index — keys stay short in A0 (failure modes S7).
    FieldStorageConfig::create(['field_name' => 'field_component_usage', 'entity_type' => 'node', 'type' => 'string', 'cardinality' => -1, 'translatable' => FALSE])->save();
    FieldConfig::create(['field_name' => 'field_component_usage', 'entity_type' => 'node', 'bundle' => 'aincient_page', 'label' => 'Usage'])->save();
    require_once dirname(__DIR__, 3) . '/aincient_pages.post_update.php';
  }

  /**
   * A page stored the 0.29.0 way: two revisions, a German translation.
   */
  private function legacyPage(): NodeInterface {
    $structure = ['type' => 'landing', 'slots' => [
      ['id' => 'a', 'component' => 'hero', 'variant' => 'centered'],
      ['id' => 'b', 'component' => 'accordion'],
      ['id' => 'c', 'component' => 'spotlight'],
    ]];
    $content = fn(string $heading) => ['title' => 'Home', 'slots' => [
      'a' => ['heading' => $heading],
      'b' => ['panels' => [['label' => 'One', 'blocks' => [['component' => 'markdown', 'props' => ['markdown' => 'x']]]]]],
      'c' => ['heading' => 'Shot'],
    ]];
    $node = Node::create([
      'type' => 'aincient_page', 'title' => 'Home', 'status' => 1,
      'field_page_structure' => json_encode($structure),
      'field_page_content' => json_encode($content('hero')),
      'field_component_usage' => ['c:hero', 'v:hero:centered', 'c:accordion'],
    ]);
    $node->save();
    $node->set('field_page_content', json_encode($content('Second revision')));
    $node->setNewRevision();
    $node->save();
    $de = $node->addTranslation('de', [
      'title' => 'Startseite',
      'field_page_structure' => '',
      'field_page_content' => json_encode($content('Hallo')),
    ]);
    $de->save();
    return $node;
  }

  private function rewrite(): string {
    $sandbox = [];
    $message = NULL;
    for ($i = 0; $i < 100 && ($sandbox['#finished'] ?? 0) < 1; $i++) {
      $message = aincient_pages_post_update_0300_component_ids($sandbox);
    }
    $this->assertGreaterThanOrEqual(1, $sandbox['#finished'], 'The rewrite finishes.');
    return (string) $message;
  }

  /**
   * Every stored component value, from every row of both tables of a field.
   *
   * @return string[]
   */
  private function stored(string $field): array {
    $values = [];
    foreach (["node__$field", "node_revision__$field"] as $table) {
      foreach ($this->container->get('database')->select($table, 'f')->fields('f', [$field . '_value'])->execute()->fetchCol() as $json) {
        $data = json_decode((string) $json, TRUE);
        if (!is_array($data)) {
          continue;
        }
        foreach ($data['slots'] ?? [] as $slot) {
          if (isset($slot['component'])) {
            $values[] = $slot['component'];
          }
          foreach ($slot['panels'] ?? [] as $panel) {
            foreach ($panel['blocks'] ?? [] as $block) {
              $values[] = $block['component'];
            }
          }
        }
      }
    }
    return array_values(array_unique($values));
  }

  private function resolved(NodeInterface $node, string $langcode): array {
    $this->container->get('entity_type.manager')->getStorage('node')->resetCache();
    $fresh = $this->container->get('entity_type.manager')->getStorage('node')->load($node->id());
    return $this->container->get('aincient_pages.store')->resolve($fresh->getTranslation($langcode));
  }

  public function testEveryRowEveryLanguageIsRewritten(): void {
    $node = $this->legacyPage();
    $before = ['en' => $this->resolved($node, 'en'), 'de' => $this->resolved($node, 'de')];

    $message = $this->rewrite();

    $this->assertEqualsCanonicalizing(['atelier:hero', 'atelier:accordion', 'unresolved:spotlight'], $this->stored('field_page_structure'));
    $this->assertSame(['atelier:markdown'], $this->stored('field_page_content'), 'Accordion children, every revision and language.');
    $raw = json_decode((string) $this->container->get('database')->query("SELECT field_page_content_value FROM {node_revision__field_page_content} WHERE langcode = 'en' ORDER BY revision_id LIMIT 1")->fetchField(), TRUE);
    $this->assertSame('hero', $raw['slots']['a']['heading'], 'A prop value equal to a component name is not a component.');

    $this->assertSame($before['en'], $this->resolved($node, 'en'), 'The page reads the same.');
    $this->assertSame($before['de'], $this->resolved($node, 'de'));
    $this->assertStringContainsString('1 unresolved names kept', $message);

    $usage = $this->container->get('database')->select('node_revision__field_component_usage', 'u')->fields('u', ['field_component_usage_value'])->execute()->fetchCol();
    $this->assertEqualsCanonicalizing(['c:hero', 'v:hero:centered', 'c:accordion'], array_values(array_unique($usage)), 'The usage index is not rewritten (S7).');

    $again = $this->rewrite();
    $this->assertStringStartsWith('Component ids: 0 stored rows updated, 0 page kinds rewritten', $again, 'A second run changes nothing.');
  }

  /**
   * One page with far more revision rows than a step holds: paged by row, so
   * no step loads more than BATCH rows, and none is skipped or seen twice.
   */
  public function testAHeavilyEditedPageIsPagedByRow(): void {
    $node = $this->legacyPage();
    $db = $this->container->get('database');
    $extra = 2 * ComponentIdRewrite::BATCH + 37;
    $insert = $db->insert('node_revision__field_page_structure')
      ->fields(['bundle', 'deleted', 'entity_id', 'revision_id', 'langcode', 'delta', 'field_page_structure_value']);
    for ($i = 0; $i < $extra; $i++) {
      $insert->values(['aincient_page', 0, $node->id(), 1000 + $i, $i % 2 ? 'de' : 'en', 0, json_encode(['type' => 'landing', 'slots' => [['id' => 'a', 'component' => 'hero']]])]);
    }
    $insert->execute();
    $total = (int) $db->query('SELECT COUNT(*) FROM {node_revision__field_page_structure}')->fetchField();

    $sandbox = [];
    $rewrite = $this->container->get('aincient_pages.component_id_rewrite');
    $rewrite->start($sandbox);
    $steps = 0;
    while ($sandbox['#finished'] < 1 && $steps < 1000) {
      $before = $sandbox['counts']['node_revision__field_page_structure']['rows'] ?? 0;
      $rewrite->step($sandbox);
      $after = $sandbox['counts']['node_revision__field_page_structure']['rows'] ?? 0;
      $this->assertLessThanOrEqual(ComponentIdRewrite::BATCH, $after - $before, 'A step never holds more than BATCH rows.');
      $steps++;
    }
    $this->assertSame($total, $sandbox['counts']['node_revision__field_page_structure']['rows'], 'Every row seen exactly once.');
    $this->assertGreaterThanOrEqual(3, $steps);
    $bare = (int) $db->query("SELECT COUNT(*) FROM {node_revision__field_page_structure} WHERE field_page_structure_value LIKE '%\"component\":\"hero\"%'")->fetchField();
    $this->assertSame(0, $bare, 'No bare name left in any revision.');
  }

  public function testConfigIsRewritten(): void {
    $this->config('aincient_pages.page_kind.landing')->setData([
      'id' => 'landing', 'label' => 'Landing', 'mode' => 'composition',
      'components' => ['hero' => [], 'cta' => []], 'opener' => 'hero', 'limits' => ['cta' => 1], 'removed' => ['newsletter'],
    ])->save();
    $this->config('aincient_pages.site_constraint')->setData([
      'components' => ['pricing'], 'tones' => ['inverted'], 'variants' => ['hero' => ['split']],
      'component_tones' => ['cta' => ['brand']], 'overrides_off' => ['aincient_pages:hero'],
    ])->save();

    $message = $this->rewrite();

    $kind = $this->config('aincient_pages.page_kind.landing');
    $this->assertSame(['atelier:hero', 'atelier:cta'], array_keys($kind->get('components')));
    $this->assertSame('atelier:hero', $kind->get('opener'));
    $this->assertSame(['atelier:cta' => 1], $kind->get('limits'));
    $this->assertSame(['atelier:newsletter'], $kind->get('removed'));
    $constraint = $this->config('aincient_pages.site_constraint');
    $this->assertSame(['atelier:pricing'], $constraint->get('components'));
    $this->assertSame(['inverted'], $constraint->get('tones'));
    $this->assertSame(['atelier:hero' => ['split']], $constraint->get('variants'));
    $this->assertSame(['atelier:cta' => ['brand']], $constraint->get('component_tones'));
    $this->assertSame(['atelier:hero'], $constraint->get('overrides_off'));
    $this->assertStringContainsString('1 page kinds and the site constraint rewritten', $message);
  }

  /**
   * The pack that provides an unresolved name comes back: its sections do.
   */
  public function testInstallingThePackResolvesItsSections(): void {
    $this->legacyPage();
    $this->config('aincient_pages.site_constraint')->setData(['components' => ['spotlight']])->save();
    $this->rewrite();
    $this->assertContains('unresolved:spotlight', $this->stored('field_page_structure'));

    $this->container->get('module_installer')->install(['atelier_test_pack']);

    $this->assertContains('atelier_test_pack:spotlight', $this->stored('field_page_structure'));
    $this->assertNotContains('unresolved:spotlight', $this->stored('field_page_structure'));
    $this->assertSame(['atelier_test_pack:spotlight'], $this->config('aincient_pages.site_constraint')->get('components'));
  }

  /**
   * A straight 0.29 → A1 upgrade on a site that already has a pack sharing a
   * built-in's short name: a bare name in old data meant the built-in (the
   * gate refused the pack's then), so the rewrite keeps it there — it never
   * follows the pair choice onto the pack's component (DECISIONS 0539).
   */
  public function testOldBareNamesStayOnTheBuiltinWhenAPairExists(): void {
    $this->container->get('module_installer')->install(['atelier_test_pair_pack']);
    $this->assertSame('atelier_test_pair_pack', $this->container->get('aincient_pages.catalog')->discovered()->sections()['testimonials']['provider'], 'The pack wins the pair choice.');
    $node = Node::create([
      'type' => 'aincient_page', 'title' => 'Proof', 'status' => 1,
      'field_page_structure' => json_encode(['type' => 'landing', 'slots' => [['id' => 'q', 'component' => 'testimonials']]]),
      'field_page_content' => json_encode(['title' => 'Proof', 'slots' => ['q' => ['heading' => 'Kind words']]]),
    ]);
    $node->save();
    $this->rewrite();
    $this->assertSame(['atelier:testimonials'], $this->stored('field_page_structure'));
  }

}
