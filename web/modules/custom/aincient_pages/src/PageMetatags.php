<?php

declare(strict_types=1);

namespace Drupal\aincient_pages;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Drupal\path_alias\AliasManagerInterface;

/**
 * Resolves the metatag tag set for one rendered page.
 *
 * The chrome-less page shell builds its own `<head>`, so it asks metatag for
 * the tags directly instead of going through Drupal's page pipeline. Metatag's
 * public entity helper (`tagsFromEntityWithDefaults()`) walks only the ENTITY
 * default chain — global → entity type → bundle → the node's own field. The
 * FRONT-PAGE defaults (`metatag.metatag_defaults.front`) live in a different
 * branch of the manager (`getSpecialMetatags()`, reached only from the page
 * pipeline), so the shell never saw them: the site root rendered with the node
 * default `canonical_url: '[node:url]'` and every share of the home page
 * pointed at `/node/<id>` instead of `/` (issue #29).
 *
 * This class restores metatag's own precedence for the front page: global
 * merged with the `front` defaults REPLACES the entity-type/bundle chain (it
 * is an either/or in `MetatagManager::getDefaultMetatags()`), while the page's
 * own overrides still win over both.
 *
 * Front-page identity is decided from the effective `system.site:page.front`
 * (the value SiteInformationOverrider layers the studio identity onto), NOT
 * from `path.matcher`: `PathMatcher::isFrontPage()` matches the CURRENT route,
 * which is FALSE under CLI — and the static export renders every page from the
 * CLI, so a route-based check would fix the live site and leave the exported
 * home page broken.
 */
final class PageMetatags {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    // OPTIONAL (@?): path_alias is `required: true` in a real install, but
    // KernelTestBase enables only what a test lists — a hard reference here
    // fails container compile in every kernel test that omits it. Null just
    // means an alias-configured front page is not recognised, which no
    // Atelier install has (the identity slots resolve to `/node/<id>`).
    private readonly ?AliasManagerInterface $aliasManager = NULL,
    // OPTIONAL (@?): null when metatag is uninstalled, in which case a page
    // renders with no meta tags at all rather than fataling.
    private readonly ?object $metatagManager = NULL,
  ) {}

  /**
   * The metatag tag set (raw, token-bearing) for a node about to be rendered.
   *
   * @return array<string, string>
   *   Tag name => token/value, or [] when metatag is not installed.
   */
  public function tags(NodeInterface $node): array {
    if ($this->metatagManager === NULL) {
      return [];
    }
    if (!$this->isFrontPage($node)) {
      return $this->metatagManager->tagsFromEntityWithDefaults($node);
    }
    // Front page: the node's own overrides, then global + `front`.
    return $this->metatagManager->tagsFromEntity($node) + $this->frontDefaults();
  }

  /**
   * Whether this node is the site's configured front page.
   */
  public function isFrontPage(NodeInterface $node): bool {
    $front = trim((string) $this->configFactory->get('system.site')->get('page.front'));
    if ($front === '' || $node->id() === NULL) {
      return FALSE;
    }
    $internal = '/node/' . $node->id();
    if ($front === $internal) {
      return TRUE;
    }
    // An operator editing system.site by hand can point the front page at an
    // alias; the identity slots always resolve to `/node/<id>`. getPathByAlias()
    // returns its argument unchanged when there is no alias.
    return $this->aliasManager !== NULL
      && $this->aliasManager->getPathByAlias($front) === $internal;
  }

  /**
   * The global defaults merged with the front-page defaults.
   *
   * Mirrors the special-page branch of MetatagManager::getDefaultMetatags():
   * disabled defaults are skipped, and `front` wins key-by-key over `global`.
   *
   * @return array<string, string>
   */
  private function frontDefaults(): array {
    $storage = $this->entityTypeManager->getStorage('metatag_defaults');
    $tags = [];
    foreach (['global', 'front'] as $id) {
      $defaults = $storage->load($id);
      if ($defaults !== NULL && $defaults->status()) {
        $tags = array_merge($tags, $defaults->get('tags') ?: []);
      }
    }
    return $tags;
  }

}
