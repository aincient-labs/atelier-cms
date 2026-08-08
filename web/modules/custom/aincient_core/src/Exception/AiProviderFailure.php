<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Exception;

use Drupal\aincient_core\Inference\ProviderCall;

/**
 * A provider request failed, carrying what the upstream actually said.
 *
 * Thrown on the inference path ({@see \Drupal\aincient_core\Inference\AiGateway},
 * {@see \Drupal\aincient_core\Inference\ChatCompleter}) in place of an opaque
 * transport exception whose message was just
 * "Server error (HTTP 503) occurred." (DECISIONS 0269). The original is always
 * the `previous` exception; this one exists to carry a diagnosable message and
 * to be greppable.
 *
 * Deliberately extends \Exception, NOT \RuntimeException. FlowDrop's
 * NodeRuntimeService routes a \RuntimeException through its inner catch (error
 * Output → error-edge routing, node status COMPLETED), while everything else
 * takes the generic path: node status FAILED, `logNodeFailure()`, and a
 * "Node execution failed for <node>: <message>" rethrow. The latter is what a
 * provider failure does today, and it is the more forensically useful of the
 * two on an appliance where dblog is off and the job trail is the only surface.
 * Retyping is part of wiring the reserved `error` port (plans/current.md), which
 * needs workflow-level authoring and verification — not a side effect of making
 * the message readable.
 */
final class AiProviderFailure extends \Exception {

  /**
   * Constructs an AiProviderFailure.
   *
   * @param string $message
   *   What to tell the reader — already classified, never the upstream's text.
   * @param int $code
   *   Unused; kept for \Exception's signature.
   * @param \Throwable|null $previous
   *   The provider's own exception, always.
   * @param string $kind
   *   Which kind of failure this is, as one of
   *   {@see \Drupal\aincient_core\Inference\ProviderCall}'s `KIND_*` constants.
   *   A rejected credential and an overloaded provider need opposite responses
   *   from the reader; this is the machine-readable half of telling them apart
   *   (atelier-cms#8). Defaults to `unknown` for the call sites that raise this
   *   without going through {@see \Drupal\aincient_core\Inference\ProviderCall}.
   * @param string $provider
   *   The provider whose call this failure belongs to, or '' when the raising
   *   site did not name one. Part of the structured account a downstream branch
   *   reads off {@see self::toDetail()} instead of re-deriving.
   * @param string $model
   *   The model being called, or '' when unnamed. See `$provider`.
   * @param bool $retryable
   *   Whether the underlying fault is transient in nature — a rate limit, a 5xx
   *   or a transport blip that a later attempt might clear — as opposed to a
   *   hard fault (a rejected key, a missing model, a malformed tool call) where
   *   retrying only spends time. The machine-readable half of "try again" versus
   *   "fix something first"; distinct from whether the READER may re-send a turn
   *   ({@see \Drupal\aincient_chat\Chat\ProviderFailureSurface}), which also
   *   weighs whether the turn already took effect.
   */
  public function __construct(
    string $message,
    int $code = 0,
    ?\Throwable $previous = NULL,
    private readonly string $kind = ProviderCall::KIND_UNKNOWN,
    private readonly string $provider = '',
    private readonly string $model = '',
    private readonly bool $retryable = FALSE,
  ) {
    parent::__construct($message, $code, $previous);
  }

  /**
   * Which kind of failure this is.
   *
   * @return string
   *   One of {@see \Drupal\aincient_core\Inference\ProviderCall}'s `KIND_*`.
   */
  public function getKind(): string {
    return $this->kind;
  }

  /**
   * The structured account of this failure, for a downstream branch to read.
   *
   * The shape our own reason node emits on its `error_detail` output port and
   * the shape the console card is driven from — `kind` selects the action,
   * `provider`/`model` name what failed, `message` is the already-classified
   * sentence (never the upstream's raw text), and `retryable` says whether a
   * later attempt might clear it. Assembled here, in one place, so both the
   * in-graph consumer and the request-scoped {@see ProviderFailureLog} read an
   * identical struct rather than each re-deriving fields from the message.
   *
   * @return array{kind: string, provider: string, model: string, message: string, retryable: bool}
   *   The structured failure.
   */
  public function toDetail(): array {
    return [
      'kind' => $this->kind,
      'provider' => $this->provider,
      'model' => $this->model,
      'message' => $this->getMessage(),
      'retryable' => $this->retryable,
    ];
  }

}
