<?php

declare(strict_types=1);

namespace Drupal\aincient_pages\Reference;

use Drupal\aincient_pages\EntityEmbedResolver;

/**
 * The unified front door to every referenceable type.
 *
 * Collects the tagged {@see ReferenceProviderInterface}s and exposes the two
 * operations the authoring layer needs, both type-agnostic: SEARCH across a set
 * of types (for the picker / agent find tool) and DESCRIBE a stored token (for the
 * in-field preview). Token → type dispatch reuses
 * {@see EntityEmbedResolver::parse()} so the grammar lives in exactly one place.
 */
final class ReferenceCatalog {

  /**
   * Providers keyed by their type key.
   *
   * @var array<string, \Drupal\aincient_pages\Reference\ReferenceProviderInterface>
   */
  private array $providers = [];

  /**
   * @param iterable<\Drupal\aincient_pages\Reference\ReferenceProviderInterface> $providers
   *   The tagged providers (service collector).
   */
  public function __construct(
    private readonly EntityEmbedResolver $embed,
    iterable $providers,
  ) {
    foreach ($providers as $provider) {
      $this->providers[$provider->typeKey()] = $provider;
    }
  }

  /**
   * The type keys this site can reference (every registered provider).
   *
   * @return array<int, string>
   */
  public function types(): array {
    return array_keys($this->providers);
  }

  /**
   * Search one or more types for pickable references.
   *
   * @param array<int, string> $types
   *   The type keys to include; an empty array means every registered type.
   *   Unknown keys are ignored.
   * @param string|null $query
   *   Optional name/title substring.
   * @param int $limit
   *   Maximum rows PER type.
   *
   * @return array<int, array>
   *   A flat list of descriptors ({@see ReferenceDescriptor}).
   */
  public function search(array $types, ?string $query, int $limit = 50): array {
    $types = $types === [] ? $this->types() : $types;
    $out = [];
    foreach ($types as $type) {
      $provider = $this->providers[$type] ?? NULL;
      if ($provider !== NULL) {
        foreach ($provider->search($query, $limit) as $descriptor) {
          $out[] = $descriptor;
        }
      }
    }
    return $out;
  }

  /**
   * Resolve a stored token to a descriptor for preview, or NULL.
   *
   * Parses the token to {type, id} and dispatches to the matching provider; a
   * malformed token, an unknown type, or a dangling reference all yield NULL.
   */
  public function describe(string $token): ?array {
    $ref = $this->embed->parse($token);
    if ($ref === NULL) {
      return NULL;
    }
    $provider = $this->providers[$ref['type']] ?? NULL;
    return $provider?->describe($ref['id']);
  }

}
