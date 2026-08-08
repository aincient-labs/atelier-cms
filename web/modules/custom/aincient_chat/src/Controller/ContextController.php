<?php

declare(strict_types=1);

namespace Drupal\aincient_chat\Controller;

use Drupal\aincient_core\Context\ContextStore;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Ingest endpoint for chat-composer attachments (images and text documents).
 *
 * The operator attaches a file to a chat turn to give the agent context. The
 * bytes do NOT go into the media library: they land in the private, forensic
 * context store (validated, normalized to one derivative under
 * private://atelier-context, one permanent ledger row) owned by
 * {@see \Drupal\aincient_core\Context\ContextStore}. Images are pixel-bomb
 * guarded and GD-re-encoded to a WebP derivative; Tier A text documents
 * (DECISIONS 0350) pass a code floor (size, text-content sniff, UTF-8) and are
 * stored as their normalized text. The returned `ref` (`context:<id>`) is the
 * handle the composer sends back with the message; the turn preparer resolves
 * it (a vision description for an image, the stored text for a document).
 *
 * Ownership is enforced by passing the current user's uid into the store (never
 * a route-level check), and the served derivative is governed by
 * aincient_core's owner-scoped hook_file_download — so an image thumb is shown
 * to the owner (or a store administrator) and no one else.
 */
final class ContextController extends ControllerBase {

  /**
   * Client extensions dispatched to the text-document store path.
   */
  private const DOCUMENT_EXTENSIONS = ['md', 'markdown', 'txt'];

  public function __construct(
    private readonly ContextStore $contextStore,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('aincient_core.context_store'),
      $container->get('file_url_generator'),
    );
  }

  /**
   * POST /atelier/chat/thread/{thread_id}/attach — ingest one attachment.
   *
   * Multipart body: `file` (an image or a Tier A text document). The store path
   * is chosen by the client extension; each store validates the CONTENT, so a
   * mislabelled file is rejected there rather than mis-stored. Returns a
   * `{ item: … }` row whose shape depends on kind — an image carries
   * `thumb`/`width`/`height`, a document carries `kind`/`size`/`mime` — so the
   * composer can preview it and later replay its `ref`. We do NOT require an
   * existing thread/session — the operator attaches before the first message is
   * sent; the thread id is just recorded on the ledger row.
   */
  public function attach(Request $request, string $thread_id): JsonResponse {
    $file = $request->files->get('file');
    if (!$file instanceof UploadedFile) {
      return new JsonResponse(['error' => 'No file uploaded.'], 400);
    }

    $uid = (int) $this->currentUser()->id();
    $isDocument = in_array(mb_strtolower((string) $file->getClientOriginalExtension()), self::DOCUMENT_EXTENSIONS, TRUE);

    try {
      // The store validates the content and throws on an oversized / mislabelled
      // / malformed upload. The turn is null: unknown at attach time (the
      // composer attaches before the message is sent).
      $entry = $isDocument
        ? $this->contextStore->storeDocument($file, $thread_id, NULL, $uid)
        : $this->contextStore->storeImage($file, $thread_id, NULL, $uid);
    }
    catch (\Throwable $e) {
      // The operator must see a clean rejection, not a 500 — and the underlying
      // message never leaks to the client (it can carry validator internals).
      $this->getLogger('aincient_chat')->warning('Context attachment rejected for thread @thread: @message', [
        '@thread' => $thread_id,
        '@message' => $e->getMessage(),
      ]);
      $error = $isDocument
        ? 'The document could not be processed.'
        : 'The image could not be processed.';
      return new JsonResponse(['error' => $error], 422);
    }

    return new JsonResponse(['item' => $this->item($entry, $isDocument)], 201);
  }

  /**
   * Builds the composer item row for a stored entry, shaped by kind.
   *
   * @param \Drupal\aincient_core\Entity\ContextLedgerEntry $entry
   *   The persisted ledger entry.
   * @param bool $isDocument
   *   Whether the entry is a text document.
   *
   * @return array<string, mixed>
   *   The `item` row: images carry thumb/width/height; documents carry
   *   kind/size/mime and no thumb.
   */
  private function item($entry, bool $isDocument): array {
    $base = [
      'id' => (int) $entry->id(),
      'ref' => 'context:' . $entry->id(),
      'filename' => $entry->get('filename')->value,
    ];

    if ($isDocument) {
      return $base + [
        'kind' => 'document',
        'size' => (int) $entry->get('size')->value,
        'mime' => $entry->get('sniffed_mime')->value,
      ];
    }

    // The private-file display URL: a private:// uri yields the /system/files/…
    // route, which cores through the owner-scoped hook_file_download, so the
    // same-origin console owner can display it and no one else can.
    $fileUri = $entry->get('file')->entity->getFileUri();
    return $base + [
      'kind' => 'image',
      'thumb' => $this->fileUrlGenerator->generateString($fileUri),
      'width' => (int) $entry->get('width')->value,
      'height' => (int) $entry->get('height')->value,
    ];
  }

}
