<?php

declare(strict_types=1);

namespace Drupal\aincient_pages;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Reads and reconciles the editable links of the site's chrome menus.
 *
 * The Header/Footer studios edit the `main` and `footer` menus inline (the
 * "modern console, not stock admin widgets" north star) rather than linking out
 * to /admin/structure/menu. Only user-authored `menu_link_content` entities are
 * managed here — links defined by modules in code/config are left untouched.
 * Like the rest of the studio, edits are a CLIENT-SIDE DRAFT; nothing is written
 * until Publish calls {@see sync()}, which reconciles the live menu to match the
 * draft (create new, update changed, reorder by position, delete removed). The
 * live nav ({@see SiteChrome::nav()}) then reflects the change with no extra wiring.
 */
final class MenuRepository {

  /** The chrome menus the studio may edit — never an arbitrary menu name. */
  public const EDITABLE = ['main', 'footer'];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  public static function isEditable(string $menu): bool {
    return in_array($menu, self::EDITABLE, TRUE);
  }

  private function storage(): EntityStorageInterface {
    return $this->entityTypeManager->getStorage('menu_link_content');
  }

  /**
   * The editable links of a chrome menu as a nested tree, in menu order.
   *
   * Each node is `{id, title, url, enabled, children: [...]}`; `children` holds
   * the same shape recursively (empty for a leaf). A child references its parent
   * by the parent's menu-link plugin id (`menu_link_content:<uuid>`); a link whose
   * parent is not itself an editable link of this menu is surfaced at the root so
   * it can never silently disappear.
   *
   * @return list<array{id: int, title: string, url: string, enabled: bool, children: list<array<string, mixed>>}>
   */
  public function tree(string $menu): array {
    if (!self::isEditable($menu)) {
      return [];
    }
    $ids = $this->storage()->getQuery()
      ->accessCheck(FALSE)
      ->condition('menu_name', $menu)
      ->sort('weight')
      ->sort('id')
      ->execute();
    $entities = $this->storage()->loadMultiple($ids);

    // Index this menu's own links by plugin id so we can tell a real parent
    // reference from a dangling one (module-defined / cross-menu parent).
    $own = [];
    foreach ($entities as $entity) {
      $own['menu_link_content:' . $entity->uuid()] = TRUE;
    }

    // Group entities under their parent plugin id ('' = root), preserving the
    // weight/id sort, then build the tree top-down.
    $childrenOf = [];
    foreach ($entities as $entity) {
      $parent = (string) $entity->get('parent')->value;
      if ($parent !== '' && !isset($own[$parent])) {
        $parent = '';
      }
      $childrenOf[$parent][] = $entity;
    }

    return $this->buildLevel($childrenOf, '');
  }

  /**
   * Recursively build the children of one parent plugin id into tree nodes.
   *
   * @param array<string, list<\Drupal\menu_link_content\MenuLinkContentInterface>> $childrenOf
   *
   * @return list<array{id: int, title: string, url: string, enabled: bool, children: list<array<string, mixed>>}>
   */
  private function buildLevel(array $childrenOf, string $parent): array {
    $out = [];
    foreach ($childrenOf[$parent] ?? [] as $entity) {
      $out[] = [
        'id' => (int) $entity->id(),
        'title' => (string) $entity->getTitle(),
        'url' => $this->uriToPath((string) $entity->get('link')->uri),
        'enabled' => (bool) $entity->isEnabled(),
        'children' => $this->buildLevel($childrenOf, 'menu_link_content:' . $entity->uuid()),
      ];
    }
    return $out;
  }

  /**
   * Reconcile a chrome menu's editable links to match the studio draft.
   *
   * The draft is a nested tree; each node is `{id?, title, url, enabled, children?}`
   * and sibling order IS the weight. Existing links with a matching id are updated
   * (and may be re-parented); new ones (no id) are created; existing links absent
   * from the draft — anywhere in the tree — are deleted. Nodes are saved pre-order
   * (parent before its children) so a freshly-created parent has a uuid for its
   * children to reference. Returns the saved tree (so the studio can re-seed its
   * baseline with server-assigned ids).
   *
   * @param list<array<string, mixed>> $links
   *
   * @return list<array{id: int, title: string, url: string, enabled: bool, children: list<array<string, mixed>>}>
   */
  public function sync(string $menu, array $links): array {
    if (!self::isEditable($menu)) {
      return [];
    }
    $existing = [];
    $ids = $this->storage()->getQuery()
      ->accessCheck(FALSE)
      ->condition('menu_name', $menu)
      ->execute();
    foreach ($this->storage()->loadMultiple($ids) as $entity) {
      $existing[(int) $entity->id()] = $entity;
    }

    $kept = [];
    $this->syncLevel($menu, $links, '', $existing, $kept);

    // Delete the editable links the draft dropped (at any depth).
    $remove = array_filter($existing, static fn($e) => empty($kept[(int) $e->id()]));
    if ($remove) {
      $this->storage()->delete($remove);
    }

    return $this->tree($menu);
  }

  /**
   * Save one level of the draft tree under $parent, then recurse into children.
   *
   * @param list<mixed> $links
   * @param array<int, \Drupal\menu_link_content\MenuLinkContentInterface> $existing
   * @param array<int, true> $kept
   */
  private function syncLevel(string $menu, array $links, string $parent, array $existing, array &$kept): void {
    $weight = 0;
    foreach ($links as $link) {
      if (!is_array($link)) {
        continue;
      }
      $title = trim((string) ($link['title'] ?? ''));
      if ($title === '') {
        // A titleless link is meaningless in the nav — drop it (and its subtree).
        continue;
      }
      $uri = $this->pathToUri((string) ($link['url'] ?? ''));
      $enabled = !isset($link['enabled']) || (bool) $link['enabled'];
      $id = isset($link['id']) ? (int) $link['id'] : 0;

      $entity = ($id && isset($existing[$id])) ? $existing[$id] : NULL;
      if ($entity === NULL) {
        $entity = $this->storage()->create(['menu_name' => $menu]);
      }
      $entity->set('title', $title);
      $entity->set('link', ['uri' => $uri]);
      $entity->set('enabled', $enabled);
      $entity->set('weight', $weight++);
      $entity->set('parent', $parent);
      $entity->save();
      $kept[(int) $entity->id()] = TRUE;

      $children = (isset($link['children']) && is_array($link['children'])) ? $link['children'] : [];
      if ($children) {
        $this->syncLevel($menu, $children, 'menu_link_content:' . $entity->uuid(), $existing, $kept);
      }
    }
  }

  /**
   * A stored menu-link `uri` as a friendly editor path (or reference token).
   *
   * `internal:/about` → `/about`; `internal:/` / `route:<front>` → `/`; an
   * external URL passes through; a page reference `entity:node/5` → the console's
   * reference TOKEN `entity:node:5` (colon form — the grammar the studio's
   * ReferenceField / reference layer speak, {@see EntityEmbedResolver}); anything
   * else is returned raw so the editor at least round-trips it.
   */
  private function uriToPath(string $uri): string {
    if ($uri === 'internal:/' || $uri === 'route:<front>') {
      return '/';
    }
    if (str_starts_with($uri, 'internal:')) {
      return substr($uri, strlen('internal:'));
    }
    // Core stores an entity link as `entity:<type>/<id>`; the console addresses
    // the same entity by a colon-delimited reference token — translate so a
    // page-reference link round-trips into the studio's page picker.
    if (preg_match('#^entity:([a-z][a-z0-9_]*)/(\d+)$#', $uri, $m)) {
      return 'entity:' . $m[1] . ':' . $m[2];
    }
    return $uri;
  }

  /**
   * A friendly editor path (or reference token) as a storable menu-link `uri`.
   *
   * `/about` → `internal:/about`; an http(s) URL passes through; `<front>` and
   * `` → `internal:/`; a bare `about` → `internal:/about`. A page-reference TOKEN
   * `entity:node:5` (the studio picker's value) → core's `entity:node/5` uri, so
   * the live nav resolves it to the node's canonical/aliased URL for free
   * ({@see SiteChrome::nav()}). Other already-schemed values
   * (internal:/route:/tel:/mailto:) pass through unchanged.
   */
  private function pathToUri(string $path): string {
    $path = trim($path);
    if ($path === '' || $path === '<front>' || $path === '/') {
      return 'internal:/';
    }
    if (preg_match('#^https?://#i', $path)) {
      return $path;
    }
    // A console reference token (colon form) → core's entity uri (slash form).
    if (preg_match('#^entity:([a-z][a-z0-9_]*):(\d+)$#', $path, $m)) {
      return 'entity:' . $m[1] . '/' . $m[2];
    }
    if (preg_match('#^(internal|entity|route|tel|mailto):#', $path)) {
      return $path;
    }
    return 'internal:/' . ltrim($path, '/');
  }

}
