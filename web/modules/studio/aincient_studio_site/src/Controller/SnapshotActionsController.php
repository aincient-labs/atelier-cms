<?php

declare(strict_types=1);

namespace Drupal\aincient_studio_site\Controller;

use Drupal\aincient_export\Drush\Commands\ExportCommands;
use Drupal\aincient_export\SnapshotStore;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The Settings studio's Freeze & Live actions (DECISIONS 0416).
 *
 * Freeze the published site into a snapshot, serve one (or go live), keep or
 * delete one. Every response carries the whole snapshot state so the rail can
 * re-render from the answer. The store, the exporter and the read-only list +
 * file routes are `aincient_export`'s (core); this controller is the studio's
 * because the actions are the studio's — with Settings switched off they 403,
 * and the site still exports from drush.
 */
final class SnapshotActionsController extends ControllerBase {

  public function __construct(
    private readonly SnapshotStore $store,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('aincient_export.snapshot_store'));
  }

  /**
   * POST {label?, keep?, serve?, force?}: freeze the published site into a new snapshot.
   *
   * A snapshot whose export reported failures or broken links is taken but
   * NOT served (unless the caller passes force: true); the response carries
   * the problems so the pane can show them and offer "serve anyway".
   */
  public function freeze(Request $request): JsonResponse {
    $body = self::body($request);
    $label = trim((string) ($body['label'] ?? ''));
    $serve = (bool) ($body['serve'] ?? TRUE);
    $force = (bool) ($body['force'] ?? FALSE);
    try {
      [$snapshot, $result] = $this->store->freeze(
        $request->getSchemeAndHttpHost(),
        [
          'label' => $label,
          'keep' => (bool) ($body['keep'] ?? FALSE),
          'frozen_by' => $this->currentUser()->getAccountName(),
          'atelier_version' => (string) (getenv('ATELIER_VERSION') ?: 'dev'),
        ],
        ExportCommands::DEFAULT_CHECK_IGNORE,
      );
    }
    catch (\RuntimeException $e) {
      return new JsonResponse(['ok' => FALSE, 'error' => $e->getMessage()] + $this->store->state(), 500);
    }
    $problems = [];
    foreach ($result->failures as $path => $error) {
      $problems[] = sprintf('%s failed: %s', $path, $error);
    }
    foreach ($result->brokenLinks as $broken) {
      $problems[] = sprintf('%s links to %s, which does not exist', $broken['file'], $broken['ref']);
    }
    foreach ($result->missingAssets as $path => $status) {
      $problems[] = sprintf('%s is referenced but could not be produced (HTTP %d)', $path, $status);
    }
    $served = FALSE;
    if ($serve && ($result->ok() || $force)) {
      $this->store->use($snapshot->id);
      $served = TRUE;
    }
    return new JsonResponse([
      'ok' => $result->ok(),
      'snapshot' => $snapshot->toArray(),
      'served' => $served,
      'problems' => $problems,
    ] + $this->store->state());
  }

  /**
   * POST {id}: serve a snapshot, or "live".
   */
  public function use(Request $request): JsonResponse {
    $id = (string) (self::body($request)['id'] ?? '');
    try {
      $this->store->use($id);
    }
    catch (\InvalidArgumentException $e) {
      return new JsonResponse(['ok' => FALSE, 'error' => $e->getMessage()] + $this->store->state(), 400);
    }
    return new JsonResponse(['ok' => TRUE] + $this->store->state());
  }

  /**
   * POST {id, keep}: flip a snapshot's keep flag.
   */
  public function keep(Request $request): JsonResponse {
    $body = self::body($request);
    try {
      $this->store->setKeep((string) ($body['id'] ?? ''), (bool) ($body['keep'] ?? TRUE));
    }
    catch (\InvalidArgumentException $e) {
      return new JsonResponse(['ok' => FALSE, 'error' => $e->getMessage()] + $this->store->state(), 400);
    }
    return new JsonResponse(['ok' => TRUE] + $this->store->state());
  }

  /**
   * POST {id}: delete a snapshot that is not being served.
   */
  public function delete(Request $request): JsonResponse {
    $id = (string) (self::body($request)['id'] ?? '');
    try {
      $this->store->delete($id);
    }
    catch (\InvalidArgumentException | \LogicException $e) {
      return new JsonResponse(['ok' => FALSE, 'error' => $e->getMessage()] + $this->store->state(), 400);
    }
    return new JsonResponse(['ok' => TRUE] + $this->store->state());
  }

  /**
   * @return array<string, mixed>
   */
  private static function body(Request $request): array {
    $decoded = json_decode((string) $request->getContent(), TRUE);
    return is_array($decoded) ? $decoded : [];
  }

}
