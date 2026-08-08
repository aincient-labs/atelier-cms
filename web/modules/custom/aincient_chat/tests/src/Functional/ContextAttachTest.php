<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_chat\Functional;

use Drupal\Core\Url;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The composer image-attachment ingest endpoint (context store HTTP seam).
 *
 * Covers POST /atelier/chat/thread/{thread_id}/attach end to end over real
 * HTTP: an operator's PNG is ingested into the private context store,
 * returning the opaque `context:<id>` ref + a private thumb URL; the ledger row
 * is created and owned by that operator with the posted thread id; a non-image
 * is a clean 422 that writes nothing; and the route is denied to anyone without
 * the console permission. The owner-scoped serving of the thumb (owner 200,
 * stranger 403) is exercised here too, the visible proof that aincient_core's
 * hook_file_download governs the private derivative.
 *
 * @group aincient
 * @covers \Drupal\aincient_chat\Controller\ContextController
 */
#[RunTestsInSeparateProcesses]
class ContextAttachTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['node', 'aincient_chat'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   *
   * The aincient_chat module ships install config that predates its own schema
   * (aincient_chat.settings:default_workflow / :exposed_workflows have none) —
   * a known, pre-existing drift the sibling EditorPillThemedTest also relaxes.
   * Without this, module install fails before this test's subject runs.
   */
  protected $strictConfigSchema = FALSE;

  /**
   * The thread id every attach in this test targets.
   */
  private const THREAD = 'thr_ctxtest';

  /**
   * The absolute attach URL for the fixed test thread.
   */
  private function attachUrl(): string {
    return Url::fromRoute('aincient_chat.context_attach', ['thread_id' => self::THREAD])
      ->setAbsolute()
      ->toString();
  }

  /**
   * The BrowserKit client behind the current mink session (carries the cookie).
   */
  private function client() {
    return $this->getSession()->getDriver()->getClient();
  }

  /**
   * POSTs one multipart `file` field to the attach URL.
   *
   * @return array
   *   A [status, body] pair: the HTTP status and the decoded JSON body.
   */
  private function postFile(string $path, string $name, string $mime): array {
    $this->client()->request('POST', $this->attachUrl(), [], [
      'file' => [
        'tmp_name' => $path,
        'name' => $name,
        'type' => $mime,
        'error' => 0,
        'size' => filesize($path),
      ],
    ]);
    $response = $this->client()->getInternalResponse();
    return [$response->getStatusCode(), json_decode($response->getContent(), TRUE) ?: []];
  }

  /**
   * Builds a real PNG on disk via GD and returns its path.
   */
  private function makePng(): string {
    $path = \Drupal::service('file_system')->getTempDirectory() . '/' . uniqid('ctx-', TRUE) . '.png';
    $im = imagecreatetruecolor(120, 90);
    imagepng($im, $path);
    imagedestroy($im);
    return $path;
  }

  /**
   * How many ledger rows currently exist.
   */
  private function ledgerCount(): int {
    return (int) \Drupal::entityTypeManager()
      ->getStorage('aincient_context_entry')
      ->getQuery()
      ->accessCheck(FALSE)
      ->count()
      ->execute();
  }

  /**
   * Happy path: an operator's PNG is ingested, and the ledger row is owned.
   */
  public function testOperatorAttachesImage(): void {
    $operator = $this->drupalCreateUser(['use aincient operator console']);
    $this->drupalLogin($operator);

    $path = $this->makePng();
    [$status, $body] = $this->postFile($path, 'photo.png', 'image/png');
    unlink($path);

    $this->assertSame(201, $status);
    $this->assertArrayHasKey('item', $body);
    $item = $body['item'];
    $this->assertIsInt($item['id']);
    $this->assertGreaterThan(0, $item['id']);
    $this->assertSame('context:' . $item['id'], $item['ref']);
    $this->assertSame('photo.png', $item['filename']);
    $this->assertStringContainsString('/system/files/', $item['thumb']);
    // A 120x90 source is under the long-edge ceiling, so dimensions are kept.
    $this->assertSame(120, $item['width']);
    $this->assertSame(90, $item['height']);

    // The ledger row exists, owned by the operator, tagged with the thread id.
    $entry = \Drupal::entityTypeManager()
      ->getStorage('aincient_context_entry')
      ->load($item['id']);
    $this->assertNotNull($entry);
    $this->assertSame((int) $operator->id(), (int) $entry->getOwnerId());
    $this->assertSame(self::THREAD, $entry->get('thread_id')->value);

    // The owner can display the private thumb (owner-scoped download hook).
    $this->drupalGet($item['thumb']);
    $this->assertSession()->statusCodeEquals(200);

    // A different operator is denied the same private thumb — the served file
    // is governed by the owner-scoped download hook, not the route permission.
    $stranger = $this->drupalCreateUser(['use aincient operator console']);
    $this->drupalLogin($stranger);
    $this->drupalGet($item['thumb']);
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * A file that is neither a valid image nor a document is a clean 422.
   *
   * A non-document extension routes to the image path, so plain text posted as
   * a .png is rejected there with the image-shaped message.
   */
  public function testNonImageRejected(): void {
    $operator = $this->drupalCreateUser(['use aincient operator console']);
    $this->drupalLogin($operator);

    $before = $this->ledgerCount();
    $path = \Drupal::service('file_system')->getTempDirectory() . '/' . uniqid('ctx-', TRUE) . '.png';
    file_put_contents($path, 'this is plainly not an image');
    [$status, $body] = $this->postFile($path, 'fake.png', 'image/png');
    unlink($path);

    $this->assertSame(422, $status);
    $this->assertArrayHasKey('error', $body);
    // The client sees a clean message, never the validator internals.
    $this->assertSame('The image could not be processed.', $body['error']);
    $this->assertSame($before, $this->ledgerCount(), 'A rejected upload writes no ledger row.');
  }

  /**
   * Happy path: a Markdown design file is ingested as a document.
   *
   * The document item shape carries kind/size/mime and NO thumb/width/height;
   * the ledger row is kind 'document' with the normalized text in description.
   */
  public function testOperatorAttachesDocument(): void {
    $operator = $this->drupalCreateUser(['use aincient operator console']);
    $this->drupalLogin($operator);

    $path = \Drupal::service('file_system')->getTempDirectory() . '/' . uniqid('ctx-', TRUE) . '.md';
    file_put_contents($path, "# Brand\r\n\r\nbrand_primary: oklch(0.7 0.2 30)\n");
    [$status, $body] = $this->postFile($path, 'design.md', 'text/markdown');
    unlink($path);

    $this->assertSame(201, $status);
    $item = $body['item'];
    $this->assertSame('context:' . $item['id'], $item['ref']);
    $this->assertSame('design.md', $item['filename']);
    $this->assertSame('document', $item['kind']);
    $this->assertGreaterThan(0, $item['size']);
    $this->assertArrayHasKey('mime', $item);
    // Document items carry no image-shaped fields.
    $this->assertArrayNotHasKey('thumb', $item);
    $this->assertArrayNotHasKey('width', $item);

    $entry = \Drupal::entityTypeManager()
      ->getStorage('aincient_context_entry')
      ->load($item['id']);
    $this->assertNotNull($entry);
    $this->assertSame('document', $entry->get('kind')->value);
    $this->assertSame((int) $operator->id(), (int) $entry->getOwnerId());
    // Normalized text (CRLF → LF) is stored on the description for turn injection.
    $this->assertStringContainsString('brand_primary', $entry->get('description')->value);
    $this->assertStringNotContainsString("\r", $entry->get('description')->value);
  }

  /**
   * A binary disguised with a .md name is a clean, document-shaped 422.
   */
  public function testDisguisedDocumentRejected(): void {
    $operator = $this->drupalCreateUser(['use aincient operator console']);
    $this->drupalLogin($operator);

    $before = $this->ledgerCount();
    $path = \Drupal::service('file_system')->getTempDirectory() . '/' . uniqid('ctx-', TRUE) . '.md';
    // Real PNG bytes — not text, not valid UTF-8.
    file_put_contents($path, "\x89PNG\r\n\x1a\n" . random_bytes(64));
    [$status, $body] = $this->postFile($path, 'evil.md', 'text/markdown');
    unlink($path);

    $this->assertSame(422, $status);
    $this->assertSame('The document could not be processed.', $body['error']);
    $this->assertSame($before, $this->ledgerCount(), 'A rejected document writes no ledger row.');
  }

  /**
   * A missing multipart `file` is a 400, not a validator error.
   */
  public function testMissingFileIs400(): void {
    $operator = $this->drupalCreateUser(['use aincient operator console']);
    $this->drupalLogin($operator);

    $this->client()->request('POST', $this->attachUrl());
    $this->assertSame(400, $this->client()->getInternalResponse()->getStatusCode());
  }

  /**
   * Anonymous (no console permission) is denied the route.
   */
  public function testAnonymousDenied(): void {
    // A fresh functional install starts anonymous — no logout needed.
    $path = $this->makePng();
    [$status] = $this->postFile($path, 'photo.png', 'image/png');
    unlink($path);
    $this->assertSame(403, $status);
    $this->assertSame(0, $this->ledgerCount(), 'A denied request writes no ledger row.');
  }

}
