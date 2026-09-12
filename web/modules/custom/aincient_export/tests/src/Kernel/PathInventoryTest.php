<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_export\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\path_alias\Entity\PathAlias;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * @coversDefaultClass \Drupal\aincient_export\PathInventory
 * @group aincient_export
 */
#[RunTestsInSeparateProcesses]
final class PathInventoryTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'node',
    'file',
    'image',
    'path_alias',
    'language',
    'aincient_export',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('path_alias');
    $this->installConfig(['system', 'node', 'filter', 'language']);
    NodeType::create(['type' => 'aincient_page', 'name' => 'Page'])->save();
    ConfigurableLanguage::createFromLangcode('de')->save();
    ConfigurableLanguage::createFromLangcode('fr')->save();
    $this->config('language.negotiation')
      ->set('url.source', 'path_prefix')
      ->set('url.prefixes', ['en' => '', 'de' => 'de', 'fr' => 'fr'])
      ->save();
    // LanguageServiceProvider registers the outbound path processor ONLY when
    // the site is multilingual as the container is compiled — which, in a
    // kernel test, is before these languages exist. Without the rebuild no URL
    // ever gets a `/de` prefix and the multilingual assertions are vacuous.
    $this->container->get('kernel')->rebuildContainer();
  }

  /**
   * Every enabled language contributes its own front page, and a page is
   * enumerated once per translation that actually exists — not once per
   * language. A snapshot holding only the default language falls through to
   * live Drupal for every translated URL (pitch-demo report, 12 Sep 2026).
   *
   * @covers ::collect
   */
  public function testCollectEnumeratesEveryEnabledLanguage(): void {
    $node = Node::create(['type' => 'aincient_page', 'title' => 'Only English', 'status' => 1]);
    $node->save();

    $paths = $this->container->get('aincient_export.path_inventory')->collect();

    // One front page per language, each carrying its prefix.
    $this->assertContains('/', $paths);
    $this->assertContains('/de', $paths);
    $this->assertContains('/fr', $paths);
    // The node has no translations, so it is enumerated ONCE, not three times.
    $this->assertContains('/node/' . $node->id(), $paths);
    $this->assertNotContains('/de/node/' . $node->id(), $paths);
    $this->assertNotContains('/fr/node/' . $node->id(), $paths);
    // Nothing is enumerated twice.
    $this->assertSame(array_values(array_unique($paths)), $paths);
  }

  /**
   * @covers ::collect
   */
  public function testCollect(): void {
    $published = Node::create(['type' => 'aincient_page', 'title' => 'Published', 'status' => 1]);
    $published->save();
    PathAlias::create(['path' => '/node/' . $published->id(), 'alias' => '/pages/published'])->save();

    $unpublished = Node::create(['type' => 'aincient_page', 'title' => 'Draft', 'status' => 0]);
    $unpublished->save();

    $unaliased = Node::create(['type' => 'aincient_page', 'title' => 'No alias', 'status' => 1]);
    $unaliased->save();

    $paths = $this->container->get('aincient_export.path_inventory')->collect();

    $this->assertContains('/', $paths);
    $this->assertContains('/pages/published', $paths);
    $this->assertContains('/node/' . $unaliased->id(), $paths);
    $this->assertNotContains('/node/' . $unpublished->id(), $paths);

    // The configured front page is deduplicated — it already exports as "/".
    $this->config('system.site')->set('page.front', '/pages/published')->save();
    $paths = $this->container->get('aincient_export.path_inventory')->collect();
    $this->assertContains('/', $paths);
    $this->assertNotContains('/pages/published', $paths);
  }

}
