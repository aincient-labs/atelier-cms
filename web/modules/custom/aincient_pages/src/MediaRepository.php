<?php

declare(strict_types=1);

namespace Drupal\aincient_pages;

use Drupal\Component\Render\PlainTextOutput;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Utility\Token;
use Drupal\file\FileInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The image-media library, shaped for the page authoring path.
 *
 * The single seam between page authoring and Drupal media. The unified reference
 * layer reads media through here via {@see \Drupal\aincient_pages\Reference\MediaReferenceProvider}
 * (the studio's ReferenceField and the agent's find_reference tool both go through
 * the catalog), and uploads land here too — so every path surfaces the identical
 * library and emits the same opaque `media:<id>` TOKEN the page schema stores
 * (resolved at render by {@see EntityEmbedResolver}).
 *
 * Deliberately narrow: only the standard `image` media type (the one Phase 4a
 * ships). A row is `{ id, token, name, thumb, alt }` — `thumb` and `alt` are
 * resolved THROUGH EntityEmbedResolver so token semantics live in exactly one
 * place.
 */
final class MediaRepository {

  /**
   * The media bundle the page authoring path works with (Phase 4a ships one).
   */
  private const BUNDLE = 'image';

  /**
   * The image style used for picker/tool thumbnails (ships with core media).
   */
  private const THUMB_STYLE = 'media_library';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityEmbedResolver $embed,
    private readonly FileSystemInterface $fileSystem,
    private readonly Token $token,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  /**
   * Recent image media, newest first, optionally filtered by a name substring.
   *
   * @return array<int, array{id:string, token:string, name:string, thumb:string, alt:string}>
   */
  public function search(?string $query = NULL, int $limit = 60): array {
    $storage = $this->entityTypeManager->getStorage('media');
    $q = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('bundle', self::BUNDLE)
      ->sort('created', 'DESC')
      ->range(0, $limit);
    $query = $query !== NULL ? trim($query) : '';
    if ($query !== '') {
      $q->condition('name', '%' . $query . '%', 'LIKE');
    }
    return $this->shape($q->execute());
  }

  /**
   * Resolve a single `media:<id>` token (or bare id) to a row, or NULL.
   *
   * @return array{id:string, token:string, name:string, thumb:string, alt:string}|null
   */
  public function resolveToken(string $token): ?array {
    $ref = $this->embed->parse($token);
    $id = $ref && $ref['type'] === 'media' ? (string) $ref['id'] : (ctype_digit($token) ? $token : NULL);
    if ($id === NULL) {
      return NULL;
    }
    $rows = $this->shape([$id]);
    return $rows[0] ?? NULL;
  }

  /**
   * Resolve a `media:<id>` token to a file URL at a named image style, or NULL.
   *
   * The display-sized counterpart to the `thumb` on a picker row: pickers want the
   * small square `media_library` derivative, but a preview surface that renders the
   * image at real card size (the Presence teaser / social cards) needs a derivative
   * cut to that size — a media_library thumb upscaled into a 16:9 card looks blurry.
   * Only media tokens resolve; a non-media / dangling token → NULL. An unknown style
   * name falls back to the source file (see {@see EntityEmbedResolver::url()}).
   */
  public function previewUrl(string $token, string $style): ?string {
    $ref = $this->embed->parse($token);
    if (!$ref || $ref['type'] !== 'media') {
      return NULL;
    }
    return $this->embed->url($token, $style);
  }

  /**
   * Create an image-media entity from an uploaded file.
   *
   * Validates the upload against the media type's own source-field settings
   * (allowed extensions + max size), so the picker can't bypass the limits the
   * library enforces everywhere else. Returns the new row, or throws on a bad
   * upload (caught + reported by the controller).
   *
   * @return array{id:string, token:string, name:string, thumb:string, alt:string}
   */
  public function createFromUpload(UploadedFile $upload, ?string $alt = NULL): array {
    $file = $this->saveUploadedImage($upload);
    $basename = basename($upload->getClientOriginalName());
    $name = $alt !== NULL && trim($alt) !== '' ? trim($alt) : pathinfo($basename, PATHINFO_FILENAME);
    $media = $this->entityTypeManager->getStorage('media')->create([
      'bundle' => self::BUNDLE,
      'name' => $name,
      $this->sourceFieldName() => [
        'target_id' => $file->id(),
        'alt' => $alt !== NULL && trim($alt) !== '' ? trim($alt) : $name,
      ],
    ]);
    $media->save();

    return $this->shape([$media->id()])[0];
  }

  /**
   * Create an image-media entity from raw image bytes (the AI-generation path).
   *
   * The {@see self::createFromUpload} sibling for bytes that never touched an
   * `UploadedFile` — the binary an image provider (Nano Banana) returns. Lands
   * the bytes in the SAME token-resolved upload directory as an upload, mints a
   * `file` entity + an `image` media entity, and returns the picker row with its
   * fresh `media:<id>` token — so a generated image is indistinguishable from an
   * uploaded one everywhere downstream (the Library, the reference catalog, page
   * embeds). Throws on an unwritable directory / save failure.
   *
   * @param string $binary
   *   The raw image bytes.
   * @param string $filename
   *   A base filename (extension included, e.g. "hero.png"); used to derive the
   *   stored file name and its extension.
   * @param string|null $alt
   *   Alt text for the media item (also seeds its name when no explicit $name).
   * @param string|null $name
   *   An explicit display name; when given it wins over the alt-seeded name (so a
   *   MADE title and a MADE alt can differ — Law 11). Falls back to $alt, then the
   *   filename, when NULL/blank.
   *
   * @return array{id:string, token:string, name:string, thumb:string, alt:string}
   */
  public function createFromBytes(string $binary, string $filename, ?string $alt = NULL, ?string $name = NULL): array {
    if ($binary === '') {
      throw new \RuntimeException('The image generation returned no data.');
    }
    $file = $this->saveImageBytes($binary, $filename);
    $basename = basename($filename);
    $name = $name !== NULL && trim($name) !== ''
      ? trim($name)
      : ($alt !== NULL && trim($alt) !== '' ? trim($alt) : pathinfo($basename, PATHINFO_FILENAME));
    $media = $this->entityTypeManager->getStorage('media')->create([
      'bundle' => self::BUNDLE,
      'name' => $name,
      $this->sourceFieldName() => [
        'target_id' => $file->id(),
        'alt' => $alt !== NULL && trim($alt) !== '' ? trim($alt) : $name,
      ],
    ]);
    $media->save();

    return $this->shape([$media->id()])[0];
  }

  /**
   * The editable detail of one image-media item for the media studio, or NULL.
   *
   * The "open this media item" read behind {@see \Drupal\aincient_pages\Controller\MediaController::detail}.
   * Superset of a picker row: adds the display-sized `preview` (the studio renders
   * the image at real size, not the small square thumb), the source `mime` /
   * pixel `width`+`height` (shown as read-only facts), and the publish `status`.
   * NULL when the id isn't an image-media item, or its source file has vanished
   * (shape() skips it — nothing editable to show).
   *
   * @return array{id:string, token:string, name:string, thumb:string, alt:string, preview:string, mime:string, width:int, height:int, status:string}|null
   */
  public function detail(string|int $id): ?array {
    $base = $this->shape([(string) $id]);
    if ($base === []) {
      return NULL;
    }
    $media = $this->entityTypeManager->getStorage('media')->load($id);
    $field = $media->get($this->sourceFieldName());
    $file = $field->entity;
    return $base[0] + [
      'preview' => $file instanceof FileInterface
        ? $this->fileUrlGenerator->generateString($file->getFileUri())
        : $base[0]['thumb'],
      'mime' => $file instanceof FileInterface ? (string) $file->getMimeType() : '',
      'width' => (int) ($field->width ?? 0),
      'height' => (int) ($field->height ?? 0),
      'status' => $media->isPublished() ? 'published' : 'unpublished',
    ];
  }

  /**
   * The source file's bytes for a `media:<id>` token — the image→image input.
   *
   * Resolves a media token to its underlying image file and reads it into memory
   * (binary + mime + filename), the shape {@see \Drupal\ai\OperationType\GenericType\ImageFile}
   * wants when editing an EXISTING image ("make this warmer"). NULL when the token
   * isn't a resolvable image-media item, or its source file has vanished.
   *
   * @return array{binary:string, mime:string, filename:string}|null
   */
  public function sourceBytes(string $token): ?array {
    $ref = $this->embed->parse($token);
    $id = $ref && $ref['type'] === 'media' ? (string) $ref['id'] : (ctype_digit($token) ? $token : NULL);
    if ($id === NULL) {
      return NULL;
    }
    $media = $this->entityTypeManager->getStorage('media')->load($id);
    if (!$media instanceof \Drupal\media\MediaInterface || $media->bundle() !== self::BUNDLE) {
      return NULL;
    }
    $file = $media->get($this->sourceFieldName())->entity;
    if (!$file instanceof FileInterface) {
      return NULL;
    }
    $binary = @file_get_contents($file->getFileUri());
    if ($binary === FALSE || $binary === '') {
      return NULL;
    }
    return [
      'binary' => $binary,
      'mime' => (string) $file->getMimeType(),
      'filename' => (string) $file->getFilename(),
    ];
  }

  /**
   * Update an image-media item's editable metadata (name and/or alt text).
   *
   * The write behind the media studio's non-AI editor rail. Only the keys present
   * in $fields are touched (a `name`-only save leaves alt alone, and vice-versa),
   * so the two form controls save independently. Returns the fresh {@see detail}
   * row; throws on an unknown id / wrong bundle / empty name (caught + reported by
   * the controller).
   *
   * @param array{name?:string, alt?:string} $fields
   *
   * @return array{id:string, token:string, name:string, thumb:string, alt:string, preview:string, mime:string, width:int, height:int, status:string}
   */
  public function updateMetadata(string|int $id, array $fields): array {
    $media = $this->loadImage($id);
    if (array_key_exists('name', $fields)) {
      $name = trim((string) $fields['name']);
      if ($name === '') {
        throw new \RuntimeException('A name is required.');
      }
      $media->set('name', $name);
    }
    if (array_key_exists('alt', $fields)) {
      $field = $media->get($this->sourceFieldName());
      if ($field->target_id) {
        $field->alt = trim((string) $fields['alt']);
      }
    }
    $media->save();
    return $this->detail($id) ?? throw new \RuntimeException('The media item could not be read back.');
  }

  /**
   * Swap the underlying image file, keeping the SAME media id + `media:<id>` token.
   *
   * Every page/block/chrome consumer stores the token, not the file — so replacing
   * the file propagates the new image everywhere the item is embedded, with no
   * reference rewrite (the point of a stable token). Alt text is preserved (it's a
   * property of the media item, not the file). Validates the upload against the
   * same source-field limits as {@see createFromUpload}. Throws on unknown id /
   * wrong bundle / bad upload.
   *
   * @return array{id:string, token:string, name:string, thumb:string, alt:string, preview:string, mime:string, width:int, height:int, status:string}
   */
  public function replaceFile(string|int $id, UploadedFile $upload): array {
    $media = $this->loadImage($id);
    $file = $this->saveUploadedImage($upload);
    $field = $media->get($this->sourceFieldName());
    $alt = trim((string) ($field->alt ?? ''));
    $media->set($this->sourceFieldName(), [
      'target_id' => $file->id(),
      'alt' => $alt !== '' ? $alt : (string) $media->label(),
    ]);
    $media->save();
    return $this->detail($id) ?? throw new \RuntimeException('The media item could not be read back.');
  }

  /**
   * Replace an item's file with ANOTHER media item's bytes, keeping its token.
   *
   * The "commit this edit onto the original" path: `$targetId` is the image being
   * overwritten (the one every consumer embeds), `$fromToken` the source of the new
   * bytes (the generated edit result). Copies the source bytes into a fresh file and
   * repoints the target's source field at it — same media id + `media:<id>` token, so
   * the new image propagates everywhere with no reference rewrite. The target's own
   * alt is preserved. Throws on unknown target / wrong bundle / unreadable source.
   *
   * @return array{id:string, token:string, name:string, thumb:string, alt:string, preview:string, mime:string, width:int, height:int, status:string}
   */
  public function replaceFromMedia(string|int $targetId, string $fromToken): array {
    $target = $this->loadImage($targetId);
    $bytes = $this->sourceBytes($fromToken);
    if ($bytes === NULL) {
      throw new \RuntimeException('The source image could not be read.');
    }
    $file = $this->saveImageBytes(
      $bytes['binary'],
      $bytes['filename'] !== '' ? $bytes['filename'] : 'replacement.png',
    );
    $field = $target->get($this->sourceFieldName());
    $alt = trim((string) ($field->alt ?? ''));
    $target->set($this->sourceFieldName(), [
      'target_id' => $file->id(),
      'alt' => $alt !== '' ? $alt : (string) $target->label(),
    ]);
    $target->save();
    return $this->detail($targetId) ?? throw new \RuntimeException('The media item could not be read back.');
  }

  /**
   * Delete an image-media item — the human's "discard" for an unwanted asset.
   *
   * The AI never deletes its own (expensive) output; this is the human's explicit
   * control. Deletes only the media entity — its now-unused source file is collected
   * by Drupal's file GC. A token that other content still embeds resolves to nothing
   * afterwards (embeds degrade gracefully — {@see shape} skips a vanished file);
   * usage-safety (block/warn on referenced items) is a deferred enhancement. Throws
   * on unknown id / wrong bundle.
   */
  public function delete(string|int $id): void {
    $this->loadImage($id)->delete();
  }

  /**
   * Load an image-media item by id, or throw if it's missing / the wrong bundle.
   */
  private function loadImage(string|int $id): \Drupal\media\MediaInterface {
    $media = $this->entityTypeManager->getStorage('media')->load($id);
    if (!$media instanceof \Drupal\media\MediaInterface || $media->bundle() !== self::BUNDLE) {
      throw new \RuntimeException('That media item does not exist.');
    }
    return $media;
  }

  /**
   * Validate an uploaded image against the field's limits and save it as a file.
   *
   * The shared bytes-to-file path behind both {@see createFromUpload} (new item)
   * and {@see replaceFile} (swap an existing item's file): checks extension + max
   * size against the media type's own source-field settings, lands the bytes in
   * the field's token-resolved upload directory, and returns the saved file entity.
   */
  private function saveUploadedImage(UploadedFile $upload): FileInterface {
    if (!$upload->isValid()) {
      throw new \RuntimeException('The upload did not complete.');
    }
    $settings = $this->sourceFieldSettings();
    $extensions = preg_split('/\s+/', trim((string) ($settings['file_extensions'] ?? 'png gif jpg jpeg webp')));
    $ext = strtolower((string) $upload->getClientOriginalExtension());
    if ($ext === '' || !in_array($ext, $extensions, TRUE)) {
      throw new \RuntimeException(sprintf('Unsupported file type ".%s" — allowed: %s.', $ext, implode(', ', $extensions)));
    }
    $maxBytes = $this->maxBytes($settings['max_filesize'] ?? NULL);
    if ($maxBytes > 0 && $upload->getSize() > $maxBytes) {
      throw new \RuntimeException('The file is larger than the allowed maximum.');
    }

    // Land the bytes in the media type's configured upload directory.
    $directory = $this->uploadDirectory($settings);
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $basename = basename($upload->getClientOriginalName());
    $destination = $this->fileSystem->createFilename($basename, $directory);
    $uri = $this->fileSystem->move($upload->getRealPath(), $destination, FileExists::Rename);

    $file = $this->entityTypeManager->getStorage('file')->create([
      'uri' => $uri,
      'status' => 1,
    ]);
    $file->save();
    return $file;
  }

  /**
   * Land raw image bytes in the field's upload directory as a file entity.
   *
   * The bytes counterpart of {@see self::saveUploadedImage} for the AI-generation
   * path: no upload validation (the bytes are ours, not a user file), but it lands
   * them in the SAME token-resolved directory and normalises the extension against
   * the field's allowed set (falling back to `png`, what Nano Banana returns) so a
   * generated file is stored exactly like an uploaded one.
   */
  private function saveImageBytes(string $binary, string $filename): FileInterface {
    $settings = $this->sourceFieldSettings();
    $extensions = preg_split('/\s+/', trim((string) ($settings['file_extensions'] ?? 'png gif jpg jpeg webp')));
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if ($ext === '' || !in_array($ext, $extensions, TRUE)) {
      // Nano Banana returns PNG; keep that when the field allows it, else the
      // first allowed extension, so the stored file has a valid, allowed name.
      $ext = in_array('png', $extensions, TRUE) ? 'png' : (string) ($extensions[0] ?? 'png');
      $filename = pathinfo($filename, PATHINFO_FILENAME) . '.' . $ext;
    }

    $directory = $this->uploadDirectory($settings);
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $destination = $this->fileSystem->createFilename(basename($filename), $directory);
    $uri = $this->fileSystem->saveData($binary, $destination, FileExists::Rename);

    $file = $this->entityTypeManager->getStorage('file')->create([
      'uri' => $uri,
      'status' => 1,
    ]);
    $file->save();
    return $file;
  }

  /**
   * Shape a set of media ids into picker/tool rows (skips anything unresolvable).
   *
   * @param array<int|string> $ids
   *
   * @return array<int, array{id:string, token:string, name:string, thumb:string, alt:string}>
   */
  private function shape(array $ids): array {
    $rows = [];
    foreach ($this->entityTypeManager->getStorage('media')->loadMultiple($ids) as $media) {
      $token = 'media:' . $media->id();
      $thumb = $this->embed->url($token, self::THUMB_STYLE);
      if ($thumb === NULL) {
        // A media item whose source file vanished — not pickable.
        continue;
      }
      $rows[] = [
        'id' => (string) $media->id(),
        'token' => $token,
        'name' => (string) $media->label(),
        'thumb' => $thumb,
        'alt' => $this->embed->alt($token) ?? '',
      ];
    }
    return $rows;
  }

  /**
   * The source field name of the image media type (e.g. field_media_image).
   */
  private function sourceFieldName(): string {
    $type = $this->entityTypeManager->getStorage('media_type')->load(self::BUNDLE);
    return $type ? $type->getSource()->getConfiguration()['source_field'] ?? 'field_media_image' : 'field_media_image';
  }

  /**
   * The image source field's third-party settings (extensions, max size, dir).
   *
   * @return array<string, mixed>
   */
  private function sourceFieldSettings(): array {
    $field = $this->entityTypeManager->getStorage('field_config')
      ->load('media.' . self::BUNDLE . '.' . $this->sourceFieldName());
    return $field ? $field->getSettings() : [];
  }

  /**
   * The stream-wrapper directory uploads land in, from the field's settings.
   */
  private function uploadDirectory(array $settings): string {
    $scheme = (string) ($settings['uri_scheme'] ?? 'public');
    $dir = trim((string) ($settings['file_directory'] ?? ''), '/');
    // Resolve any tokens the field directory carries — the core default is
    // '[date:custom:Y]-[date:custom:m]' → '2026-07'. This mirrors core's
    // FileItem::doGetUploadLocation() so programmatic uploads land in the SAME
    // directory as UI uploads. (The previous regex-strip of tokens collapsed a
    // token-only directory to the bare '-' separator → files at public://-/.)
    if ($dir !== '') {
      $dir = PlainTextOutput::renderFromHtml($this->token->replace($dir));
    }
    $dir = trim($dir, '/');
    return $scheme . '://' . ($dir !== '' ? $dir : 'media');
  }

  /**
   * Resolve a field max-filesize setting (e.g. "8 MB", or '' for unlimited).
   */
  private function maxBytes(?string $setting): int {
    if ($setting === NULL || trim($setting) === '') {
      return 0;
    }
    // Drupal ships a Bytes helper; fall back to 0 (unlimited) if unavailable.
    return class_exists('\Drupal\Component\Utility\Bytes')
      ? (int) \Drupal\Component\Utility\Bytes::toNumber($setting)
      : 0;
  }

}
