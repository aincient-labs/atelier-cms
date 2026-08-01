<?php

declare(strict_types=1);

namespace Drupal\aincient_pages;

/**
 * The bounded shortlist of link destinations injected into the page agent's
 * prompt — the site's own MAIN MENU, never its page list.
 *
 * The agent has to be able to answer "link this button to our pricing page"
 * without being told the URL. Two ways to give it that, and only one scales:
 *
 *  - INJECT the site's pages. Unbounded (a site heading for 1000 pages ships
 *    1000 lines of prompt on every turn), stale the moment a page is added, and
 *    mostly irrelevant to any one request. Never do this.
 *  - RETRIEVE on demand — `find_reference` (types "node") searches the same
 *    catalog the studio picker uses and returns tokens. O(1) prompt cost at any
 *    site size. This is the general answer, and it is already wired.
 *
 * Retrieval alone has one gap: the agent only searches if it knows there is
 * something to search FOR. So we inject the one list that is bounded by design —
 * the main menu. It is the site's OWN curated shortlist of destinations (a
 * handful of items, sized by navigation design rather than content volume: eight
 * links whether the site has ten pages or ten thousand), a human chose every
 * entry, and it is almost exactly the set a hero CTA points at in practice.
 *
 * The fit is exact rather than convenient: {@see MenuRepository::tree()} already
 * returns each link's target as either a path (`/about`), an external URL, or an
 * `entity:node:<id>` token — precisely the three forms a link prop accepts
 * ({@see ComponentCatalog::LINK_PROPS}), so nothing is translated on the way in
 * and the agent can copy a target through verbatim.
 *
 * The long tail stays with `find_reference`, and the note says so — an agent
 * that mistook this for the whole site would tell the user a page doesn't exist.
 */
final class SiteDestinations {

  /**
   * The menu that IS the shortlist — the primary navigation.
   */
  private const MENU = 'main';

  /**
   * A hard ceiling on emitted links, so a pathological mega-menu can't grow the
   * prompt without bound. Well above any sane primary nav; when it bites, the
   * note SAYS it was truncated rather than silently presenting a partial list as
   * complete.
   */
  private const MAX_LINKS = 30;

  public function __construct(
    private readonly MenuRepository $menus,
  ) {}

  /**
   * The prompt block, or '' when there is no navigation to describe.
   *
   * Empty output is the honest answer for a fresh site whose menu nobody has
   * built yet: the heading alone would imply a shortlist exists and quietly
   * discourage the `find_reference` call that is the right move there.
   */
  public function forPrompt(): string {
    $lines = [];
    $truncated = $this->flatten($this->menus->tree(self::MENU), 0, $lines);
    if ($lines === []) {
      return '';
    }
    $out = [
      'SITE DESTINATIONS — this site\'s main menu: the pages its own navigation points at,',
      'as targets you can put straight into a link prop (cta_url / secondary_url / a logo\'s url).',
      'This is the NAV, not the whole site — it is a curated handful, and the site almost',
      'certainly has more pages. For anything not listed, call find_reference (types "node").',
    ];
    if ($truncated) {
      $out[] = sprintf('(Only the first %d links are shown — the menu is longer.)', self::MAX_LINKS);
    }
    return implode("\n", array_merge($out, $lines));
  }

  /**
   * Flatten the menu tree into `- "Title" → <target>` lines, indented by depth.
   *
   * Disabled links are skipped: they are not part of the live navigation, so
   * offering one as a destination would surface something the operator hid.
   * A link with no title or no target is skipped for the same reason — it is not
   * usable as a CTA target.
   *
   * @param list<array<string, mixed>> $tree
   *   Menu nodes as returned by {@see MenuRepository::tree()}.
   * @param int $depth
   *   Current nesting depth (0 = top level), used for indentation only.
   * @param list<string> $lines
   *   Accumulator, appended to in place.
   *
   * @return bool
   *   TRUE if the cap was hit and links were dropped.
   */
  private function flatten(array $tree, int $depth, array &$lines): bool {
    foreach ($tree as $link) {
      if (count($lines) >= self::MAX_LINKS) {
        return TRUE;
      }
      $title = trim((string) ($link['title'] ?? ''));
      $target = trim((string) ($link['url'] ?? ''));
      if (!empty($link['enabled']) && $title !== '' && $target !== '') {
        $lines[] = sprintf('%s- "%s" → %s', str_repeat('  ', $depth + 1), $title, $target);
      }
      $children = is_array($link['children'] ?? NULL) ? $link['children'] : [];
      if ($children !== [] && $this->flatten($children, $depth + 1, $lines)) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
