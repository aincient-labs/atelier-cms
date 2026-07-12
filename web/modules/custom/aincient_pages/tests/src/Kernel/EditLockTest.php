<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Kernel;

use Drupal\aincient_pages\EditLock;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the single-writer editor lock — the concurrency control that makes
 * "two studios/tabs/users editing one draft" structurally impossible.
 *
 * The lock is keyed (nid, langcode); the authority is the fencing token. These
 * tests exercise the four resolutions of {@see EditLock::acquire} (free /
 * same-session / held-by-me-elsewhere / held-by-another) plus the fence
 * ({@see EditLock::verify}) and release. Node access is out of scope here (it's
 * enforced in the controller); this is the store-level lock mechanics.
 *
 * @group aincient
 */
#[RunTestsInSeparateProcesses]
final class EditLockTest extends KernelTestBase {

  protected static $modules = ['system', 'user', 'field', 'text', 'node', 'workflows', 'content_moderation', 'aincient_pages'];

  private User $alice;
  private User $bob;

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    // The lock's own (non-entity) table, defined in hook_schema.
    $this->installSchema('aincient_pages', ['aincient_edit_lock']);
    $this->alice = User::create(['name' => 'alice']);
    $this->alice->save();
    $this->bob = User::create(['name' => 'bob']);
    $this->bob->save();
  }

  private function lock(): EditLock {
    return $this->container->get('aincient_pages.edit_lock');
  }

  private function actAs(User $user): void {
    $this->container->get('current_user')->setAccount($user);
  }

  public function testAcquireOnFreeNodeMintsTokenAndFencePasses(): void {
    $this->actAs($this->alice);
    $r = $this->lock()->acquire(1, '', 'content');

    $this->assertSame('acquired', $r['status']);
    $this->assertNotEmpty($r['token']);
    $this->assertSame((int) $this->alice->id(), $r['holder']['uid']);
    $this->assertSame('content', $r['holder']['studio']);
    $this->assertTrue($r['holder']['mine']);

    $this->assertTrue($this->lock()->verify(1, '', $r['token']));
    $this->assertFalse($this->lock()->verify(1, '', 'not-the-token'));
    $this->assertFalse($this->lock()->verify(1, '', NULL), 'A caller with no token never passes the fence.');
  }

  public function testSameUserSecondTabIsHeldSelfWithoutToken(): void {
    $this->actAs($this->alice);
    $this->lock()->acquire(1, '', 'content');

    // The same user, a second tab (no carried token, no force): the lock is
    // held — by them — so acquire reports held_self and withholds the token.
    $again = $this->lock()->acquire(1, '', 'content');
    $this->assertSame('held_self', $again['status']);
    $this->assertNull($again['token']);
    $this->assertTrue($again['holder']['mine']);
  }

  public function testDifferentUserIsHeldOther(): void {
    $this->actAs($this->alice);
    $this->lock()->acquire(1, '', 'content');

    $this->actAs($this->bob);
    $held = $this->lock()->acquire(1, '', 'content');
    $this->assertSame('held_other', $held['status']);
    $this->assertNull($held['token']);
    $this->assertFalse($held['holder']['mine']);
    $this->assertSame('alice', $held['holder']['name']);
  }

  public function testSameSessionHandoverKeepsTokenAndFlipsStudio(): void {
    $this->actAs($this->alice);
    $first = $this->lock()->acquire(1, '', 'content');

    // Carrying the token across the Content → Checks switch keeps the lock (same
    // session), returns the SAME token, and updates the studio — no takeover.
    $handover = $this->lock()->acquire(1, '', 'checks', $first['token']);
    $this->assertSame('acquired', $handover['status']);
    $this->assertSame($first['token'], $handover['token']);
    $this->assertSame('checks', $handover['holder']['studio']);
  }

  public function testForceTakeoverMintsNewTokenAndStalesTheOld(): void {
    $this->actAs($this->alice);
    $alicesToken = $this->lock()->acquire(1, '', 'content')['token'];

    // Bob explicitly takes over: a new token is minted and Alice's is now stale.
    $this->actAs($this->bob);
    $taken = $this->lock()->acquire(1, '', 'content', NULL, TRUE);
    $this->assertSame('acquired', $taken['status']);
    $this->assertNotSame($alicesToken, $taken['token']);
    $this->assertSame((int) $this->bob->id(), $taken['holder']['uid']);

    $this->assertFalse($this->lock()->verify(1, '', $alicesToken), "The taken-over session's token no longer holds the fence.");
    $this->assertTrue($this->lock()->verify(1, '', $taken['token']));
  }

  public function testReleaseRequiresMatchingTokenUnlessForced(): void {
    $this->actAs($this->alice);
    $token = $this->lock()->acquire(1, '', 'content')['token'];

    $this->assertFalse($this->lock()->release(1, '', 'wrong-token'), 'A stale token cannot release the lock.');
    $this->assertNotNull($this->lock()->status(1, ''));

    $this->assertTrue($this->lock()->release(1, '', $token));
    $this->assertNull($this->lock()->status(1, ''), 'The matching token releases it.');

    // Force-release works without any token (the explicit takeover escape hatch).
    $this->lock()->acquire(1, '', 'content');
    $this->assertTrue($this->lock()->release(1, '', NULL, TRUE));
    $this->assertNull($this->lock()->status(1, ''));
  }

  public function testLockIsPartitionedByLangcode(): void {
    $this->actAs($this->alice);
    $source = $this->lock()->acquire(1, '', 'content');
    // A different translation of the same node is an independent lock.
    $de = $this->lock()->acquire(1, 'de', 'content');
    $this->assertSame('acquired', $de['status']);
    $this->assertNotSame($source['token'], $de['token']);
    $this->assertTrue($this->lock()->verify(1, '', $source['token']));
    $this->assertTrue($this->lock()->verify(1, 'de', $de['token']));
    $this->assertFalse($this->lock()->verify(1, 'de', $source['token']), 'A source-language token does not unlock a translation.');
  }

}
