<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_chat\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\aincient_chat\Chat\ProviderFailureSurface;
use Drupal\aincient_core\Inference\ProviderCall;

/**
 * Tests the kind → surface decision for a provider failure.
 *
 * The reason the nine kinds exist: an expired key is a thirty-second fix, a 429
 * is somebody else's minute. Every kind is pinned here, including the seven that
 * offer no link — "no affordance" is a decision, and an accidental link on
 * `rate_limit` would tell a reader they misconfigured a provider that is fine.
 *
 * @coversDefaultClass \Drupal\aincient_chat\Chat\ProviderFailureSurface
 * @group aincient
 */
final class ProviderFailureSurfaceTest extends UnitTestCase {

  /**
   * A stand-in for the models form URL the route table produces.
   */
  private const MODELS_URL = '/admin/config/aincient/models';

  /**
   * Every kind, whether it offers a link, and whether it may be re-sent.
   *
   * The third column is the Retry gate on KIND alone (a clean turn, nothing
   * applied). Three kinds are transient enough that the identical request may
   * simply work later; five are not, for five different reasons.
   *
   * @return array<string, array{string, string|null, bool}>
   *   Kind → expected link label (NULL for sentence-only) → retryable.
   */
  public static function kinds(): array {
    return [
      // A rejected credential is ours to fix, and the models form is where the
      // key lives.
      // A rejected credential is ours to fix, and repeating the request will be
      // rejected in exactly the same way — link, never Retry.
      'auth links to the models form' => [ProviderCall::KIND_AUTH, 'Reconnect provider', FALSE],
      // A model the provider does not have is a binding, fixed on the same form.
      'model_missing links to the models form' => [ProviderCall::KIND_MODEL_MISSING, 'Change model', FALSE],
      // The provider's own weather — no link (nothing of ours is misconfigured),
      // but a minute later the same request may sail through.
      'rate_limit may be re-sent' => [ProviderCall::KIND_RATE_LIMIT, NULL, TRUE],
      'unavailable may be re-sent' => [ProviderCall::KIND_UNAVAILABLE, NULL, TRUE],
      // We could not name it, so we cannot rule out that it was a blip.
      'unknown may be re-sent' => [ProviderCall::KIND_UNKNOWN, NULL, TRUE],
      // A shape the run hit, not a setting — and an identical request truncates
      // identically, which is the whole reason this kind is separate.
      'too_long is a sentence alone' => [ProviderCall::KIND_TOO_LONG, NULL, FALSE],
      // The provider made a decision. Repeating it is noise.
      'refused is a sentence alone' => [ProviderCall::KIND_REFUSED, NULL, FALSE],
      'rejected is a sentence alone' => [ProviderCall::KIND_REJECTED, NULL, FALSE],
      // A garbled tool call is stochastic: the identical request may come back
      // well-formed, so the reader may re-send. No link — the honest fix ("a
      // more capable model") is guidance, not a one-click action.
      'tool_malformed may be re-sent' => [ProviderCall::KIND_TOOL_MALFORMED, NULL, TRUE],
    ];
  }

  /**
   * @covers ::action
   * @dataProvider kinds
   */
  public function testEachKindGetsItsSurface(string $kind, ?string $label): void {
    $action = ProviderFailureSurface::action($kind, static fn(): string => self::MODELS_URL);

    if ($label === NULL) {
      $this->assertNull($action, sprintf('Kind "%s" must offer no action.', $kind));
      return;
    }
    $this->assertSame(['label' => $label, 'url' => self::MODELS_URL], $action);
  }

  /**
   * Every kind, and whether a clean turn may be re-sent.
   *
   * @covers ::retry
   * @dataProvider kinds
   */
  public function testEachKindGetsItsRetryDecision(string $kind, ?string $label, bool $retryable): void {
    $this->assertSame(
      $retryable,
      ProviderFailureSurface::retry($kind, FALSE),
      sprintf('Kind "%s" retry decision on a clean turn.', $kind),
    );
  }

  /**
   * A turn that already applied something is never re-sendable, whatever the kind.
   *
   * THE GATE THAT MATTERS. A rate limit hit AFTER the agent created a page is the
   * StaleTurnRecovery hazard wearing a friendly button: re-sending those same words
   * makes a second page. A missing button is an annoyance; that is a data problem.
   *
   * @covers ::retry
   * @dataProvider kinds
   */
  public function testAppliedWorkSuppressesRetryForEveryKind(string $kind): void {
    $this->assertFalse(
      ProviderFailureSurface::retry($kind, TRUE),
      sprintf('Kind "%s" must not offer Retry once work has landed.', $kind),
    );
  }

  /**
   * The note explains the withheld button — and only then.
   *
   * @covers ::note
   * @dataProvider kinds
   */
  public function testNoteOnlyExplainsAWithheldRetry(string $kind, ?string $label, bool $retryable): void {
    // Nothing applied: there is nothing to explain either way.
    $this->assertNull(ProviderFailureSurface::note($kind, FALSE));

    $note = ProviderFailureSurface::note($kind, TRUE);
    if (!$retryable) {
      // These kinds never had a Retry to lose, so they owe no explanation.
      $this->assertNull($note, sprintf('Kind "%s" must not explain a button it never offers.', $kind));
      return;
    }
    $this->assertIsString($note);
    $this->assertStringContainsString('already took effect', $note);
    // Product language: no model, provider or engine vocabulary in the note.
    $this->assertDoesNotMatchRegularExpression('/token|model|provider|node|job/i', $note);
  }

  /**
   * All nine kinds are covered — a tenth kind must fail this test, not slip
   * through as "sentence only" nobody chose.
   *
   * @covers ::action
   */
  public function testEveryKindIsAccountedFor(): void {
    $declared = [];
    foreach ((new \ReflectionClass(ProviderCall::class))->getConstants() as $name => $value) {
      if (str_starts_with($name, 'KIND_')) {
        $declared[] = $value;
      }
    }
    $tested = array_map(static fn(array $case): string => $case[0], array_values(self::kinds()));

    sort($declared);
    sort($tested);
    $this->assertSame($declared, $tested);
  }

  /**
   * An action a kind earns still degrades to sentence-only with no URL.
   *
   * A dead link is worse than no link: it turns a diagnosable failure into two.
   *
   * @covers ::action
   */
  public function testNoUrlMeansNoAction(): void {
    $this->assertNull(ProviderFailureSurface::action(ProviderCall::KIND_AUTH, static fn(): string => ''));
  }

  /**
   * A kind nobody declared is treated as `unknown`, not as an opportunity.
   *
   * @covers ::action
   */
  public function testUnrecognisedKindOffersNothing(): void {
    $this->assertNull(ProviderFailureSurface::action('teapot', static fn(): string => self::MODELS_URL));
    // And no Retry either. `unknown` is retryable because somebody decided it was;
    // a kind nobody declared is a kind nobody reasoned about.
    $this->assertFalse(ProviderFailureSurface::retry('teapot', FALSE));
    $this->assertNull(ProviderFailureSurface::note('teapot', TRUE));
  }

}
