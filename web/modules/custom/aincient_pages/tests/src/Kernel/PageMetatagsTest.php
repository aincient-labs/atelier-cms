<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests PageMetatags: the tag set the chrome-less page shell renders.
 *
 * The regression it guards (issue #29): the shell asked metatag's ENTITY
 * default chain, which never reaches `metatag.metatag_defaults.front`, so the
 * home page canonicalised to the node's own URL — `/node/1` on a site with no
 * alias, which is what visitors got when they shared it.
 *
 * @group aincient
 */
#[RunTestsInSeparateProcesses]
final class PageMetatagsTest extends KernelTestBase {

  protected static $modules = [
    'system', 'user', 'field', 'text', 'token', 'path', 'path_alias', 'node',
    'metatag', 'file', 'media', 'workflows', 'content_moderation',
    'aincient_core', 'aincient_pages',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('path_alias');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'metatag']);
    NodeType::create(['type' => 'aincient_page', 'name' => 'AIncient page'])->save();
  }

  private function metatags(): \Drupal\aincient_pages\PageMetatags {
    return $this->container->get('aincient_pages.metatags');
  }

  private function makePage(string $title): Node {
    $node = Node::create(['type' => 'aincient_page', 'title' => $title, 'status' => 1]);
    $node->save();
    return $node;
  }

  /** Points the effective front page at an internal path. */
  private function setFront(string $path): void {
    $this->config('system.site')->set('page.front', $path)->save();
  }

  /**
   * The front page canonicalises to the SITE ROOT, not to its node URL.
   */
  public function testFrontPageUsesFrontDefaults(): void {
    $node = $this->makePage('Home');
    $this->setFront('/node/' . $node->id());

    $tags = $this->metatags()->tags($node);
    $this->assertSame('[site:url]', $tags['canonical_url']);
    $this->assertSame('[site:url]', $tags['shortlink']);
  }

  /**
   * Every other page keeps its own canonical — the front default is not global.
   */
  public function testInnerPageKeepsNodeCanonical(): void {
    $front = $this->makePage('Home');
    $inner = $this->makePage('Rooms');
    $this->setFront('/node/' . $front->id());

    $tags = $this->metatags()->tags($inner);
    $this->assertSame('[node:url]', $tags['canonical_url']);
    $this->assertArrayNotHasKey('shortlink', $tags);
  }

  /**
   * A front page configured by ALIAS is still recognised as the front page.
   *
   * The identity slots always resolve to `/node/<id>`, but an operator editing
   * system.site by hand can point it at an alias.
   */
  public function testFrontPageConfiguredByAlias(): void {
    $node = $this->makePage('Home');
    $this->container->get('entity_type.manager')->getStorage('path_alias')->create([
      'path' => '/node/' . $node->id(),
      'alias' => '/welcome',
    ])->save();
    $this->setFront('/welcome');

    $this->assertTrue($this->metatags()->isFrontPage($node));
    $this->assertSame('[site:url]', $this->metatags()->tags($node)['canonical_url']);
  }

  /**
   * The page's OWN override still wins over the front-page default.
   */
  public function testPageOverrideBeatsFrontDefault(): void {
    FieldStorageConfig::create([
      'field_name' => 'field_metatag',
      'entity_type' => 'node',
      'type' => 'metatag',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_metatag',
      'entity_type' => 'node',
      'bundle' => 'aincient_page',
    ])->save();

    $node = $this->makePage('Home');
    $node->set('field_metatag', json_encode(['canonical_url' => 'https://example.com/custom']))->save();
    $this->setFront('/node/' . $node->id());

    $this->assertSame('https://example.com/custom', $this->metatags()->tags($node)['canonical_url']);
  }

  /**
   * No front page configured (or a slot pointing elsewhere) → node defaults.
   */
  public function testNoFrontPageConfigured(): void {
    $node = $this->makePage('Home');
    $this->setFront('');

    $this->assertFalse($this->metatags()->isFrontPage($node));
    $this->assertSame('[node:url]', $this->metatags()->tags($node)['canonical_url']);
  }

}
