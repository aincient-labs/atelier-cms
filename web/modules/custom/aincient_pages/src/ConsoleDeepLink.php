<?php

declare(strict_types=1);

namespace Drupal\aincient_pages;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Url;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/**
 * The single source of truth for "open this entity in its console studio".
 *
 * Studio-authored content is edited in the chat console's studios, never at the
 * raw Drupal entity form. This maps such an entity to its bookmarkable console
 * deep link — the room-primary routes in `aincient_chat.routing.yml`, the codec
 * documented in `chat-ui/src/console-url.ts`:
 *
 *   - `aincient_page` node → /atelier/content/node/<nid>   (Content studio)
 *   - `block` media        → /atelier/content/block/<id>   (Content studio)
 *   - `image` media        → /atelier/media/image/<id>     (Media studio)
 *
 * Any other entity → NULL: no studio owns it, so the caller keeps whatever
 * fallback it has (the raw entity edit form, or no edit affordance at all).
 *
 * Two callers share this so the operations menu and the studio's reference field
 * agree on ONE URL and neither escapes to the backend node/media form:
 *   - the "Edit in studio" entity operation ({@see aincient_pages_entity_operation});
 *   - the reference descriptors' `edit_url` ({@see MediaReferenceProvider},
 *     {@see NodeReferenceProvider}).
 */
final class ConsoleDeepLink {

  /**
   * The console route + params that edit this entity in its studio, or NULL.
   *
   * The pure mapping — no container, no URL generation — so the entity→studio
   * contract is unit-testable in isolation from route availability.
   *
   * @return array{0: string, 1: array<string, mixed>}|null
   *   `[route_name, route_parameters]`, or NULL if no studio owns the entity.
   */
  public static function route(EntityInterface $entity): ?array {
    $type = $entity->getEntityTypeId();
    $bundle = $entity->bundle();
    return match (TRUE) {
      $type === 'node' && $bundle === 'aincient_page' => [
        'aincient_chat.console_doc',
        ['studio' => 'content', 'doc_type' => 'node', 'nid' => $entity->id()],
      ],
      $type === 'media' && $bundle === 'block' => [
        'aincient_chat.console_doc',
        ['studio' => 'content', 'doc_type' => 'block', 'nid' => $entity->id()],
      ],
      $type === 'media' && $bundle === 'image' => [
        'aincient_chat.console_media',
        ['bundle' => 'image', 'nid' => $entity->id()],
      ],
      default => NULL,
    };
  }

  /**
   * The console deep link that edits this entity in its studio, or NULL.
   *
   * Degrades gracefully: aincient_pages does not depend on aincient_chat, so if
   * the console routes are absent (the chat module is disabled) this returns NULL
   * rather than fataling — the caller then keeps its own fallback.
   */
  public static function editUrl(EntityInterface $entity): ?Url {
    $route = self::route($entity);
    if ($route === NULL) {
      return NULL;
    }
    try {
      \Drupal::service('router.route_provider')->getRouteByName($route[0]);
    }
    catch (RouteNotFoundException) {
      return NULL;
    }
    return Url::fromRoute($route[0], $route[1]);
  }

  /**
   * The studio-access permission gating this entity's deep link, or NULL.
   *
   * Mirrors `Studio::<case>->permission()` in aincient_chat as a literal string
   * (aincient_pages does not depend on aincient_chat's code, only its routes):
   * the deep link opens a studio, so the operation is only offered to a user who
   * can actually enter it — otherwise the link lands on a console that falls back
   * to the default studio.
   */
  public static function studioPermission(EntityInterface $entity): ?string {
    $type = $entity->getEntityTypeId();
    $bundle = $entity->bundle();
    return match (TRUE) {
      $type === 'node' && $bundle === 'aincient_page' => 'use aincient studio content',
      $type === 'media' && $bundle === 'block' => 'use aincient studio content',
      $type === 'media' && $bundle === 'image' => 'use aincient studio media',
      default => NULL,
    };
  }

}
