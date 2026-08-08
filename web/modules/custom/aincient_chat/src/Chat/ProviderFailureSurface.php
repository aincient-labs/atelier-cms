<?php

declare(strict_types=1);

namespace Drupal\aincient_chat\Chat;

use Drupal\aincient_core\InstallCapabilities;
use Drupal\aincient_core\Inference\ProviderCall;

/**
 * Which action a provider failure offers the reader, chosen by its kind.
 *
 * The whole reason {@see ProviderCall}'s nine kinds exist. A rejected key and an
 * overloaded provider read identically as a red failed node, and they need
 * opposite responses: one is something the reader can fix in thirty seconds, the
 * other is somebody else's minute to wait out. This class is the one table that
 * says which is which, and it is deliberately server-side — the console renders
 * the action it is handed, so the split is asserted in one place with one test
 * rather than half here and half in the bundle.
 *
 * TWO KINDS CARRY A LINK, six carry nothing:
 *   - `auth` — the key is wrong, revoked or not entitled: reconnect the provider.
 *   - `model_missing` — the bound model does not exist at that provider: change
 *     the model.
 * Both land on the models form, which is where credentials and role bindings both
 * live.
 *
 * Everything else is a sentence alone. `rate_limit` and `unavailable` are the
 * provider's own weather — a link to our settings would suggest the reader
 * misconfigured something they did not. `too_long` is a shape the run hit, not a
 * setting. `tool_malformed` is the model garbling a tool call — the honest fix is
 * "a more capable model", guidance not a one-click action. `refused`, `rejected`
 * and `unknown` have no action we can name honestly, and an affordance offered on
 * a guess costs more trust than the silence does.
 *
 * THE LINK IS NAVIGATION, never a re-send. Re-sending is a SEPARATE decision,
 * {@see self::retry()}, and it is gated twice because a careless re-send after a
 * turn that already applied part of its work could create a second page or apply a
 * brand twice — the hazard StaleTurnRecovery exists for, which is precisely why
 * that recovery does not resume either.
 *
 * RETRY IS OFFERED FOR FOUR KINDS AND ONLY WHEN THE TURN APPLIED NOTHING:
 *   - `rate_limit`, `unavailable`, `unknown` — a transient no from the provider;
 *     the identical request may well succeed a minute later. The reader IS the
 *     retry mechanism: a button they choose to press, never a timer.
 *   - `tool_malformed` — the model garbled its tool call; generation is
 *     stochastic, so the identical request may well come back well-formed. Same
 *     reader-driven retry, no link.
 *   - `auth` and `model_missing` keep their link instead — repeating a request
 *     does not fix a credential or a binding.
 *   - `too_long` is excluded because an identical request truncates identically;
 *     that is the whole reason it is classified apart.
 *   - `refused` and `rejected` are decisions, not weather. Repeating them is noise.
 * The second gate is the caller's: it passes whether anything in this turn already
 * took effect. When it did, the sentence stands alone plus {@see self::note()} —
 * a missing button is a small annoyance, a duplicate published page is a data
 * problem.
 */
final class ProviderFailureSurface {

  /**
   * Kind → the words on its link. Absent kind means: sentence only.
   */
  private const LABELS = [
    ProviderCall::KIND_AUTH => 'Reconnect provider',
    ProviderCall::KIND_MODEL_MISSING => 'Change model',
  ];

  /**
   * The kinds a plain re-send can plausibly get past. Order is documentation.
   */
  private const RETRYABLE = [
    ProviderCall::KIND_RATE_LIMIT,
    ProviderCall::KIND_UNAVAILABLE,
    ProviderCall::KIND_UNKNOWN,
    ProviderCall::KIND_TOOL_MALFORMED,
  ];

  /**
   * The action for this kind, or NULL when the sentence stands alone.
   *
   * @param string $kind
   *   One of {@see ProviderCall}'s `KIND_*` values. Anything unrecognised is
   *   treated as `unknown` — sentence only, which is the safe direction.
   * @param callable(): string $modelsUrl
   *   Resolves where the models form lives — normally
   *   {@see InstallCapabilities::setupUrl()}, the capability chips' own
   *   derivation off Drupal's route table. Injected rather than called here so
   *   this table stays a pure function, and DEFERRED because six of the eight
   *   kinds never need a URL: resolving a route for a rate limit would make this
   *   decision depend on a container it has no business touching.
   *
   * @return array{label: string, url: string}|null
   *   The link to render, or NULL for no action at all.
   */
  public static function action(string $kind, callable $modelsUrl): ?array {
    $label = self::LABELS[$kind] ?? NULL;
    if ($label === NULL) {
      return NULL;
    }
    // An empty URL degrades to sentence-only: a dead link turns one diagnosable
    // failure into two.
    $url = $modelsUrl();

    return $url === '' ? NULL : ['label' => $label, 'url' => $url];
  }

  /**
   * Whether this failure may offer the reader a Retry.
   *
   * @param string $kind
   *   One of {@see ProviderCall}'s `KIND_*` values. An unrecognised string is
   *   NOT retryable — unlike the declared `unknown`, a kind nobody chose is a
   *   kind nobody reasoned about, and the safe direction is no button.
   * @param bool $applied
   *   TRUE when something in this turn has already taken effect (a tool ran).
   *   Suppresses Retry outright, whatever the kind.
   *
   * @return bool
   *   TRUE to offer Retry.
   */
  public static function retry(string $kind, bool $applied): bool {
    return !$applied && in_array($kind, self::RETRYABLE, TRUE);
  }

  /**
   * Why Retry is absent, when it was withheld only because work already landed.
   *
   * Said out loud on purpose: a reader who has seen the button on the previous
   * rate limit will look for it, and "the button moved" is a worse story than one
   * short honest sentence. NULL for every other case — the kinds that never offer
   * Retry owe no explanation for a button they were never going to have.
   *
   * @param string $kind
   *   One of {@see ProviderCall}'s `KIND_*` values.
   * @param bool $applied
   *   TRUE when something in this turn has already taken effect.
   *
   * @return string|null
   *   The note to render under the sentence, or NULL for none.
   */
  public static function note(string $kind, bool $applied): ?string {
    if (!$applied || !in_array($kind, self::RETRYABLE, TRUE)) {
      return NULL;
    }

    return 'Part of this request already took effect, so Atelier will not send it again on its own — ask for the rest in a new message.';
  }

}
