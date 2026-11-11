<?php

declare(strict_types=1);

namespace Drupal\aincient_core\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Refuses an out-of-range entity id in an Atelier JSON body with a 400 (KI-1).
 *
 * The console contract's body ids (`node_id`, `base_vid`) are PostgreSQL `int4`
 * serials. A larger value used to reach a query and come back as a 500. This
 * checks the two keys once, after routing and access, for every Atelier write
 * route, instead of in each controller. Missing or empty values pass through:
 * the controllers already answer those.
 */
final class BodyEntityIdSubscriber implements EventSubscriberInterface {

  public const KEYS = ['node_id', 'base_vid'];

  private const INT4_MAX = 2147483647;

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // After RouterListener (32), which resolves the route and checks access.
    return [KernelEvents::REQUEST => ['onRequest', 30]];
  }

  public function onRequest(RequestEvent $event): void {
    $request = $event->getRequest();
    if (!$event->isMainRequest() || $request->isMethodSafe()) {
      return;
    }
    $route = (string) $request->attributes->get('_route', '');
    if (!str_starts_with($route, 'aincient') || !str_contains((string) $request->headers->get('Content-Type', ''), 'json')) {
      return;
    }
    $body = json_decode((string) $request->getContent(), TRUE);
    if (!is_array($body)) {
      return;
    }
    foreach (self::KEYS as $key) {
      if (array_key_exists($key, $body) && !self::valid($body[$key])) {
        $event->setResponse(new JsonResponse(['error' => sprintf('%s must be a whole number from 1 to %d.', $key, self::INT4_MAX)], 400));
        return;
      }
    }
  }

  /**
   * Whether a body id is absent-ish (NULL, '') or a positive int4.
   */
  public static function valid(mixed $value): bool {
    if ($value === NULL || $value === '') {
      return TRUE;
    }
    if (is_int($value)) {
      return $value >= 1 && $value <= self::INT4_MAX;
    }
    if (is_string($value) && preg_match('/^[1-9][0-9]{0,9}$/', $value)) {
      return (int) $value <= self::INT4_MAX;
    }
    return FALSE;
  }

}
