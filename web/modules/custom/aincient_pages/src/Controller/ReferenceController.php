<?php

declare(strict_types=1);

namespace Drupal\aincient_pages\Controller;

use Drupal\aincient_pages\Reference\ReferenceCatalog;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The unified JSON API behind the page studio's reference picker + preview.
 *
 * Collapses the three former picker endpoints (media list, embed find, block
 * list) into one SEARCH and one DESCRIBE over {@see ReferenceCatalog}, so every
 * referenceable type — media, nodes, blocks (Users/Brand later) — is browsed,
 * previewed and written back through one code path and one descriptor shape. The
 * studio's `<ReferenceField>` and the agent's find tool both read from here.
 * Gated like the other console endpoints (`administer aincient pages`).
 */
final class ReferenceController implements ContainerInjectionInterface {

  public function __construct(
    private readonly ReferenceCatalog $catalog,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self($container->get('aincient_pages.reference_catalog'));
  }

  /**
   * GET /aincient/reference/search — pickable references across types.
   *
   * `?types=media,node,block` (CSV; default all) and `?q=<text>`. Returns
   * `{ items: [ descriptor ] }` — see {@see \Drupal\aincient_pages\Reference\ReferenceDescriptor}.
   */
  public function search(Request $request): JsonResponse {
    $typesParam = (string) ($request->query->get('types') ?? '');
    $types = $typesParam !== ''
      ? array_values(array_filter(array_map('trim', explode(',', $typesParam))))
      : [];
    $q = $request->query->get('q');
    $items = $this->catalog->search($types, is_string($q) ? $q : NULL);
    return new JsonResponse(['items' => $items]);
  }

  /**
   * GET /aincient/reference/resolve — one stored token → a descriptor (preview).
   *
   * `?token=<token>`. Returns `{ item: descriptor|null }` (null for a malformed,
   * unknown-type or dangling reference, so the field can show a "missing" state).
   */
  public function resolve(Request $request): JsonResponse {
    $token = (string) ($request->query->get('token') ?? '');
    return new JsonResponse(['item' => $token !== '' ? $this->catalog->describe($token) : NULL]);
  }

}
