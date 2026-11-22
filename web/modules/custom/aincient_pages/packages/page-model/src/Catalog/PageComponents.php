<?php

declare(strict_types=1);

namespace Aincient\PageModel\Catalog;

use Aincient\PageModel\ComponentCatalog;
use Aincient\PageModel\ComponentId;

/**
 * What a component reference means on one page (DECISIONS 0539).
 *
 * The one rule, for every reader and writer of a page: a SHORT name means the
 * component the page's kind offers under it, else the one the site offers
 * (a slot the kind has switched off still renders, 0455); a FULL id means
 * exactly that component. A stored full id reads back short only when the
 * short name means exactly it — so a page holding the other one of a
 * same-name pair keeps its full id and can never be re-read as the wrong one,
 * and a site with no pair reads every name short, as before.
 */
final class PageComponents {

  public function __construct(
    private readonly EffectiveCatalog $kind,
    private readonly EffectiveCatalog $discovered,
  ) {}

  /**
   * The full id a short name means on this page, or NULL when nothing on the
   * site is called that.
   */
  public function offered(string $name): ?string {
    foreach ([$this->kind, $this->discovered] as $catalog) {
      $def = $catalog->def($name);
      if ($def !== NULL) {
        $provider = (string) ($def['provider'] ?? '');
        // A virtual def (`block`) is stored as a built-in id since 0.30.0.
        return ($provider === '' ? ComponentCatalog::BUILTIN_PROVIDER : $provider) . ':' . $name;
      }
    }
    return NULL;
  }

  /**
   * The full id to STORE for a reference: a full id as is (an old built-in
   * id bridged), a short name as {@see offered()}, an unknown name as
   * `unresolved:<name>`; empty stays empty.
   */
  public function resolve(string $ref): string {
    if ($ref === '') {
      return '';
    }
    if (str_contains($ref, ':')) {
      return ComponentId::bridge($ref) ?? $ref;
    }
    return $this->offered($ref) ?? ComponentId::UNRESOLVED . ':' . $ref;
  }

  /**
   * The form a stored id READS as: short when the short name means exactly
   * it (or nothing on the site has that name), the full id otherwise. An `unresolved:` name reads as its name (it
   * renders again once its pack is back, as since 0.30.0).
   */
  public function canonical(string $id): string {
    if (!str_contains($id, ':')) {
      return $id;
    }
    $id = ComponentId::bridge($id) ?? $id;
    $name = ComponentId::short($id);
    if (str_starts_with($id, ComponentId::UNRESOLVED . ':')) {
      return $name;
    }
    // Short when the short name means exactly it — or when nothing on the
    // site is called that (a pack since uninstalled): it reads as before
    // 0539, and the save drops it as it always did.
    $offered = $this->offered($name);
    return $offered === $id || $offered === NULL ? $name : $id;
  }

  /**
   * The name the site-wide palette resolves to the same component — what the
   * renderer looks definitions up by (it renders against discovery, 0455).
   */
  public function forDiscovered(string $ref): string {
    $id = $this->resolve($ref);
    if ($id === '' || str_starts_with($id, ComponentId::UNRESOLVED . ':')) {
      return ComponentId::short($id);
    }
    $name = ComponentId::short($id);
    $def = $this->discovered->def($name);
    $provider = $def === NULL ? NULL : (string) ($def['provider'] ?? '');
    $own = $provider === NULL ? NULL : ($provider === '' ? ComponentCatalog::BUILTIN_PROVIDER : $provider) . ':' . $name;
    return $own === $id ? $name : $id;
  }

  /**
   * Sections holding a component other than the one the kind now offers
   * under the same short name — the older one of a pair. Editing such a page
   * waits for the owner's switch (0539). A component the kind does not offer
   * at all is not listed (it is a kept slot, 0455).
   *
   * @param array<int, array> $sections
   *   Schema sections (`id`, `component`).
   *
   * @return list<array{id: string, from: string, to: string, name: string}>
   */
  public function outdated(array $sections): array {
    $out = [];
    foreach ($sections as $section) {
      $ref = (string) ($section['component'] ?? '');
      if ($ref === '') {
        continue;
      }
      $name = ComponentId::short($ref);
      $def = $this->kind->placeable($name);
      if ($def === NULL) {
        continue;
      }
      $to = $this->offered($name);
      $from = $this->resolve($ref);
      if ($to !== NULL && $from !== $to) {
        $out[] = ['id' => (string) ($section['id'] ?? ''), 'from' => $from, 'to' => $to, 'name' => $name];
      }
    }
    return $out;
  }

  /**
   * Short names held by more than one component on this page — a mix the
   * page save refuses (0539).
   *
   * @param array<int, array> $sections
   *
   * @return array<string, list<string>>
   *   short name => the full ids.
   */
  public function mixes(array $sections): array {
    $byName = [];
    foreach ($sections as $section) {
      $id = $this->resolve((string) ($section['component'] ?? ''));
      if ($id === '' || str_starts_with($id, ComponentId::UNRESOLVED . ':')) {
        continue;
      }
      $byName[ComponentId::short($id)][$id] = TRUE;
    }
    $mixes = [];
    foreach ($byName as $name => $ids) {
      if (count($ids) > 1) {
        $mixes[$name] = array_keys($ids);
      }
    }
    return $mixes;
  }

}
