<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_core\Unit;

use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tripwire: adding a capability re-opens the deferred taint-gate question.
 *
 * The attachment taint + tool gate (DECISIONS 0368) is DEFERRED, and safely so:
 * the gate only matters once the AI has an autonomous path to an irreversible or
 * outward-facing action, and today it does not — every Atelier capability is
 * reversible/internal (preview, propose, read, draft edit, generate a draft),
 * and publish/export/mail/delete are HUMAN actions, not agent tools. So there is
 * nothing to gate, and wiring a runtime gate now would protect nothing.
 *
 * The risk in deferring a security control is that it rots into a silent hole:
 * someone later adds a `PublishPage` or `SendEmail` capability and there is no
 * gate AND no alarm. This test IS the alarm. It freezes the current capability
 * roster; adding or removing a capability fails it, forcing a conscious pass over
 * the question the failure message asks. No Drupal bootstrap — it reads the
 * plugin files off disk, so it cannot be defeated by container state.
 *
 */
#[Group('aincient_core')]
final class CapabilityRosterTaintGuardTest extends UnitTestCase {

  /**
   * The capabilities that exist today, ALL reviewed as taint-safe.
   *
   * Taint-safe = reversible AND internal: it makes no change a person cannot
   * trivially undo, and emits nothing outward-facing or irreversible (no publish
   * to the live site, no export off disk, no mail, no delete, no third-party
   * send). A read-only capability qualifies by definition.
   *
   * @var array<int, string>
   */
  private const REVIEWED_TAINT_SAFE = [
    'BrandPicker',
    'FindReference',
    'GenerateAltText',
    'GenerateImage',
    'ListPages',
    'OnboardingPanel',
    'PreviewBrand',
    'PreviewChrome',
    'PreviewPage',
    'ProposeBrandStatus',
    'ProposeDesignTokens',
    'ProposeMediaName',
    'ReadMetaTags',
    'ResetPreview',
    // Routing-only: emits a `logo_handoff` card that deep-links to Identity →
    // Logo when an attached image is meant as the logo/favicon. It PLACES
    // nothing — no field write, no upload, no media — so it is reversible AND
    // internal (DECISIONS 0372, Phase 3). Taint-safe.
    'RouteLogoImage',
    'RunPageAudit',
    'StudioTour',
  ];

  /**
   * The capability roster is frozen; a change must reconfirm the taint decision.
   */
  public function testCapabilityRosterIsUnchangedSinceTheTaintReview(): void {
    // From tests/src/Unit up to modules/custom: Unit → src → tests → aincient_core
    // → custom.
    $customModules = dirname(__DIR__, 4);
    $found = [];
    foreach (glob($customModules . '/*/src/Plugin/AiCapability/*.php') ?: [] as $file) {
      $found[] = basename($file, '.php');
    }
    sort($found);

    $expected = self::REVIEWED_TAINT_SAFE;
    sort($expected);

    $message = <<<'TXT'
The Atelier capability roster changed since the taint-gate review (DECISIONS 0368).

If you ADDED a capability: decide whether it is taint-safe (reversible AND internal
— a preview, proposal, read, draft edit, or draft generation). If it can publish to
the live site, export off disk, send mail, delete, or otherwise take an irreversible
or outward-facing action, it is NOT taint-safe: the attachment taint + tool gate
(deferred in 0368) must be built and wired BEFORE that capability ships — an
attachment can carry a prompt injection that drives the agent to call it. If it is
taint-safe, add its class short name to REVIEWED_TAINT_SAFE here.

If you REMOVED a capability: drop it from REVIEWED_TAINT_SAFE.
TXT;

    $this->assertSame($expected, $found, $message);
  }

}
