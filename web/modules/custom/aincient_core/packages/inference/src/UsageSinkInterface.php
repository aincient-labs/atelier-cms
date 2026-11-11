<?php

declare(strict_types=1);

namespace Aincient\Inference;

/**
 * Where an inference seam reports what a completed call used.
 *
 * In Drupal {@see \Drupal\aincient_core\Usage\UsageRecorder} prices the call and
 * writes the metering row. An implementation must never throw: a broken
 * bookkeeper must not stop the work.
 */
interface UsageSinkInterface {

  /**
   * The operation value a chat turn records — vision turns included.
   *
   * Kept as the literal `chat` the drupal/ai era used, even though the new table
   * starts empty and nothing forces the continuity: an export taken before the
   * cutover and one taken after still line up on this axis, which is the only
   * way the two can be compared at all. A vision call is a chat call with an
   * image part on this backend, so it is not a third value.
   */
  public const OPERATION_CHAT = 'chat';

  /**
   * Generating an image from a prompt.
   */
  public const OPERATION_TEXT_TO_IMAGE = 'text_to_image';

  /**
   * Editing an image the caller supplied.
   */
  public const OPERATION_IMAGE_TO_IMAGE = 'image_to_image';

  /**
   * The call-site tag for a turn of the agent loop.
   *
   * The reasoner serves every FlowDrop `reason` node — the operator and its
   * sub-agents — so this identifies "an agent turn", which is exactly the
   * distinction the dashboard was missing against thread naming. The gateway's
   * tags are NOT defined here: they are passed in by their callers
   * (`aincient_chat_thread_namer`, `aincient_media_studio`) and the recorder
   * preserves them verbatim so rows keep lining up if those call sites move.
   */
  public const TAG_AGENT_TURN = 'aincient_agent_turn';

  /**
   * The call-site tag for the plain chat node the brand specialists run on.
   */
  public const TAG_SIMPLE_CHAT = 'aincient_flows_simple_chat';

  /**
   * Records one completed inference call. Never throws.
   *
   * @param string $providerId
   *   The provider that served the call.
   * @param string $modelId
   *   The model that served the call, as resolved (never as requested).
   * @param object $result
   *   The result the platform yielded, for its token metadata.
   * @param string $tag
   *   The call-site tag ({@see self::TAG_AGENT_TURN}).
   * @param string $operation
   *   One of the OPERATION_* values.
   */
  public function record(
    string $providerId,
    string $modelId,
    object $result,
    string $tag,
    string $operation = self::OPERATION_CHAT,
  ): void;

}
