<?php

declare(strict_types=1);

namespace Drupal\aincient_studio_site\Controller;

use Drupal\aincient_chat\Studio\StudioDemoContent;
use Drupal\aincient_chat\Studio\StudioManager;
use Drupal\aincient_core\Demo\DemoContentTracker;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The Settings studio's "Clear examples" (plans/studio-modules.md "Demo content").
 *
 * A studio seeds example content the first time it is switched on; this is the
 * one operator surface that removes it. The list groups the tracked entities
 * by the studio that seeded them; the clear deletes every tracked entity still
 * present for one studio (or all) — edited ones too, because the tag means
 * "came from us", not "untouched" ({@see DemoContentTracker}). The headless
 * twin is `drush atelier:demo-clear`; both call the same tracker, so what the
 * button removes and what the shell removes cannot differ.
 *
 * Same shape as the snapshot actions: JSON, cookie auth, the Settings
 * studio's derived permission, stamped by the route subscriber so a
 * switched-off Settings 403s them.
 */
final class ExamplesController extends ControllerBase {

  public function __construct(
    private readonly DemoContentTracker $tracker,
    private readonly StudioManager $studios,
    private readonly StudioDemoContent $demo,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('aincient_core.demo_content'),
      $container->get('plugin.manager.aincient.studios'),
      $container->get('aincient_chat.studio_demo_content'),
    );
  }

  /**
   * GET: the example content still present, per studio.
   */
  public function list(): JsonResponse {
    return new JsonResponse($this->examples());
  }

  /**
   * POST {studio?}: delete the examples of one studio, or of every studio.
   */
  public function clear(Request $request): JsonResponse {
    $body = json_decode((string) $request->getContent(), TRUE);
    $studio = is_array($body) && is_string($body['studio'] ?? NULL) && $body['studio'] !== '' ? $body['studio'] : NULL;
    if ($studio !== NULL && $this->studios->get($studio) === NULL && $this->tracker->tracked($studio) === []) {
      return new JsonResponse(['ok' => FALSE, 'error' => sprintf('Unknown studio "%s".', $studio)] + $this->examples(), 404);
    }
    try {
      $deleted = $this->tracker->clear($studio);
    }
    catch (\Throwable $e) {
      return new JsonResponse(['ok' => FALSE, 'error' => $e->getMessage()] + $this->examples(), 500);
    }
    return new JsonResponse(['ok' => TRUE, 'deleted' => $deleted] + $this->examples());
  }

  /**
   * @return array{studios: list<array{id: string, label: string, count: int}>, total: int}
   */
  private function examples(): array {
    $counts = [];
    foreach ($this->tracker->tracked() as $row) {
      $counts[$row['studio']] = ($counts[$row['studio']] ?? 0) + 1;
    }
    $studios = [];
    foreach ($counts as $id => $count) {
      $studios[] = [
        'id' => (string) $id,
        'label' => $this->studios->get((string) $id)?->label() ?? (string) $id,
        'count' => $count,
        'ships' => $this->demo->ships((string) $id),
      ];
    }
    usort($studios, fn(array $a, array $b) => strcmp($a['label'], $b['label']));
    return ['studios' => $studios, 'total' => array_sum($counts)];
  }

}
