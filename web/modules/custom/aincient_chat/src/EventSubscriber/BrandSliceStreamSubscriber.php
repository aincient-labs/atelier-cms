<?php

declare(strict_types=1);

namespace Drupal\aincient_chat\EventSubscriber;

use Drupal\aincient_chat\Chat\StreamRelay;
use Drupal\aincient_chat\Event\ChatEvent;
use Drupal\flowdrop_runtime\Event\JobCompletedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Streams each Brand specialist's slice to the live preview as it completes.
 *
 * The user-facing half of the deterministic-merge redesign. The orchestrator no
 * longer applies brand changes mid-turn (the merge node does it ONCE at
 * end-of-turn — {@see \Drupal\aincient_flows\Plugin\FlowDropNodeProcessor\BrandApplySlices}),
 * so the studio preview would otherwise sit frozen for the whole ~2-minute turn.
 * This gives the *user* the live feedback the *agent* deliberately lost: as each
 * specialist sub-workflow completes mid-loop, its returned slice is applied to a
 * transient `brand_preview` frame pushed straight onto the open SSE stream, so
 * colour then shape animate into the preview while the agent is still working.
 *
 * Why this is safe with the merge node: the channel is ONE-WAY, server→client.
 * The agent never observes these frames (they ride the SSE response, not the
 * conversation buffer), so they cannot re-trigger the retry loop the merge node
 * exists to kill. The end-of-turn merged frame is authoritative and persisted;
 * these live frames are transient (not harvested, not stored) — on reload the
 * harvested merged card reconstructs the final state.
 *
 * Mirrors {@see NodeProgressSubscriber}: subscribes the event by LITERAL string
 * so registering never autoloads a FlowDrop class (the module stays installable
 * without FlowDrop; the event simply never fires), and the cross-module
 * BrandPreviewApplier is resolved LAZILY (aincient_chat does not depend on
 * aincient_pages — same posture as FlowDropDispatcher's FlowDrop services).
 *
 * Note: typography fonts apply on Publish, so a font change won't visibly move
 * the live preview — that's inherent, not a streaming bug; colour + shape do.
 */
final class BrandSliceStreamSubscriber implements EventSubscriberInterface {

  /**
   * The shared applier service id (resolved lazily; see the class docblock).
   */
  private const APPLIER_SERVICE = 'aincient_pages.preview_applier';

  public function __construct(
    private readonly StreamRelay $relay,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return ['flowdrop_runtime.job.completed' => 'onJobCompleted'];
  }

  /**
   * Apply one completed specialist's slice as a live preview frame.
   */
  public function onJobCompleted(JobCompletedEvent $event): void {
    // Only while a streamed console turn is listening — otherwise emit() would
    // no-op anyway, so skip the slice decode + apply entirely.
    if (!$this->relay->isArmed()) {
      return;
    }

    $job = $event->getJob();
    // Gate to brand specialist sub-workflow executors. Cheap string check on
    // the node_type_id — no FlowDrop autoload, no work for any other node
    // (reason, gateways, the merge node, other agents).
    $nodeTypeId = (string) ($job->getMetadata()['node_type_id'] ?? '');
    if (!str_contains($nodeTypeId, 'brand_specialist') || $job->getStatus() !== 'completed') {
      return;
    }

    if (!\Drupal::hasService(self::APPLIER_SERVICE)) {
      return;
    }
    $applier = \Drupal::service(self::APPLIER_SERVICE);

    // The executor wraps the sub-workflow's slice as {slice: "<fenced json>"}.
    $slice = $applier->decodeSlice((string) ($job->getOutputData()['slice'] ?? ''));
    if ($slice === NULL) {
      return;
    }

    // Re-encode the decoded slice into the string args apply() consumes (the
    // same shape the merge node feeds it), then apply this ONE slice.
    $args = [];
    if (is_array($slice['tokens_json'] ?? NULL)) {
      $args['tokens_json'] = (string) json_encode($slice['tokens_json']);
    }
    if (is_array($slice['presets_json'] ?? NULL)) {
      $args['presets_json'] = (string) json_encode($slice['presets_json']);
    }
    if (isset($slice['fonts'])) {
      $args['fonts'] = is_array($slice['fonts']) ? implode(', ', $slice['fonts']) : (string) $slice['fonts'];
    }
    if ($args === []) {
      return;
    }

    $envelope = $applier->apply($args);
    if (isset($envelope['error']) || !isset($envelope['__widget__'])) {
      return;
    }

    // A transient widget frame on the open stream. The frontend mints a fresh
    // toolCallId per tool_call frame, so this live frame and the end-of-turn
    // merged frame both apply (layered idempotently per cssVar).
    $this->relay->emit(ChatEvent::toolCall(
      (string) $envelope['__widget__'],
      $envelope['payload'],
    ));
  }

}
