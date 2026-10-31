<?php

declare(strict_types=1);

namespace Drupal\aincient_studio_site\Controller;

use Drupal\aincient_studio_site\Engine\EnginePairings;
use Drupal\aincient_studio_site\Engine\RevokeRefused;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The Settings studio's Managers section (Manager Instances M4: m4-1, m4-3).
 *
 * GET lists the Managers paired with this site's engine, from the engine's
 * read-only outbox; POST {id} asks the engine to revoke one non-primary
 * pairing through the inbox. There is no pairing here and never a code
 * (design E-02). Same shape as the other Settings sections — JSON, cookie
 * auth — plus a dedicated restricted permission and the session CSRF header
 * token on the write.
 */
final class ManagersController extends ControllerBase {

  public function __construct(
    private readonly EnginePairings $pairings,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('aincient_studio_site.engine_pairings'));
  }

  /**
   * GET: {state, pairings[], error?}.
   */
  public function list(): JsonResponse {
    return self::noStore(new JsonResponse($this->pairings->read()));
  }

  /**
   * POST {id}: write the engine's revoke request for one non-primary pairing.
   */
  public function revoke(Request $request): JsonResponse {
    $body = json_decode((string) $request->getContent(), TRUE);
    $id = is_array($body) && is_string($body['id'] ?? NULL) ? $body['id'] : '';
    try {
      $revoked = $this->pairings->requestRevoke($id, $this->currentUser()->getAccountName() . ' (uid ' . $this->currentUser()->id() . ')');
    }
    catch (RevokeRefused $e) {
      return self::noStore(new JsonResponse(['ok' => FALSE, 'error' => $e->getMessage()] + $this->pairings->read(), $e->status));
    }
    return self::noStore(new JsonResponse([
      'ok' => TRUE,
      'revoked' => $revoked,
    ] + $this->pairings->read()));
  }

  private static function noStore(JsonResponse $response): JsonResponse {
    $response->headers->set('Cache-Control', 'private, no-store');
    return $response;
  }

}
