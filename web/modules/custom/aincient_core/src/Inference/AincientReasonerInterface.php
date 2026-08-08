<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Inference;

use Drupal\flowdrop\DTO\Reason\ReasonRequest;
use Drupal\flowdrop\Service\Reasoning\ChatReasonerInterface;

/**
 * A reasoner that can also return Atelier's richer result contract.
 *
 * Extends FlowDrop's `@api` {@see ChatReasonerInterface} so the same service
 * still binds to `flowdrop.chat_reasoner` and serves the engine's own reason
 * node unchanged. The one addition — {@see self::reasonRich()} — hands Atelier's
 * own reason node an {@see AincientReasonResult} instead of the four-field
 * engine DTO, so the raw wire body, the detected codec, and a structured
 * failure survive the reasoner→node boundary that {@see
 * \Drupal\flowdrop\DTO\Reason\ReasonResult} cannot widen (ADR 0365).
 *
 * `reason()` stays the narrow contract: it returns the same result `reasonRich`
 * does, projected down to `ReasonResult`, so nothing that speaks only the engine
 * interface is affected.
 */
interface AincientReasonerInterface extends ChatReasonerInterface {

  /**
   * Runs one reasoning step, returning the full account of the response.
   *
   * @param \Drupal\flowdrop\DTO\Reason\ReasonRequest $request
   *   The conversation, tools and sampling controls to reason over.
   *
   * @return \Drupal\aincient_core\Inference\AincientReasonResult
   *   Text and tool calls, plus the raw wire body and detected codec.
   *
   * @throws \Drupal\aincient_core\Exception\AiProviderFailure
   *   When the provider could not serve the call, retries included.
   */
  public function reasonRich(ReasonRequest $request): AincientReasonResult;

}
