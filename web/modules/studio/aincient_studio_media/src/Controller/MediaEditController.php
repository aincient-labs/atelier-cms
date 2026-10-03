<?php

declare(strict_types=1);

namespace Drupal\aincient_studio_media\Controller;

use Drupal\aincient_pages\MediaRepository;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\media\MediaInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * JSON API of the Media studio's editor rail — ONE image item's detail + writes.
 *
 * Lives in the Library STUDIO module (plans/studio-modules.md Phase E): these
 * five routes exist only for the rail, so they are gated by the studio's
 * derived permission and by its on/off switch. Everything that other surfaces
 * also need — upload, token → derivative URL, the reference catalog — stays in
 * core (`aincient_pages`'s MediaController / ReferenceController), and all
 * shaping still goes through {@see MediaRepository} so the rail, the picker and
 * the agent's tools see the same library.
 */
final class MediaEditController implements ContainerInjectionInterface {

  public function __construct(
    private readonly MediaRepository $media,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self($container->get('aincient_pages.media'));
  }

  /**
   * GET /atelier/media/{media}/schema — the editable detail of one image item.
   *
   * The "open this media item" read behind the editor rail — the name, alt,
   * display-sized preview, and read-only facts (mime + dimensions + status).
   * Mirrors BlockController::blockSchema: a non-image bundle 404s, a view-access
   * denial 403s (so the console can render its dead-end pane), a source file
   * that has vanished 404s (nothing editable).
   */
  public function detail(MediaInterface $media): JsonResponse {
    if ($media->bundle() !== 'image') {
      return new JsonResponse(['error' => 'Not an editable image.'], 404);
    }
    if (!$media->access('view')) {
      return new JsonResponse(['error' => 'You don’t have access to view this media item.'], 403);
    }
    $row = $this->media->detail((string) $media->id());
    if ($row === NULL) {
      return new JsonResponse(['error' => 'That media item is missing its file.'], 404);
    }
    return new JsonResponse($row);
  }

  /**
   * POST /atelier/media/{media} — update an image item's name and/or alt text.
   *
   * JSON body `{ name?: string, alt?: string }` — only the keys present are
   * written, so the two editor controls save independently. Returns the fresh
   * detail row. Gated on edit access (403), wrong bundle (404), bad input (422).
   */
  public function save(MediaInterface $media, Request $request): JsonResponse {
    if ($media->bundle() !== 'image') {
      return new JsonResponse(['error' => 'Not an editable image.'], 404);
    }
    if (!$media->access('update')) {
      return new JsonResponse(['error' => 'You don’t have access to edit this media item.'], 403);
    }
    $body = json_decode((string) $request->getContent(), TRUE);
    if (!is_array($body)) {
      return new JsonResponse(['error' => 'Expected a JSON object.'], 400);
    }
    $fields = array_intersect_key($body, ['name' => TRUE, 'alt' => TRUE]);
    try {
      $row = $this->media->updateMetadata((string) $media->id(), $fields);
    }
    catch (\Throwable $e) {
      return new JsonResponse(['error' => $e->getMessage()], 422);
    }
    return new JsonResponse(['item' => $row]);
  }

  /**
   * POST /atelier/media/{media}/file — swap the image file, keeping the token.
   *
   * Multipart body: `file` (the replacement image). The `media:<id>` token is
   * unchanged, so every page/block/chrome that embeds it now serves the new
   * bytes with no reference rewrite. Alt text is preserved. Gated on edit access.
   */
  public function replaceFile(MediaInterface $media, Request $request): JsonResponse {
    if ($media->bundle() !== 'image') {
      return new JsonResponse(['error' => 'Not an editable image.'], 404);
    }
    if (!$media->access('update')) {
      return new JsonResponse(['error' => 'You don’t have access to edit this media item.'], 403);
    }
    $file = $request->files->get('file');
    if ($file === NULL) {
      return new JsonResponse(['error' => 'Expected a multipart "file" upload.'], 400);
    }
    try {
      $row = $this->media->replaceFile((string) $media->id(), $file);
    }
    catch (\Throwable $e) {
      return new JsonResponse(['error' => $e->getMessage()], 422);
    }
    return new JsonResponse(['item' => $row]);
  }

  /**
   * POST /atelier/media/{media}/replace-from — overwrite {media} with another
   * item's bytes, keeping {media}'s token.
   *
   * JSON body `{ from: 'media:<id>' }`. The "commit this edit onto the original"
   * write: {media} is the original every page embeds, `from` the generated edit
   * result. {media} keeps its `media:<id>` token, so consumers update in place.
   * Gated on edit access to {media} (403), wrong bundle (404), bad input (422).
   */
  public function replaceFrom(MediaInterface $media, Request $request): JsonResponse {
    if ($media->bundle() !== 'image') {
      return new JsonResponse(['error' => 'Not an editable image.'], 404);
    }
    if (!$media->access('update')) {
      return new JsonResponse(['error' => 'You don’t have access to edit this media item.'], 403);
    }
    $body = json_decode((string) $request->getContent(), TRUE);
    $from = is_array($body) ? (string) ($body['from'] ?? '') : '';
    if ($from === '') {
      return new JsonResponse(['error' => 'Expected a JSON body with a "from" media token.'], 400);
    }
    try {
      $row = $this->media->replaceFromMedia((string) $media->id(), $from);
    }
    catch (\Throwable $e) {
      return new JsonResponse(['error' => $e->getMessage()], 422);
    }
    return new JsonResponse(['item' => $row]);
  }

  /**
   * POST /atelier/media/{media}/delete — delete an image-media item.
   *
   * The human's explicit discard for an unwanted (e.g. just-generated) image.
   * Returns `{ deleted: true }`. Gated on delete access (403), wrong bundle (404).
   */
  public function delete(MediaInterface $media): JsonResponse {
    if ($media->bundle() !== 'image') {
      return new JsonResponse(['error' => 'Not an editable image.'], 404);
    }
    if (!$media->access('delete')) {
      return new JsonResponse(['error' => 'You don’t have access to delete this media item.'], 403);
    }
    try {
      $this->media->delete((string) $media->id());
    }
    catch (\Throwable $e) {
      return new JsonResponse(['error' => $e->getMessage()], 422);
    }
    return new JsonResponse(['deleted' => TRUE]);
  }

}
