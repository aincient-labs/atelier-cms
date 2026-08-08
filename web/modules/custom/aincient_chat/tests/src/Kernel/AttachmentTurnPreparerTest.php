<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_chat\Kernel;

use Drupal\aincient_core\ModelRoles;
use Drupal\Core\File\FileExists;
use Drupal\file\Entity\File;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\aincient_core\Traits\ScriptedInferenceTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The vision seam, wired: attachments become fenced, pre-described user text.
 *
 * Drives the REAL path — a stored context ledger entry, the real gateway, the
 * scripted vision provider standing in for a live one — through
 * {@see \Drupal\aincient_chat\Chat\AttachmentTurnPreparer}, and pins the
 * properties DECISIONS 0347 settled: the operator's message survives, the
 * description is fenced, the ledger's forensic `description` field is filled,
 * the owner check is enforced, and a bad ref degrades to "as if no attachment"
 * rather than throwing.
 */
#[RunTestsInSeparateProcesses]
#[Group('aincient_chat')]
final class AttachmentTurnPreparerTest extends KernelTestBase {

  use ScriptedInferenceTrait;
  use UserCreationTrait;

  /**
   * The description the scripted vision provider answers with.
   */
  private const SCRIPTED_DESCRIPTION = 'A weathered brass astrolabe on a linen cloth; the engraved rim reads MMXXVI.';

  /**
   * {@inheritdoc}
   *
   * KernelTestBase does NOT auto-enable declared dependencies (the 0359 lesson),
   * so every module the container needs is listed explicitly.
   */
  protected static $modules = [
    'system', 'user', 'file', 'image', 'key',
    'aincient_core', 'aincient_chat', 'aincient_inference_test',
  ];

  /**
   * The signed-in actor who owns the attachments under test.
   */
  private int $uid;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installEntitySchema('aincient_context_entry');
    $this->installConfig(['system']);

    $account = $this->setUpCurrentUser();
    $this->uid = (int) $account->id();

    // A bound + connected vision role, answering a fixed description — the real
    // resolution/connected-credential/invoke path, no live provider.
    $this->connectScriptedProvider();
    $this->bindScriptedRole(ModelRoles::VISION);
    $this->scriptInferenceText(self::SCRIPTED_DESCRIPTION);
  }

  /**
   * The preparer under test.
   */
  private function preparer(): object {
    return $this->container->get('aincient_chat.attachment_turn_preparer');
  }

  /**
   * A real, owned attachment is described, fenced, and folded into the turn.
   */
  public function testOwnedAttachmentIsDescribedAndFenced(): void {
    $id = $this->createEntry($this->uid, 'astrolabe.webp');

    $out = $this->preparer()->augment('What is this object?', ['context:' . $id], $this->uid);

    // The operator's message survives, ahead of the injected block.
    $this->assertStringContainsString('What is this object?', $out);
    $this->assertLessThan(
      strpos($out, self::SCRIPTED_DESCRIPTION),
      strpos($out, 'What is this object?'),
    );
    // The scripted description is present, wrapped in OUR fence.
    $this->assertStringContainsString(self::SCRIPTED_DESCRIPTION, $out);
    $this->assertStringContainsString('<<<UNTRUSTED_ATTACHMENT label="astrolabe.webp">>>', $out);
    $this->assertStringContainsString('<<<END_UNTRUSTED_ATTACHMENT>>>', $out);
    // The untrusted-data preamble is present.
    $this->assertStringContainsString('untrusted DATA', $out);
    // An IMAGE turn carries the logo-handoff guidance (DECISIONS 0372, Phase 3):
    // an attached image can't be placed as a logo/favicon from chat.
    $this->assertStringContainsString('logo, brand mark, or favicon', $out);
    $this->assertStringContainsString('Identity studio', $out);

    // The forensic `description` field is now populated on the ledger row.
    $entry = $this->reloadEntry($id);
    $this->assertSame(self::SCRIPTED_DESCRIPTION, trim((string) $entry->get('description')->value));
  }

  /**
   * A re-send reuses the stored description without asking vision again.
   */
  public function testStoredDescriptionIsReusedOnResend(): void {
    $id = $this->createEntry($this->uid, 'astrolabe.webp');

    $first = $this->preparer()->augment('First look.', ['context:' . $id], $this->uid);
    $this->assertStringContainsString(self::SCRIPTED_DESCRIPTION, $first);

    // Re-script the provider with a DIFFERENT answer; a reused description must
    // ignore it entirely (the cheap cache off the persisted field).
    $this->scriptInferenceText('A COMPLETELY DIFFERENT description that must never appear.');

    $second = $this->preparer()->augment('Second look.', ['context:' . $id], $this->uid);
    $this->assertStringContainsString(self::SCRIPTED_DESCRIPTION, $second);
    $this->assertStringNotContainsString('COMPLETELY DIFFERENT', $second);
  }

  /**
   * An attachment owned by another user is skipped — the mandatory owner check.
   */
  public function testForeignAttachmentIsSkipped(): void {
    $other = $this->createUser();
    $id = $this->createEntry((int) $other->id(), 'not-mine.webp');

    $out = $this->preparer()->augment('Whose is this?', ['context:' . $id], $this->uid);

    $this->assertSame('Whose is this?', $out, 'A foreign attachment must not augment the turn.');
    $this->assertStringNotContainsString(self::SCRIPTED_DESCRIPTION, $out);
    // And it must NOT have been described (no forensic write on someone else's row).
    $entry = $this->reloadEntry($id);
    $this->assertSame('', trim((string) $entry->get('description')->value));
  }

  /**
   * A document attachment is folded in as fenced text, with NO vision call.
   */
  public function testDocumentIsFoldedInWithoutVision(): void {
    // Arm vision with a sentinel that must never surface — a document must not
    // reach the vision path at all.
    $this->scriptInferenceText('VISION MUST NOT RUN for a document.');
    $text = "brand_primary: cinnabar\nradius_md: 0.5rem";
    $id = $this->createDocumentEntry($this->uid, 'design.md', $text);

    $out = $this->preparer()->augment('Apply these tokens.', ['context:' . $id], $this->uid);

    $this->assertStringContainsString('Apply these tokens.', $out);
    // The document's text is folded in verbatim, inside OUR fence.
    $this->assertStringContainsString($text, $out);
    $this->assertStringContainsString('<<<UNTRUSTED_ATTACHMENT label="design.md">>>', $out);
    // Preamble names a document, and vision output never appears.
    $this->assertStringContainsString('document', $out);
    $this->assertStringContainsString('untrusted DATA', $out);
    $this->assertStringNotContainsString('VISION MUST NOT RUN', $out);
    // A document-only turn is NOT an image handoff — the logo guidance is
    // image-scoped and must not appear here.
    $this->assertStringNotContainsString('logo, brand mark, or favicon', $out);
  }

  /**
   * A mixed turn names both an image and a document in the preamble.
   */
  public function testMixedImageAndDocumentPreamble(): void {
    $img = $this->createEntry($this->uid, 'astrolabe.webp');
    $doc = $this->createDocumentEntry($this->uid, 'design.md', 'brand_primary: cinnabar');

    $out = $this->preparer()->augment(
      'Both please.',
      ['context:' . $img, 'context:' . $doc],
      $this->uid,
    );

    // The image is described (vision) and the document text is inlined.
    $this->assertStringContainsString(self::SCRIPTED_DESCRIPTION, $out);
    $this->assertStringContainsString('brand_primary: cinnabar', $out);
    // Both kinds are named in the preamble.
    $this->assertStringContainsString('1 image', $out);
    $this->assertStringContainsString('1 document', $out);
  }

  /**
   * A non-existent ref is skipped without throwing.
   */
  public function testMissingRefIsSkipped(): void {
    $out = $this->preparer()->augment('Anybody home?', ['context:999999'], $this->uid);
    $this->assertSame('Anybody home?', $out);
  }

  /**
   * No refs returns the message verbatim.
   */
  public function testEmptyRefsReturnsMessageVerbatim(): void {
    $out = $this->preparer()->augment('Just a plain turn.', [], $this->uid);
    $this->assertSame('Just a plain turn.', $out);
  }

  /**
   * Creates a context ledger entry with a real stored derivative File.
   *
   * @param int $uid
   *   The owner.
   * @param string $filename
   *   The client display name recorded on the entry.
   *
   * @return int
   *   The new entry id.
   */
  private function createEntry(int $uid, string $filename): int {
    // A tiny real WebP-ish payload is enough: the scripted provider does not read
    // the bytes, only that describeImage was handed a non-empty ImageRef.
    $bytes = 'RIFF....WEBPVP8 fake-but-nonempty-derivative-bytes';
    $uri = $this->container->get('file_system')->saveData(
      $bytes,
      'public://astrolabe-' . $uid . '.webp',
      FileExists::Replace,
    );
    $file = File::create([
      'uri' => $uri,
      'filemime' => 'image/webp',
      'status' => 1,
    ]);
    $file->save();

    $storage = $this->container->get('entity_type.manager')->getStorage('aincient_context_entry');
    $entry = $storage->create([
      'uid' => $uid,
      'thread_id' => 'thr_test',
      'kind' => 'image',
      'filename' => $filename,
      'file' => $file->id(),
    ]);
    $entry->save();

    return (int) $entry->id();
  }

  /**
   * Creates a document ledger entry with the text stored as its File.
   *
   * @param int $uid
   *   The owner.
   * @param string $filename
   *   The client display name recorded on the entry.
   * @param string $text
   *   The normalized text — stored both as the File and on `description`.
   *
   * @return int
   *   The new entry id.
   */
  private function createDocumentEntry(int $uid, string $filename, string $text): int {
    $uri = $this->container->get('file_system')->saveData(
      $text,
      'public://doc-' . $uid . '-' . md5($filename) . '.txt',
      FileExists::Replace,
    );
    $file = File::create([
      'uri' => $uri,
      'filemime' => 'text/markdown',
      'status' => 1,
    ]);
    $file->save();

    $storage = $this->container->get('entity_type.manager')->getStorage('aincient_context_entry');
    $entry = $storage->create([
      'uid' => $uid,
      'thread_id' => 'thr_test',
      'kind' => 'document',
      'filename' => $filename,
      'description' => $text,
      'file' => $file->id(),
    ]);
    $entry->save();

    return (int) $entry->id();
  }

  /**
   * Reloads an entry fresh from storage (no static cache).
   */
  private function reloadEntry(int $id): object {
    $storage = $this->container->get('entity_type.manager')->getStorage('aincient_context_entry');
    $storage->resetCache([$id]);
    return $storage->load($id);
  }

}
