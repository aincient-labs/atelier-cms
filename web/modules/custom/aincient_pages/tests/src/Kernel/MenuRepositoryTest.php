<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Kernel;

use Drupal\aincient_pages\MenuRepository;
use Drupal\KernelTests\KernelTestBase;
use Drupal\menu_link_content\Entity\MenuLinkContent;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the inline-menu-editor backend: read + reconcile of chrome menus.
 *
 * @group aincient
 */
#[RunTestsInSeparateProcesses]
final class MenuRepositoryTest extends KernelTestBase {

  protected static $modules = [
    'system',
    'user',
    'link',
    'menu_link_content',
    'workflows', 'content_moderation', 'aincient_pages',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['workflows', 'content_moderation', 'aincient_pages']);
    $this->installEntitySchema('user');
    $this->installEntitySchema('menu_link_content');
  }

  private function repo(): MenuRepository {
    return $this->container->get('aincient_pages.menu_repository');
  }

  public function testTreeReadsEditableMenuAsFriendlyPaths(): void {
    MenuLinkContent::create(['title' => 'About', 'link' => ['uri' => 'internal:/about'], 'menu_name' => 'main', 'weight' => 0])->save();
    MenuLinkContent::create(['title' => 'GitHub', 'link' => ['uri' => 'https://example.com'], 'menu_name' => 'main', 'weight' => 1])->save();

    $tree = $this->repo()->tree('main');
    $this->assertSame(['About', 'GitHub'], array_column($tree, 'title'));
    // internal: stripped to a friendly path; external URL passes through.
    $this->assertSame('/about', $tree[0]['url']);
    $this->assertSame('https://example.com', $tree[1]['url']);
  }

  public function testNonEditableMenuIsIgnored(): void {
    $this->assertSame([], $this->repo()->tree('admin'));
    $this->assertSame([], $this->repo()->sync('admin', [['title' => 'X', 'url' => '/x']]));
    $this->assertFalse(MenuRepository::isEditable('admin'));
    $this->assertTrue(MenuRepository::isEditable('main'));
  }

  public function testSyncCreatesUpdatesAndDeletes(): void {
    // Seed two links.
    $saved = $this->repo()->sync('main', [
      ['title' => 'Home', 'url' => '/'],
      ['title' => 'Docs', 'url' => '/docs'],
    ]);
    $this->assertSame(['Home', 'Docs'], array_column($saved, 'title'));
    [$home, $docs] = $saved;

    // Rename Docs (keep its id), drop Home, append Blog.
    $next = $this->repo()->sync('main', [
      ['id' => $docs['id'], 'title' => 'Guides', 'url' => '/guides'],
      ['title' => 'Blog', 'url' => '/blog'],
    ]);

    $this->assertSame(['Guides', 'Blog'], array_column($next, 'title'));
    // The kept link reused its id; Home is gone; order = weight.
    $this->assertSame($docs['id'], $next[0]['id']);
    $this->assertSame('/guides', $next[0]['url']);
    $this->assertNotContains($home['id'], array_column($next, 'id'));
    // Exactly two editable links remain in the menu.
    $this->assertCount(2, $this->repo()->tree('main'));
  }

  public function testSyncStoresUriSchemes(): void {
    $this->repo()->sync('footer', [
      ['title' => 'Root', 'url' => '/'],
      ['title' => 'Path', 'url' => 'contact'],
      ['title' => 'External', 'url' => 'https://example.com/x'],
    ]);
    $tree = $this->repo()->tree('footer');
    $this->assertSame('/', $tree[0]['url']);
    // A bare path is normalised to a leading slash on read-back.
    $this->assertSame('/contact', $tree[1]['url']);
    $this->assertSame('https://example.com/x', $tree[2]['url']);
  }

  public function testTitlelessLinkDropped(): void {
    $saved = $this->repo()->sync('main', [
      ['title' => 'Keep', 'url' => '/'],
      ['title' => '   ', 'url' => '/blank'],
    ]);
    $this->assertSame(['Keep'], array_column($saved, 'title'));
  }

  public function testTreeReturnsNestedChildren(): void {
    $parent = MenuLinkContent::create(['title' => 'Products', 'link' => ['uri' => 'internal:/products'], 'menu_name' => 'main', 'weight' => 0]);
    $parent->save();
    $child = MenuLinkContent::create(['title' => 'Widgets', 'link' => ['uri' => 'internal:/widgets'], 'menu_name' => 'main', 'weight' => 0, 'parent' => $parent->getPluginId()]);
    $child->save();
    $grand = MenuLinkContent::create(['title' => 'Blue', 'link' => ['uri' => 'internal:/blue'], 'menu_name' => 'main', 'weight' => 0, 'parent' => $child->getPluginId()]);
    $grand->save();

    $tree = $this->repo()->tree('main');
    $this->assertCount(1, $tree);
    $this->assertSame('Products', $tree[0]['title']);
    $this->assertSame(['Widgets'], array_column($tree[0]['children'], 'title'));
    $this->assertSame(['Blue'], array_column($tree[0]['children'][0]['children'], 'title'));
    // The leaf carries an (empty) children list — the shape is uniform at every depth.
    $this->assertSame([], $tree[0]['children'][0]['children'][0]['children']);
  }

  public function testSyncCreatesNestedTree(): void {
    $saved = $this->repo()->sync('main', [
      [
        'title' => 'Products',
        'url' => '/products',
        'children' => [
          ['title' => 'Widgets', 'url' => '/widgets', 'children' => [
            ['title' => 'Blue', 'url' => '/blue'],
          ]],
          ['title' => 'Gadgets', 'url' => '/gadgets'],
        ],
      ],
      ['title' => 'About', 'url' => '/about'],
    ]);

    $this->assertSame(['Products', 'About'], array_column($saved, 'title'));
    // Sibling order at each level is preserved as weight.
    $this->assertSame(['Widgets', 'Gadgets'], array_column($saved[0]['children'], 'title'));
    $this->assertSame(['Blue'], array_column($saved[0]['children'][0]['children'], 'title'));
    // Five links persisted across the whole tree (Products, Widgets, Blue, Gadgets, About).
    $this->assertCount(5, $this->flatten($this->repo()->tree('main')));
  }

  public function testSyncReparentsAndDeletesSubtree(): void {
    $saved = $this->repo()->sync('main', [
      ['title' => 'Parent', 'url' => '/p', 'children' => [
        ['title' => 'Child', 'url' => '/c', 'children' => [
          ['title' => 'Grand', 'url' => '/g'],
        ]],
      ]],
    ]);
    $parentId = $saved[0]['id'];
    $childId = $saved[0]['children'][0]['id'];

    // Promote Child (keep its id) to the root and drop Parent. Child keeps a fresh Grand.
    $next = $this->repo()->sync('main', [
      ['id' => $childId, 'title' => 'Child', 'url' => '/c', 'children' => [
        ['title' => 'Grand', 'url' => '/g'],
      ]],
    ]);

    $this->assertSame(['Child'], array_column($next, 'title'));
    $this->assertSame($childId, $next[0]['id']);
    $this->assertSame(['Grand'], array_column($next[0]['children'], 'title'));
    // Parent (absent from the draft) was deleted along with the rest of its subtree.
    $this->assertNotContains($parentId, array_column($this->flatten($next), 'id'));

    // The promoted child's stored parent is actually cleared (not just surfaced
    // at the root because its old parent went missing).
    $child = $this->container->get('entity_type.manager')
      ->getStorage('menu_link_content')->load($childId);
    $this->assertSame('', (string) $child->get('parent')->value);
  }

  /**
   * Flatten a nested tree (depth-first) into a single list of nodes.
   *
   * @param list<array<string, mixed>> $tree
   *
   * @return list<array<string, mixed>>
   */
  private function flatten(array $tree): array {
    $out = [];
    foreach ($tree as $node) {
      $children = $node['children'] ?? [];
      unset($node['children']);
      $out[] = $node;
      $out = array_merge($out, $this->flatten($children));
    }
    return $out;
  }

}
