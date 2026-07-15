<?php

declare(strict_types=1);

namespace Drupal\aincient_pages\Controller;

use Drupal\aincient_pages\ComponentCatalog;
use Drupal\aincient_pages\EditLock;
use Drupal\aincient_pages\Exception\RevisionConflictException;
use Drupal\aincient_pages\NodeModeration;
use Drupal\aincient_pages\PageStore;
use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * JSON/HTML API for the page studio (consumed by the chat console).
 *
 * The page parallel of {@see BrandController}: the studio edits a preview-only
 * page-schema draft client-side, renders it live through {@see preview()}, and
 * persists it only when the user clicks Publish ({@see save()}). The page AGENT
 * never writes — it proposes ops the studio previews; this Publish is the only
 * path to a live page (mirroring the studio-only brand convention).
 */
final class PageController implements ContainerInjectionInterface {

  public function __construct(
    private readonly PageStore $store,
    private readonly ClassResolverInterface $classResolver,
    private readonly NodeModeration $moderation,
    private readonly EditLock $lock,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('aincient_pages.store'),
      $container->get('class_resolver'),
      $container->get('aincient_pages.moderation'),
      $container->get('aincient_pages.edit_lock'),
    );
  }

  /**
   * POST /atelier/page/apply — apply the agent's ops to the working draft.
   *
   * Body: `{ "schema": {…current draft…}, "ops": [ {op:…}, … ] }`. Runs the ops
   * through {@see PageStore::applyOps} (the single source of ops + clamp logic)
   * and returns `{ schema, rejected }`. The page_preview widget calls this when
   * the agent emits ops — the browser holds the authoritative draft (a capability
   * tool can't read turn state), so the apply happens here, not in the tool, and
   * the result feeds back into the studio draft. Stateless: nothing is stored.
   */
  public function apply(Request $request): JsonResponse {
    $data = json_decode((string) $request->getContent(), TRUE);
    if (!is_array($data) || !is_array($data['ops'] ?? NULL)) {
      return new JsonResponse(['error' => 'Expected { "schema": {…}, "ops": [ … ] }.'], 400);
    }
    $schema = is_array($data['schema'] ?? NULL) ? $data['schema'] : [];
    $result = $this->store->applyOps($schema, $data['ops']);
    return new JsonResponse([
      'schema' => $result['schema'],
      'rejected' => $result['rejected'],
    ]);
  }

  /**
   * POST /atelier/page/preview — render a draft page-schema, WITHOUT persisting.
   *
   * Body: `{ "schema": { …page-schema… } }`. The schema is clamped through the
   * same guardrail Publish uses, then rendered to the chrome-less HTML the
   * studio iframe shows (via `srcdoc`). Stateless: nothing is stored.
   */
  public function preview(Request $request): Response {
    $data = json_decode((string) $request->getContent(), TRUE);
    $schema = is_array($data) && is_array($data['schema'] ?? NULL) ? $data['schema'] : [];
    $clean = $this->store->validate($schema);
    return $this->spike()->renderSchema($clean);
  }

  /**
   * POST /atelier/page/save — SAVE the studio's working schema as a DRAFT.
   *
   * Semantics changed with the editorial workflow: this no longer publishes. It
   * persists the schema as a forward (pending) draft revision — the live page is
   * untouched. With a `node_id` it updates that page; otherwise a new draft page
   * is created. A `langcode` targets a translation (auto-created, symmetric
   * inherits the source layout). Body: `{ schema, node_id?, langcode?, base_vid? }`.
   * Going live is the separate, explicit {@see publish} click.
   */
  public function save(Request $request): JsonResponse {
    $data = $this->body($request);
    if (!is_array($data['schema'] ?? NULL)) {
      return new JsonResponse(['error' => 'Expected { "schema": { … } }.'], 400);
    }
    [$nodeId, $langcode, $baseVid] = $this->writeArgs($data);

    // A schema write requires update access on the EXACT head revision being
    // edited (read-only states / another author's draft 403 here). A new page
    // (no node yet) skips the per-node check — creating a draft is the create path.
    if ($nodeId !== NULL && ($denied = $this->denyIfNoUpdate($nodeId, $langcode)) !== NULL) {
      return $denied;
    }
    // Single-writer fence: an existing page may only be written by the session
    // that holds its editor lock (a new page has no lock yet — it's created here,
    // then the console acquires). A missing/stale token means another tab/user
    // took over → 409 lock_conflict, distinct from the base_vid 409 below.
    if ($nodeId !== NULL && ($locked = $this->denyIfNotLockHolder($nodeId, $langcode, $data)) !== NULL) {
      return $locked;
    }
    try {
      $result = $this->store->saveDraft($data['schema'], $nodeId, $langcode, $baseVid, $this->coauthorsFrom($data));
    }
    catch (RevisionConflictException $e) {
      return $this->conflict($e);
    }
    return $result === NULL
      ? new JsonResponse(['error' => 'That page does not exist, or the language is not configured.'], 404)
      : new JsonResponse($result);
  }

  /**
   * POST /atelier/page/publish — write the latest schema then PUBLISH (go live).
   *
   * The one-click save+go-live: persists the schema (if sent) and makes that
   * revision the published default. Requires update access; the publish/approve
   * transition the user holds is validated in the store. Body:
   * `{ schema?, node_id, langcode?, base_vid? }`.
   */
  public function publish(Request $request): JsonResponse {
    $data = $this->body($request);
    [$nodeId, $langcode, $baseVid] = $this->writeArgs($data);
    if ($nodeId === NULL) {
      return new JsonResponse(['error' => 'Publish needs a node_id (save a draft first).'], 400);
    }
    if (($denied = $this->denyIfNoUpdate($nodeId, $langcode)) !== NULL) {
      return $denied;
    }
    // Publish writes the draft live — same single-writer fence as save.
    if (($locked = $this->denyIfNotLockHolder($nodeId, $langcode, $data)) !== NULL) {
      return $locked;
    }
    $schema = is_array($data['schema'] ?? NULL) ? $data['schema'] : NULL;
    try {
      $result = $this->store->publish($nodeId, $schema, $langcode, $baseVid, $this->coauthorsFrom($data));
    }
    catch (RevisionConflictException $e) {
      return $this->conflict($e);
    }
    // NULL here means the publish/approve transition isn't legal for this user.
    return $result === NULL
      ? new JsonResponse(['error' => "You don’t have permission to publish this page."], 403)
      : new JsonResponse($result);
  }

  /**
   * POST /atelier/page/submit-review — move a draft to Needs review.
   */
  public function submitReview(Request $request): JsonResponse {
    return $this->transition($request, 'submit_for_review', requireUpdate: TRUE);
  }

  /**
   * POST /atelier/page/approve — Needs review → Published (reviewer-gated).
   * A reviewer needs the approve transition, NOT update access (they review,
   * they don't edit), so this does not gate on update.
   */
  public function approve(Request $request): JsonResponse {
    return $this->transition($request, 'approve', requireUpdate: FALSE);
  }

  /**
   * POST /atelier/page/reject — Needs review → Draft (reviewer sends it back).
   */
  public function reject(Request $request): JsonResponse {
    return $this->transition($request, 'reject', requireUpdate: FALSE);
  }

  /**
   * POST /atelier/page/archive — Published → Archived (take the page down).
   */
  public function archive(Request $request): JsonResponse {
    return $this->transition($request, 'archive', requireUpdate: FALSE);
  }

  /**
   * POST /atelier/page/restore — Archived → Draft (bring it back to edit).
   */
  public function restore(Request $request): JsonResponse {
    return $this->transition($request, 'restore', requireUpdate: FALSE);
  }

  /**
   * Shared handler for a pure editorial transition: validate the node + (when the
   * transition writes content, i.e. never here) update access, run it pinned to
   * `base_vid`, and return the new state envelope. A NULL store result means the
   * transition isn't legal for the current user → 403.
   */
  private function transition(Request $request, string $transitionId, bool $requireUpdate): JsonResponse {
    $data = $this->body($request);
    $nodeId = isset($data['node_id']) && $data['node_id'] !== '' ? (string) $data['node_id'] : NULL;
    if ($nodeId === NULL) {
      return new JsonResponse(['error' => 'Expected a node_id.'], 400);
    }
    $baseVid = isset($data['base_vid']) && is_numeric($data['base_vid']) ? (int) $data['base_vid'] : NULL;
    if ($requireUpdate && ($denied = $this->denyIfNoUpdate($nodeId, NULL)) !== NULL) {
      return $denied;
    }
    try {
      $result = $this->store->transition($nodeId, $transitionId, $baseVid, $this->coauthorsFrom($data));
    }
    catch (RevisionConflictException $e) {
      return $this->conflict($e);
    }
    return $result === NULL
      ? new JsonResponse(['error' => "That change isn’t available — the page may have moved on, or you don’t hold this transition."], 403)
      : new JsonResponse($result);
  }

  /**
   * Parse a JSON request body to an array (empty array if absent/malformed).
   */
  private function body(Request $request): array {
    $data = json_decode((string) $request->getContent(), TRUE);
    return is_array($data) ? $data : [];
  }

  /**
   * Extract the common write args (node_id, langcode, base_vid) from a body.
   *
   * @return array{0: ?string, 1: ?string, 2: ?int}
   */
  private function writeArgs(array $data): array {
    return [
      isset($data['node_id']) && $data['node_id'] !== '' ? (string) $data['node_id'] : NULL,
      isset($data['langcode']) && $data['langcode'] !== '' ? (string) $data['langcode'] : NULL,
      isset($data['base_vid']) && is_numeric($data['base_vid']) ? (int) $data['base_vid'] : NULL,
    ];
  }

  /**
   * Build the revision co-author records from a write request — the non-human
   * contributors (Studio + Agent) the console reports for this save, stamped
   * alongside the human revision author (see {@see PageStore::stampCoauthors}).
   * The console sends `studio`, and (when an agent produced the change) `agent`
   * + the `thread` it happened in. Returns NULL when neither is present (a plain
   * human edit carries no co-authors).
   *
   * @return array<int, array{actor: string, id: string, thread?: string}>|null
   */
  private function coauthorsFrom(array $data): ?array {
    $out = [];
    if (isset($data['studio']) && $data['studio'] !== '') {
      $out[] = ['actor' => 'studio', 'id' => (string) $data['studio']];
    }
    if (isset($data['agent']) && $data['agent'] !== '') {
      $agent = ['actor' => 'agent', 'id' => (string) $data['agent']];
      if (isset($data['thread']) && $data['thread'] !== '') {
        $agent['thread'] = (string) $data['thread'];
      }
      $out[] = $agent;
    }
    return $out === [] ? NULL : $out;
  }

  /**
   * 403 (or 404) JsonResponse if the current user can't UPDATE the head revision
   * of $nodeId — the security floor for any schema write, checked on the EXACT
   * revision the studio edits (a forward draft can differ from the published
   * default). Returns NULL when the write may proceed.
   */
  private function denyIfNoUpdate(string $nodeId, ?string $langcode): ?JsonResponse {
    $head = $this->moderation->loadLatestRevision($nodeId, 'aincient_page', $langcode);
    if ($head === NULL) {
      return new JsonResponse(['error' => 'That page does not exist.'], 404);
    }
    if (!$head->access('update')) {
      return new JsonResponse(['error' => "You don’t have access to edit this page."], 403);
    }
    return NULL;
  }

  /**
   * 409 lock_conflict if the request doesn't hold the page's editor lock — the
   * single-writer fence. The token rides the request body (`token`); a missing
   * or stale one means another session took the pen. Carries the current holder
   * so the console can say who, and offer take-over. NULL when the write may
   * proceed. Keyed on the SAME (nid, langcode) partition the write targets
   * (langcode '' = the source translation).
   */
  private function denyIfNotLockHolder(string $nodeId, ?string $langcode, array $data): ?JsonResponse {
    $token = isset($data['token']) && $data['token'] !== '' ? (string) $data['token'] : NULL;
    if ($this->lock->verify((int) $nodeId, $langcode ?? '', $token)) {
      return NULL;
    }
    return new JsonResponse([
      'error' => 'Another session is editing this page — acquire the lock to make changes.',
      'lock_conflict' => TRUE,
      'holder' => $this->lock->status((int) $nodeId, $langcode ?? ''),
    ], 409);
  }

  /**
   * 409 Conflict for a stale write — the page advanced since the studio loaded
   * it. Carries the current head vid so the studio can offer "Reload latest".
   */
  private function conflict(RevisionConflictException $e): JsonResponse {
    return new JsonResponse([
      'error' => 'This page changed since you opened it — saving would overwrite newer work.',
      'conflict' => TRUE,
      'base_vid' => $e->expectedVid,
      'current_vid' => $e->currentVid,
    ], 409);
  }

  /**
   * GET /atelier/page/manifest — the component catalog for the studio editor.
   *
   * The single source the studio's structured section editor renders from: the
   * placeable sections, each prop (with its enum values where it's enumerated or
   * a repeatable shape), plus the shared tone/variant enums and the
   * locked prop vocabulary. Sourced from {@see ComponentCatalog} so the editor,
   * the validator and the agent prompt can never drift.
   */
  public function manifest(): JsonResponse {
    return new JsonResponse([
      'sections' => array_map([$this, 'manifestEntry'], array_keys(ComponentCatalog::SECTIONS), ComponentCatalog::SECTIONS),
      'layout' => array_map([$this, 'manifestEntry'], array_keys(ComponentCatalog::LAYOUT), ComponentCatalog::LAYOUT),
      // Reference placeables (embed / block) — surfaced so the studio can offer +
      // edit them; their `entity`/`ref` props carry a picker flag (see below).
      'reference' => array_map([$this, 'manifestEntry'], array_keys(ComponentCatalog::REFERENCE), ComponentCatalog::REFERENCE),
      'tones' => ComponentCatalog::TONES,
      'hero_variants' => ComponentCatalog::HERO_VARIANTS,
      'variants' => ComponentCatalog::VARIANTS,
      'prop_vocab' => ComponentCatalog::PROP_VOCAB,
      // The image-bearing prop / row-field names, so the studio renders a media
      // picker for them (top-level props are also flagged per-prop below).
      'image_props' => ComponentCatalog::IMAGE_PROPS,
      // The bounded child allow-list for accordion panels — the components the
      // studio's panels editor offers in its per-block component picker. Their
      // prop schemas are read from `sections` (the children are leaf sections).
      'accordion_blocks' => ComponentCatalog::ACCORDION_BLOCKS,
      // The studio's translation bootstrap (languages + governance) for the
      // language switcher + inherit/diverge affordance.
      'translation' => $this->store->translationContext(),
    ]);
  }

  /**
   * Build one studio-editor manifest entry (component + use + described props)
   * for a placeable def — shared by the section and layout tiers.
   */
  private function manifestEntry(string $name, array $def): array {
    $props = [];
    foreach ($def['props'] as $prop => $hint) {
      $entry = ['name' => $prop, 'meaning' => ComponentCatalog::PROP_VOCAB[$prop] ?? ''];
      if (ComponentCatalog::isImageProp($prop)) {
        // Render a media picker for this prop (it holds a media:<id> token).
        $entry['image'] = TRUE;
      }
      if ($prop === 'entity') {
        // Render an embed picker (it holds an entity:<…> reference token).
        $entry['embed'] = TRUE;
      }
      if ($prop === 'ref') {
        // Render a global-block picker (it holds an aincient_block node id).
        $entry['block'] = TRUE;
      }
      if (ComponentCatalog::isMultilineProp($prop)) {
        // Render a textarea (long-form Markdown source), not a single-line input.
        $entry['multiline'] = TRUE;
      }
      if (ComponentCatalog::isBooleanProp($prop)) {
        // Render a checkbox (a typed boolean), not a single-line text input.
        $entry['boolean'] = TRUE;
      }
      if ($prop === 'tone') {
        $entry['enum'] = ComponentCatalog::TONES;
      }
      elseif (ComponentCatalog::isPanelProp($prop)) {
        // A nested panels list (accordion) — two levels deep, so the flat rows
        // editor can't model it. Flag it for the studio's dedicated panels
        // editor and do NOT also set `shape` (they're mutually exclusive).
        $entry['panels'] = TRUE;
      }
      elseif (str_contains($hint, '|')) {
        $entry['enum'] = explode('|', $hint);
      }
      elseif (str_starts_with($hint, '[')) {
        // A repeatable row shape, e.g. "[{value,label}]".
        $entry['shape'] = $hint;
      }
      $props[] = $entry;
    }
    return ['component' => $name, 'use' => $def['use'], 'props' => $props];
  }

  /**
   * GET /atelier/page/list — existing pages for the studio's page picker /
   * content browser.
   *
   * Two shapes from one route. With no query params it returns the legacy
   * lightweight feed ({ id, title, changed, url } per page, newest first, capped)
   * the dropdown "Open…" picker reads. With `offset`/`limit`/`q` it pages the
   * full set for the content browser — same `pages` key (so the dropdown stays
   * backward-compatible), plus `total`/`offset`/`limit` for the pager and a
   * moderation badge per item. The schema itself is fetched on pick via
   * {@see pageSchema}.
   */
  public function list(Request $request): JsonResponse {
    $q = $request->query;
    // No paging params → the legacy quick-picker feed (capped, no total).
    if (!$q->has('offset') && !$q->has('limit') && !$q->has('q')) {
      return new JsonResponse(['pages' => $this->store->list()]);
    }
    $dir = $this->store->directory(
      (int) $q->get('offset', 0),
      (int) $q->get('limit', 12),
      $q->get('q'),
      $q->get('state'),
    );
    return new JsonResponse([
      'pages' => $dir['items'],
      'total' => $dir['total'],
      'offset' => $dir['offset'],
      'limit' => $dir['limit'],
    ]);
  }

  /**
   * GET /atelier/page/{node}/schema — the stored schema for an existing page.
   *
   * The "edit this page" entry point: the studio seeds its initial draft from
   * here, then drives the agent/preview loop against it. An optional `?langcode=`
   * resolves a specific translation (the merged, inheritance-resolved schema for
   * that language) so the studio can edit a translation's copy.
   */
  public function pageSchema(NodeInterface $node, Request $request): JsonResponse {
    if ($node->bundle() !== 'aincient_page') {
      return new JsonResponse(['error' => 'Not an AIncient page.'], 404);
    }
    $langcode = $request->query->get('langcode') ?: NULL;
    $id = (string) $node->id();
    // The studio edits the LATEST revision (a forward draft may be ahead of the
    // published default), so the per-node gate runs on THAT revision, not the
    // default. VIEW is the floor to open the studio at all — no view → the
    // access-denied end-state pane (deep links can outlive accessibility). View
    // WITHOUT update isn't a 403: it opens read-only (can_edit:false below).
    $head = $this->moderation->loadLatestRevision($id, 'aincient_page', $langcode);
    if ($head === NULL) {
      return new JsonResponse(['error' => 'That page has no schema.'], 404);
    }
    if (!$head->access('view')) {
      return new JsonResponse(['error' => "You don’t have access to view this page."], 403);
    }
    $data = $this->store->loadLatest($id, $langcode);
    if ($data === NULL) {
      return new JsonResponse(['error' => 'That page has no schema.'], 404);
    }
    // Page-specific translation governance, alongside the moderation envelope
    // (moderation_state, state_label, has_pending_draft, can_edit, transitions,
    // base_vid) loadLatest already returns.
    $data['layout_mode'] = $langcode !== NULL ? $this->store->layoutMode($id, $langcode) : NULL;
    $data['translations'] = $this->store->translationsOf($id);
    return new JsonResponse($data);
  }

  /**
   * POST /atelier/page/{node}/diverge — flip a translation to asymmetric layout.
   *
   * Body: `{ "langcode": "<lc>" }`. Copy-on-write: snapshots the source skeleton
   * into the translation and detaches it from source layout edits. Permission-
   * gated (`diverge aincient page layout`) and governed by the
   * `translation.allow_divergence` setting. Returns the new mode.
   */
  public function diverge(NodeInterface $node, Request $request): JsonResponse {
    return $this->modeTransition($node, $request, TRUE);
  }

  /**
   * POST /atelier/page/{node}/converge — re-inherit the source layout.
   *
   * Body: `{ "langcode": "<lc>" }`. Discards the translation's own structure and
   * flips it back to symmetric (keeps localised content). Always permitted.
   */
  public function converge(NodeInterface $node, Request $request): JsonResponse {
    return $this->modeTransition($node, $request, FALSE);
  }

  /**
   * Shared diverge/converge handler: validate the bundle + langcode, run the
   * transition, and report the resulting mode (or the reason it was refused).
   */
  private function modeTransition(NodeInterface $node, Request $request, bool $diverge): JsonResponse {
    if ($node->bundle() !== 'aincient_page') {
      return new JsonResponse(['error' => 'Not an AIncient page.'], 404);
    }
    $data = json_decode((string) $request->getContent(), TRUE);
    $langcode = is_array($data) && isset($data['langcode']) ? (string) $data['langcode'] : '';
    if ($langcode === '') {
      return new JsonResponse(['error' => 'Expected { "langcode": "<lc>" }.'], 400);
    }
    $id = (string) $node->id();
    $ok = $diverge ? $this->store->diverge($id, $langcode) : $this->store->converge($id, $langcode);
    if (!$ok) {
      return new JsonResponse([
        'error' => $diverge
          ? 'Cannot diverge that translation (unknown language, the source language, or divergence is disabled).'
          : 'Cannot converge that translation (unknown language or the source language).',
      ], 409);
    }
    return new JsonResponse([
      'node_id' => $id,
      'langcode' => $langcode,
      'layout_mode' => $this->store->layoutMode($id, $langcode),
    ]);
  }

  /**
   * The page renderer, resolved lazily (it's a controller, not a service).
   */
  private function spike(): PageSpikeController {
    return $this->classResolver->getInstanceFromDefinition(PageSpikeController::class);
  }

}
