<?php

declare(strict_types=1);

namespace Drupal\aincient_chat\Chat;

use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * The pipelines one console turn ran in: its root and every sub-workflow run.
 *
 * A studio places the shared agent engine (DECISIONS 0463), which runs as its
 * own pipeline under the studio's (FlowDrop stamps `parent_pipeline_id` and
 * `root_pipeline_id`). So a turn is a TREE of pipelines: the studio's own run
 * holds the chat_output, the engine's run holds the tool jobs (and their widget
 * envelopes), the scratchpad, and any interrupt raised inside the loop. Every
 * reader of "what this turn did" has to look at the whole tree, or it sees only
 * the root and drops the work done below it.
 *
 * Tolerant by contract: a site without FlowDrop's pipeline entity, or an id
 * that no longer loads, answers with the id it was given.
 */
final class TurnPipelines {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * The root of a (possibly nested) pipeline; itself when top-level.
   */
  public function root(?string $pipelineId): ?string {
    if ($pipelineId === NULL || $pipelineId === '' || !$this->available()) {
      return $pipelineId;
    }
    $pipeline = $this->entityTypeManager->getStorage('flowdrop_pipeline')->load($pipelineId);
    if ($pipeline === NULL || !$pipeline->hasField('root_pipeline_id')) {
      return $pipelineId;
    }
    $root = $pipeline->get('root_pipeline_id')->getString();
    return $root !== '' ? $root : $pipelineId;
  }

  /**
   * A pipeline id followed by every pipeline below it, oldest first.
   *
   * @return list<string>
   *   The tree's pipeline ids; [$pipelineId] when nothing runs below it.
   */
  public function withDescendants(string $pipelineId): array {
    if ($pipelineId === '' || !$this->available()) {
      return $pipelineId === '' ? [] : [$pipelineId];
    }
    $ids = $this->entityTypeManager->getStorage('flowdrop_pipeline')->getQuery()
      ->accessCheck(FALSE)
      ->condition('root_pipeline_id', $pipelineId)
      ->condition('id', $pipelineId, '<>')
      ->sort('id')
      ->execute();
    return array_merge([$pipelineId], array_map('strval', array_values($ids)));
  }

  /**
   * The console session a pipeline ran on, or NULL.
   */
  public function sessionId(?string $pipelineId): ?int {
    if ($pipelineId === NULL || $pipelineId === '' || !$this->available()) {
      return NULL;
    }
    $pipeline = $this->entityTypeManager->getStorage('flowdrop_pipeline')->load($pipelineId);
    $id = $pipeline !== NULL && method_exists($pipeline, 'getSessionId') ? $pipeline->getSessionId() : NULL;
    return $id !== NULL && $id !== '' ? (int) $id : NULL;
  }

  /**
   * Whether FlowDrop's pipeline entity exists on this site.
   */
  private function available(): bool {
    return $this->entityTypeManager->hasDefinition('flowdrop_pipeline');
  }

}
