<?php

declare(strict_types=1);

namespace Drupal\aincient_pages\Controller;

use Drupal\aincient_pages\MediaRepository;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * JSON media UPLOAD + token-resolution API for the console (core).
 *
 * The two media endpoints every studio needs with the Library studio switched
 * off: creating an image-media entity from an upload (the reference picker's
 * "upload" leg, which writes back the opaque `media:<id>` TOKEN the schema
 * stores) and resolving a token to a display-sized derivative (the Presence
 * cards). Browse + token preview go through the unified reference API
 * ({@see ReferenceController} / {@see \Drupal\aincient_pages\Reference\ReferenceCatalog}).
 * The one-item EDITING routes (detail, save, replace, delete) are the Library
 * studio's and live in `aincient_studio_media` (plans/studio-modules.md Phase E).
 * All shaping goes through {@see MediaRepository} so the upload, the picker, the
 * editor rail and the agent's tools surface the same library.
 */
final class MediaController implements ContainerInjectionInterface {

  public function __construct(
    private readonly MediaRepository $media,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self($container->get('aincient_pages.media'));
  }

  /**
   * GET /atelier/media/url — a media token → a file URL at a named image style.
   *
   * `?token=media:<id>&style=<image_style>`. Returns `{ url: string|null }` — the
   * derivative URL for that style, or null for a non-media / dangling token. The
   * display-sized read behind the Presence preview cards, which render the image at
   * real card size (a picker `thumb` upscaled into those cards looks blurry).
   */
  public function url(Request $request): JsonResponse {
    $token = (string) ($request->query->get('token') ?? '');
    $style = (string) ($request->query->get('style') ?? '');
    $url = $token !== '' && $style !== '' ? $this->media->previewUrl($token, $style) : NULL;
    return new JsonResponse(['url' => $url]);
  }

  /**
   * POST /atelier/media/upload — create an image-media item from an upload.
   *
   * Multipart body: `file` (the image) + optional `alt` (alt text / name). The
   * upload is validated against the media type's own source-field limits. Returns
   * the new `{ id, token, name, thumb, alt }` row so the studio can select it.
   */
  public function upload(Request $request): JsonResponse {
    $file = $request->files->get('file');
    if ($file === NULL) {
      return new JsonResponse(['error' => 'Expected a multipart "file" upload.'], 400);
    }
    $alt = $request->request->get('alt');
    try {
      $row = $this->media->createFromUpload($file, is_string($alt) ? $alt : NULL);
    }
    catch (\Throwable $e) {
      return new JsonResponse(['error' => $e->getMessage()], 422);
    }
    return new JsonResponse(['item' => $row], 201);
  }

}
