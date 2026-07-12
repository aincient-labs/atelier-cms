<?php

declare(strict_types=1);

namespace Drupal\aincient_pages\Reference;

/**
 * One referenceable entity TYPE in the unified reference layer.
 *
 * The page schema stores every reference to a Drupal entity as a plain text
 * TOKEN (`media:<id>`, `entity:<type>:<id>[@vm]`, `block:<id>` —
 * {@see \Drupal\aincient_pages\EntityEmbedResolver}). A provider is the seam
 * between one such type and the two things the authoring UI needs of any
 * reference: SEARCH (browse to pick one) and DESCRIBE (resolve a stored token to
 * a preview). Both return the same uniform DESCRIPTOR
 * ({@see ReferenceDescriptor::create()}), so the picker, the in-field preview and
 * the agent's find tool are type-agnostic — adding Users or Brand later is one new
 * tagged provider, no change to the catalog, controller, React field or agent.
 *
 * Providers are collected by {@see ReferenceCatalog} via the
 * `aincient_pages.reference_provider` service tag, keyed by {@see typeKey()} (which
 * must match the `type` {@see \Drupal\aincient_pages\EntityEmbedResolver::parse()}
 * returns for that token shape).
 */
interface ReferenceProviderInterface {

  /**
   * The reference type key — matches EntityEmbedResolver::parse()['type'].
   *
   * E.g. `media`, `node`, `block`. Both the catalog's `types` filter and its
   * token dispatch key on this.
   */
  public function typeKey(): string;

  /**
   * Search this type for pickable references, newest first.
   *
   * @param string|null $query
   *   Optional name/title substring; NULL or '' lists the most recent.
   * @param int $limit
   *   Maximum rows.
   *
   * @return array<int, array{token: string, type: string, label: string, description: string, thumb: ?string, status: ?string, edit_url: ?string, meta: array}>
   *   Uniform descriptors ({@see ReferenceDescriptor::create()}).
   */
  public function search(?string $query, int $limit): array;

  /**
   * Resolve one entity id of this type to a descriptor, or NULL if missing.
   *
   * @return array{token: string, type: string, label: string, description: string, thumb: ?string, status: ?string, edit_url: ?string, meta: array}|null
   */
  public function describe(int $id): ?array;

}
