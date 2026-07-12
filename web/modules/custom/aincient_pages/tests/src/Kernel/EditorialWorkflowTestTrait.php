<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Kernel;

use Drupal\workflows\Entity\Workflow;

/**
 * Builds the `aincient_editorial` content-moderation workflow in a kernel test.
 *
 * Mirrors the shipped `workflows.workflow.aincient_editorial.yml` config so the
 * stores' moderation behaviour is exercised against the real states/transitions
 * (Draft · Needs review · Published · Archived) rather than core's defaults.
 * Callers must already have enabled `workflows` + `content_moderation` and
 * installed the `aincient_page` / `aincient_block` node types they pass in.
 */
trait EditorialWorkflowTestTrait {

  /**
   * Create the editorial workflow and bind it to the given bundles.
   *
   * @param string[] $bundles
   *   The NODE bundle ids to moderate (e.g. ['aincient_page']).
   * @param string[] $mediaBundles
   *   The MEDIA bundle ids to moderate (e.g. ['block'] — a global block is a
   *   media entity, DECISIONS 0138).
   */
  protected function setUpEditorialWorkflow(array $bundles, array $mediaBundles = []): void {
    $workflow = Workflow::create([
      'id' => 'aincient_editorial',
      'label' => 'AIncient editorial',
      'type' => 'content_moderation',
    ]);
    $type = $workflow->getTypePlugin();
    $states = [
      'draft' => ['Draft', 0, FALSE, FALSE],
      'needs_review' => ['Needs review', 1, FALSE, FALSE],
      'published' => ['Published', 2, TRUE, TRUE],
      'archived' => ['Archived', 3, FALSE, TRUE],
    ];
    foreach ($states as $id => [$label]) {
      if (!$type->hasState($id)) {
        $type->addState($id, $label);
      }
    }
    $conf = $type->getConfiguration();
    foreach ($states as $id => [$label, $weight, $published, $defaultRevision]) {
      $conf['states'][$id] = [
        'label' => $label,
        'weight' => $weight,
        'published' => $published,
        'default_revision' => $defaultRevision,
      ];
    }
    $type->setConfiguration($conf);
    foreach (array_keys($type->getTransitions()) as $tid) {
      $type->deleteTransition($tid);
    }
    $transitions = [
      'create_new_draft' => ['Create new draft', ['draft', 'published'], 'draft'],
      'submit_for_review' => ['Submit for review', ['draft', 'needs_review'], 'needs_review'],
      'approve' => ['Approve', ['needs_review'], 'published'],
      'reject' => ['Reject', ['needs_review'], 'draft'],
      'publish' => ['Publish', ['draft'], 'published'],
      'archive' => ['Archive', ['published'], 'archived'],
      'restore' => ['Restore', ['archived'], 'draft'],
    ];
    foreach ($transitions as $id => [$label, $from, $to]) {
      $type->addTransition($id, $label, $from, $to);
    }
    foreach ($bundles as $bundle) {
      $type->addEntityTypeAndBundle('node', $bundle);
    }
    foreach ($mediaBundles as $bundle) {
      $type->addEntityTypeAndBundle('media', $bundle);
    }
    $workflow->save();
  }

}
