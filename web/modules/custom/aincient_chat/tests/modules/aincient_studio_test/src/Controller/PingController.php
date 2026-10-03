<?php

declare(strict_types=1);

namespace Drupal\aincient_studio_test\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * The fixture studio's one route.
 */
final class PingController {

  public function ping(): JsonResponse {
    return new JsonResponse(['pong' => TRUE]);
  }

}
