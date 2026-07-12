<?php

declare(strict_types=1);

namespace Drupal\aincient_pages;

use Drupal\Component\Utility\Xss;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\node\NodeInterface;

/**
 * Validates, persists, and resolves agent-generated page-schemas.
 *
 * This is the GUARDRAIL: whatever the model emits, validate() clamps it to the
 * grammar (allow-listed components + enumerated variants/tones), so a malformed
 * or hallucinated schema can never render something broken. The LLM proposes;
 * PageStore disposes.
 *
 * Pages are persisted as `aincient_page` NODES. The validated schema is stored
 * SPLIT across two translatable fields — `field_page_structure` (the shared,
 * language-independent layout) and `field_page_content` (the per-language copy
 * overlay) — via {@see PageSchemaCodec}, so a translation can share one layout
 * while only the words diverge. Authoring, editing and rendering all speak the
 * single MERGED schema shape; {@see writeSchema} splits on write and
 * {@see resolve} merges (with per-language inheritance) on read. Using a real
 * content type gives the pages the full node ecosystem for free
 * (SEO/metatag/pathauto/sitemap, publishing workflow, revisions, Views). The
 * public face is the chrome-less render at the node's canonical URL (/node/{nid}
 * or its pathauto alias, via PageRouteSubscriber).
 */
final class PageStore {

  /**
   * Per-translation layout modes (stored in `field_layout_mode`, Phase 3b).
   *
   * SYMMETRIC: the translation inherits the source layout (its structure field
   * is empty); a source layout edit propagates to it. ASYMMETRIC: the
   * translation owns a divergent layout (copy-on-write snapshot of the source
   * skeleton at divergence time); source layout edits no longer reach it. The
   * source language is implicitly the canonical structure and carries no mode.
   */
  public const MODE_SYMMETRIC = 'symmetric';
  public const MODE_ASYMMETRIC = 'asymmetric';

  /**
   * The SEO/meta keys a page draft may carry in its `meta` block, in the order
   * the studio + agent should present them. Each key IS a Metatag plugin id, so
   * the block maps 1:1 onto `field_metatag` with no translation table — the
   * page's <title> stays in the schema (`title`), everything else in `head` is a
   * per-page override here. `title` is deliberately absent (it lives in the
   * schema and drives both the node title and the `[node:title]` meta default).
   *
   * These flow through the SAME staged-draft → Publish loop as sections: the
   * agent stages them via `preview_page` `set_meta`, the manual SEO editor stages
   * them via the draft, and {@see writeMeta} persists the override to
   * `field_metatag` on Publish. Kept language-side content (translatable field),
   * so a translation carries its own description/OG copy.
   */
  public const META_KEYS = ['description', 'canonical_url', 'og_title', 'og_description', 'og_image'];

  /**
   * The teaser keys a page draft may carry in its `teaser` block — the page's
   * PRESENCE as a referenced card (title/description/image), distinct from both
   * the page body and the SEO/meta block. Unlike `meta` (one JSON field), each
   * teaser key maps onto its OWN dedicated field so a listing/reference can
   * render the fields natively:
   *   title       → field_teaser_title       (string)
   *   description → field_teaser_description  (string_long, plain)
   *   image       → field_teaser_image        (string; a `media:<id>` token,
   *                 resolved through {@see EntityEmbedResolver} like every other
   *                 image in the schema — NOT a media reference field).
   *
   * Never derived from a body field (aincient_page has none, by design) nor from
   * the meta description — teaser and meta stay distinct sources. Flows through
   * the SAME staged-draft → Publish loop as sections/meta: the agent stages it
   * via `preview_page` `set_teaser`, the manual editor stages it via the draft,
   * and {@see writeTeaser} persists it on Publish. Translatable, so a translation
   * carries its own teaser copy.
   */
  public const TEASER_KEYS = ['title', 'description', 'image'];

  // The component vocabulary (allow-list + enums) is sourced from ComponentCatalog
  // so the validator, renderer and agent prompt can never drift. Pages carry NO
  // colour/font scheme of their own — they always render with the live brand
  // tokens (the studio-only brand convention), so there is no page-level preset.

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountProxyInterface $currentUser,
    private readonly UuidInterface $uuid,
    private readonly LanguageManagerInterface $languageManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly NodeModeration $moderation,
  ) {}

  /**
   * Clamp an arbitrary schema to the grammar. Always returns a renderable page.
   */
  public function validate(array $schema): array {
    $type = in_array($schema['type'] ?? '', ['landing', 'blog'], TRUE) ? $schema['type'] : 'landing';
    $out = [
      'type' => $type,
      'title' => $this->decodeEntities((string) ($schema['title'] ?? 'AIncient page')),
    ];

    // The SEO/meta override block is orthogonal to the page type (both landing
    // and blog carry it), so clamp it up-front before the type branches.
    $meta = $this->clampMeta($schema['meta'] ?? NULL);
    if ($meta !== []) {
      $out['meta'] = $meta;
    }

    // The teaser block (the page's presence as a referenced card) is likewise
    // type-orthogonal, so clamp it here too before the branch.
    $teaser = $this->clampTeaser($schema['teaser'] ?? NULL);
    if ($teaser !== []) {
      $out['teaser'] = $teaser;
    }

    if ($type === 'blog') {
      foreach (['category', 'lead', 'author', 'author_bio', 'date', 'cover', 'body_html'] as $k) {
        if (isset($schema[$k])) {
          // body_html is rendered raw in the prose component, so sanitise it
          // here (covers BOTH the agent and the no-AI editor). filterAdmin
          // keeps the rich tags the prose styling expects (h2/h3/p/ul/blockquote/…).
          // Every OTHER field is plain text Twig escapes on output, so normalise
          // any over-encoded entity to raw text first (see {@see decodeEntities}) —
          // body_html is excluded (it is real HTML; its entities are intentional).
          $out[$k] = $k === 'body_html'
            ? Xss::filterAdmin((string) $schema[$k])
            : $this->decodeEntities((string) $schema[$k]);
        }
      }
      return $out;
    }

    $out['sections'] = [];
    // Every section carries a STABLE id (slot identity). It survives re-validation
    // so targeted ops and the per-language content overlay can address a slot by a
    // key that doesn't shift when sections are reordered (the array index does).
    $used = [];
    foreach ($schema['sections'] ?? [] as $section) {
      $name = $section['component'] ?? '';
      if (!in_array($name, ComponentCatalog::placeableNames(), TRUE)) {
        continue;
      }
      $out['sections'][] = [
        'id' => $this->slotId($section['id'] ?? NULL, $used),
        'component' => $name,
        'props' => $this->clampProps($name, $section['props'] ?? NULL),
      ];
    }
    return $out;
  }

  /**
   * A stable per-section slot id: preserve a valid existing one (so re-validating
   * a loaded page keeps its slot identity), else mint a short unique one. $used
   * guards uniqueness within the page.
   */
  private function slotId(mixed $existing, array &$used): string {
    if (is_string($existing) && preg_match('/^[a-z0-9]{4,32}$/i', $existing) && !isset($used[$existing])) {
      $used[$existing] = TRUE;
      return $existing;
    }
    do {
      $id = substr(str_replace('-', '', $this->uuid->generate()), 0, 8);
    } while (isset($used[$id]));
    $used[$id] = TRUE;
    return $id;
  }

  /**
   * Clamp a placeable's props to the grammar so a hallucinated value can never
   * render something broken (an unknown enum) or 500 (an out-of-enum typed
   * prop). Driven entirely by {@see ComponentCatalog} so the clamp and the SDC
   * schemas stay in lock-step as the palette grows.
   */
  private function clampProps(string $name, mixed $props): array {
    $props = is_array($props) ? $props : [];
    // tone: drop an unknown surface enum — each SDC defaults its own tone.
    if (isset($props['tone']) && !in_array($props['tone'], ComponentCatalog::TONES, TRUE)) {
      unset($props['tone']);
    }
    // variant: a required SDC enum — clamp an unknown OR missing value to the
    // component's default (the first listed) so it can never trip the enum.
    if (isset(ComponentCatalog::VARIANTS[$name])) {
      $allowed = ComponentCatalog::VARIANTS[$name];
      $props['variant'] = in_array($props['variant'] ?? '', $allowed, TRUE)
        ? $props['variant']
        : $allowed[0];
    }
    // columns: typed integer in the SDC schema — coerce a form select ("3") or
    // a model string, then clamp into the component's declared column range so
    // it can't exceed the SDC enum (features tops at 3; wider grids allow 4).
    if (isset($props['columns'])) {
      $def = ComponentCatalog::placeable($name);
      $allowed = isset($def['props']['columns'])
        ? array_map('intval', explode('|', $def['props']['columns']))
        : [2, 3];
      $props['columns'] = max(min($allowed), min((int) $props['columns'], max($allowed)));
    }
    // embed: the `entity` prop is an embed token resolved at render — drop a
    // malformed one so a hallucinated value can never reach the resolver (a blank
    // entity simply renders nothing). Validated by shape only (no entity load).
    if ($name === 'embed' && isset($props['entity'])) {
      $token = is_string($props['entity']) ? trim($props['entity']) : '';
      if ($token === '' || !EntityEmbedResolver::isWellFormed($token)) {
        unset($props['entity']);
      }
      else {
        $props['entity'] = $token;
      }
    }
    // block: `ref` is a global-block reference TOKEN — the same text-token scheme
    // media/embed use. Two forms are accepted: the legacy `block:<id>` (an
    // aincient_block NODE) and, as blocks migrate onto the media bundle (DECISIONS
    // 0137), `media:<id>` (a `block` media entity). A bare positive integer is
    // accepted as sugar (manual entry / older agent output) and normalised to the
    // legacy node form; anything else, or a zero/dangling id, is dropped (a missing
    // ref renders nothing).
    if ($name === 'block' && isset($props['ref'])) {
      $ref = is_string($props['ref']) ? trim($props['ref']) : (is_int($props['ref']) ? (string) $props['ref'] : '');
      if (ctype_digit($ref)) {
        $ref = 'block:' . $ref;
      }
      if (preg_match('/^(?:block|media):(\d+)$/', $ref, $m) && (int) $m[1] > 0) {
        $props['ref'] = $ref;
      }
      else {
        unset($props['ref']);
      }
    }
    // accordion: the first heterogeneous container.
    if ($name === 'accordion') {
      // `exclusive` is a typed SDC boolean — coerce ALWAYS (independent of
      // panels), or a studio text field / model string reaches the SDC as a
      // string and 500s the render. filter_var maps "true"/"1"/"yes"/"on" → TRUE
      // and "false"/"0"/""/"no"/"off" → FALSE; any other free text → FALSE.
      if (isset($props['exclusive'])) {
        $props['exclusive'] = is_bool($props['exclusive'])
          ? $props['exclusive']
          : filter_var($props['exclusive'], FILTER_VALIDATE_BOOLEAN);
      }
      // Validate each panel's child blocks against the bounded child allow-list
      // (ACCORDION_BLOCKS) — dropping anything unknown or any container, so a
      // panel can never nest a section or another accordion — and clamp each
      // surviving child's props with the SAME per-component clamp (recurses ONE
      // level; a child is never a container, so it bottoms out). Normalises
      // panels to {label, open, blocks} so the stored shape is predictable for
      // the renderer + the studio.
      if (isset($props['panels'])) {
        $panels = is_array($props['panels']) ? $props['panels'] : [];
        $clean = [];
        foreach ($panels as $panel) {
          if (!is_array($panel)) {
            continue;
          }
          $blocks = [];
          foreach (is_array($panel['blocks'] ?? NULL) ? $panel['blocks'] : [] as $block) {
            $child = is_array($block) ? (string) ($block['component'] ?? '') : '';
            if (!in_array($child, ComponentCatalog::ACCORDION_BLOCKS, TRUE)) {
              continue;
            }
            $blocks[] = [
              'component' => $child,
              'props' => $this->clampProps($child, $block['props'] ?? NULL),
            ];
          }
          $clean[] = [
            'label' => (string) ($panel['label'] ?? ''),
            'open' => !empty($panel['open']),
            'blocks' => $blocks,
          ];
        }
        $props['panels'] = $clean;
      }
    }
    // Drop props the component doesn't declare: the renderer ignores them, so
    // keeping them only bloats the stored schema (and they're how the agent's
    // data lands in the wrong place). The SchemaLinter is what tells the agent
    // WHY a prop was dropped; here we just keep the persisted schema clean.
    $declared = ComponentCatalog::placeable($name)['props'] ?? NULL;
    $props = $declared === NULL ? $props : array_intersect_key($props, $declared);
    // Normalise over-encoded HTML entities to raw text across every prop (incl.
    // nested rows/panels). Landing props are all plain text Twig escapes — or
    // inline-markdown its renderer re-escapes — so a stored "&amp;" must be a raw
    // "&" first, or output double-encodes to a literal "&amp;". See decodeEntities.
    return $this->decodeEntities($props);
  }

  /**
   * Clamp a page's SEO/meta override block to the allow-listed keys.
   *
   * Keeps only the {@see META_KEYS} (each a Metatag plugin id), coerces each to a
   * trimmed, entity-decoded string, and drops blanks — so a blank override never
   * shadows the site default (an absent key inherits the default; that's exactly
   * how "clear this override" is expressed). A non-array meta (or none) yields the
   * empty block, so a page without SEO overrides carries no `meta` key at all.
   *
   * @return array<string, string>
   */
  private function clampMeta(mixed $meta): array {
    if (!is_array($meta)) {
      return [];
    }
    $out = [];
    foreach (self::META_KEYS as $key) {
      if (!array_key_exists($key, $meta) || !is_scalar($meta[$key])) {
        continue;
      }
      $value = trim($this->decodeEntities((string) $meta[$key]));
      if ($value === '') {
        continue;
      }
      // og_image is the one meta value that names an image. Like every other
      // schema image it may be a `media:<id>` reference token (picked/uploaded in
      // the Presence editor, resolved to an absolute URL at render — see
      // aincient_pages_metatags_alter), and — because crawlers read the tag
      // directly — it may also be a raw URL pasted in. Accept a token or a URL
      // (absolute or root-relative); drop anything else so a hallucinated value
      // can never reach the tag.
      if ($key === 'og_image'
        && !preg_match('/^media:\d+$/', $value)
        && !preg_match('#^(https?:)?//#i', $value)
        && !str_starts_with($value, '/')) {
        continue;
      }
      $out[$key] = $value;
    }
    return $out;
  }

  /**
   * Clamp a page's teaser block to the allow-listed {@see TEASER_KEYS}.
   *
   * Mirrors {@see clampMeta}: keeps only the known keys, coerces each to a
   * trimmed, entity-decoded string, and drops blanks (a blank field simply
   * clears it). The `image` key must additionally be a well-formed `media:<id>`
   * token — a malformed value is dropped so a hallucinated image can never reach
   * the renderer (same guard the `embed`/`image` section props use). A non-array
   * teaser (or none) yields the empty block, so a page without a teaser carries
   * no `teaser` key at all.
   *
   * @return array<string, string>
   */
  private function clampTeaser(mixed $teaser): array {
    if (!is_array($teaser)) {
      return [];
    }
    $out = [];
    foreach (self::TEASER_KEYS as $key) {
      if (!array_key_exists($key, $teaser) || !is_scalar($teaser[$key])) {
        continue;
      }
      $value = trim($this->decodeEntities((string) $teaser[$key]));
      if ($value === '') {
        continue;
      }
      if ($key === 'image' && !preg_match('/^media:\d+$/', $value)) {
        continue;
      }
      $out[$key] = $value;
    }
    return $out;
  }

  /**
   * Recursively HTML-entity-decode every string leaf in a value.
   *
   * The page agent (and occasionally pasted/imported copy) emits HTML entities
   * in plain-text props — "AI Ethics &amp; Policy" instead of "AI Ethics &
   * Policy". Stored verbatim, Twig then escapes the entity AGAIN on output
   * ("&amp;amp;"), so the page shows a literal "&amp;". Decoding to raw text at
   * the persist boundary fixes it at the source for EVERY write path (one-shot,
   * ops, the no-AI studio editor, the demo seed) and is correct for plain-text
   * props (Twig re-escapes), inline-markdown props (the inline renderer
   * re-escapes), and URL props alike. Idempotent — decoding raw text is a no-op,
   * so re-validating a clean schema never changes it.
   */
  private function decodeEntities(mixed $value): mixed {
    if (is_string($value)) {
      return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    if (is_array($value)) {
      return array_map([$this, 'decodeEntities'], $value);
    }
    return $value;
  }

  /**
   * Apply a list of page-DSL ops to a working schema, then clamp the result.
   *
   * This is the page agent's edit grammar — the parallel of the brand agent's
   * per-token diff, lifted to the SECTION. Ops apply sequentially against the
   * current working sections (so indices reflect prior ops in the same batch);
   * an op that can't apply is skipped and reported in `rejected` (the tool feeds
   * that back to the agent so it can self-correct) rather than aborting the
   * batch. The result is run through {@see validate()} — the SAME guardrail the
   * one-shot path uses — so a bad op can never produce an unrenderable page.
   *
   * Sections are addressed by their stable `id` (preferred — survives reordering)
   * or, as a fallback, by 0-based `index`. add_section mints a fresh id.
   *
   * Supported ops (each a map with an `op` key):
   *   - set_meta       {type?, title?, description?,  page-level fields + SEO
   *                     canonical_url?, og_title?,    overrides (null/blank a
   *                     og_description?, og_image?}   meta key ⇒ back to default)
   *   - set_teaser     {title?, description?, image?} teaser card fields (image =
   *                                                   a media:<id> token; null/
   *                                                   blank a key clears it)
   *   - add_section    {component, props?, after?}   insert (after = id|index; append if absent)
   *   - update_section {id|index, props}             shallow prop-merge (null=unset)
   *   - remove_section {id|index}                    drop a section
   *   - reorder        {order: [id|int,…]}           permutation of all slots
   *
   * Landing-only: ops target the section list. (Blog is a locked content recipe;
   * set_meta can still flip type, after which section ops are inert.)
   *
   * @param array $schema
   *   The current working schema (may be empty for a fresh page).
   * @param array $ops
   *   The ordered ops to apply.
   *
   * @return array{schema: array, rejected: array<int, array{op: string, reason: string}>}
   *   The clamped result schema and the list of skipped ops with reasons.
   */
  public function applyOps(array $schema, array $ops): array {
    $work = [
      'type' => in_array($schema['type'] ?? '', ['landing', 'blog'], TRUE) ? $schema['type'] : 'landing',
      'title' => (string) ($schema['title'] ?? 'AIncient page'),
      'sections' => [],
    ];
    // Carry the SEO/meta override block forward so set_meta ops merge onto the
    // current overrides (rather than replacing the whole block each turn).
    if (isset($schema['meta']) && is_array($schema['meta'])) {
      $work['meta'] = $schema['meta'];
    }
    // Likewise carry the teaser block forward so set_teaser ops merge onto it.
    if (isset($schema['teaser']) && is_array($schema['teaser'])) {
      $work['teaser'] = $schema['teaser'];
    }
    // Carry forward only well-formed, allow-listed sections (reindexed 0..n),
    // preserving each slot id so id-addressed ops in this batch resolve. $used
    // tracks ids so add_section mints non-colliding ones.
    $used = [];
    foreach ($schema['sections'] ?? [] as $section) {
      if (in_array($section['component'] ?? '', ComponentCatalog::placeableNames(), TRUE)) {
        $work['sections'][] = [
          'id' => $this->slotId($section['id'] ?? NULL, $used),
          'component' => $section['component'],
          'props' => is_array($section['props'] ?? NULL) ? $section['props'] : [],
        ];
      }
    }

    $rejected = [];
    foreach ($ops as $i => $op) {
      $type = is_array($op) ? (string) ($op['op'] ?? '') : '';
      try {
        switch ($type) {
          case 'set_meta':
            if (isset($op['type']) && in_array($op['type'], ['landing', 'blog'], TRUE)) {
              $work['type'] = $op['type'];
            }
            if (isset($op['title']) && is_string($op['title']) && trim($op['title']) !== '') {
              $work['title'] = trim($op['title']);
            }
            // SEO/meta overrides ride flat on the op alongside title (one staging
            // path): a present key sets that override, an explicit null / blank
            // clears it (back to the site default). Only the allow-listed keys
            // are read; validate() re-clamps the merged block.
            $meta = is_array($work['meta'] ?? NULL) ? $work['meta'] : [];
            foreach (self::META_KEYS as $key) {
              if (!array_key_exists($key, $op)) {
                continue;
              }
              $value = $op[$key];
              if ($value === NULL || (is_scalar($value) && trim((string) $value) === '')) {
                unset($meta[$key]);
              }
              elseif (is_scalar($value)) {
                $meta[$key] = trim((string) $value);
              }
            }
            if ($meta === []) {
              unset($work['meta']);
            }
            else {
              $work['meta'] = $meta;
            }
            break;

          case 'set_teaser':
            // The teaser block (the page's presence as a referenced card) stages
            // just like set_meta: each key rides flat on the op, a present value
            // sets it, an explicit null / blank clears it. validate() re-clamps
            // the merged block (drops blanks; rejects a non-media-token image).
            $teaser = is_array($work['teaser'] ?? NULL) ? $work['teaser'] : [];
            foreach (self::TEASER_KEYS as $key) {
              if (!array_key_exists($key, $op)) {
                continue;
              }
              $value = $op[$key];
              if ($value === NULL || (is_scalar($value) && trim((string) $value) === '')) {
                unset($teaser[$key]);
              }
              elseif (is_scalar($value)) {
                $teaser[$key] = trim((string) $value);
              }
            }
            if ($teaser === []) {
              unset($work['teaser']);
            }
            else {
              $work['teaser'] = $teaser;
            }
            break;

          case 'add_section':
            $name = (string) ($op['component'] ?? '');
            if (!in_array($name, ComponentCatalog::placeableNames(), TRUE)) {
              throw new \InvalidArgumentException(sprintf('unknown component "%s"', $name));
            }
            $new = [
              'id' => $this->slotId(NULL, $used),
              'component' => $name,
              'props' => is_array($op['props'] ?? NULL) ? $op['props'] : [],
            ];
            if (($op['after'] ?? NULL) === NULL) {
              $work['sections'][] = $new;
            }
            else {
              $pos = $this->afterPos($op['after'], $work['sections']);
              array_splice($work['sections'], $pos + 1, 0, [$new]);
            }
            break;

          case 'update_section':
            $idx = $this->resolveIndex($op, $work['sections']);
            $merged = $work['sections'][$idx]['props'];
            foreach (is_array($op['props'] ?? NULL) ? $op['props'] : [] as $k => $v) {
              if ($v === NULL) {
                unset($merged[$k]);
              }
              else {
                $merged[$k] = $v;
              }
            }
            $work['sections'][$idx]['props'] = $merged;
            break;

          case 'remove_section':
            $idx = $this->resolveIndex($op, $work['sections']);
            array_splice($work['sections'], $idx, 1);
            break;

          case 'reorder':
            $order = $op['order'] ?? NULL;
            if (!is_array($order)) {
              throw new \InvalidArgumentException('reorder needs an "order" array');
            }
            $count = count($work['sections']);
            $ints = $this->reorderIndices(array_values($order), $work['sections']);
            $sorted = $ints;
            sort($sorted);
            if ($sorted !== range(0, $count - 1) && !($count === 0 && $ints === [])) {
              throw new \InvalidArgumentException('order must list every section index exactly once');
            }
            $reordered = [];
            foreach ($ints as $pos) {
              $reordered[] = $work['sections'][$pos];
            }
            $work['sections'] = $reordered;
            break;

          default:
            throw new \InvalidArgumentException(sprintf('unknown op "%s"', $type === '' ? '(missing)' : $type));
        }
      }
      catch (\InvalidArgumentException $e) {
        $rejected[] = ['op' => $type !== '' ? $type : (string) $i, 'reason' => $e->getMessage()];
      }
    }

    return ['schema' => $this->validate($work), 'rejected' => $rejected];
  }

  /**
   * Resolve a section to its current array position from an op.
   *
   * Prefers the stable slot `id` (survives reordering); falls back to a numeric
   * `index` (legacy / positional). Throws if neither resolves.
   */
  private function resolveIndex(array $op, array $sections): int {
    if (isset($op['id']) && is_string($op['id']) && $op['id'] !== '') {
      foreach ($sections as $i => $section) {
        if (($section['id'] ?? NULL) === $op['id']) {
          return $i;
        }
      }
      throw new \InvalidArgumentException(sprintf('no section with id "%s"', $op['id']));
    }
    if (!isset($op['index']) || !is_numeric($op['index'])) {
      throw new \InvalidArgumentException('op needs a section "id" (or numeric "index")');
    }
    $idx = (int) $op['index'];
    if ($idx < 0 || $idx >= count($sections)) {
      throw new \InvalidArgumentException(sprintf('section index %d out of range', $idx));
    }
    return $idx;
  }

  /**
   * Position to insert AFTER for an add_section anchor (id or index).
   *
   * An id resolves to that slot's position; a numeric index is clamped so a
   * stale anchor still inserts somewhere sane; an unknown id appends (-1 →
   * inserts at 0+1 from the caller, i.e. after the last existing section).
   */
  private function afterPos(mixed $after, array $sections): int {
    if (is_string($after) && $after !== '') {
      foreach ($sections as $i => $section) {
        if (($section['id'] ?? NULL) === $after) {
          return $i;
        }
      }
      return count($sections) - 1;
    }
    return max(-1, min((int) $after, count($sections) - 1));
  }

  /**
   * Map a reorder `order` list to integer positions.
   *
   * Accepts a list of slot ids (preferred) OR positional indices. The list is
   * treated as ids only when EVERY entry is a string matching a current slot id;
   * otherwise each entry is coerced to an int (legacy positional reorder). The
   * caller validates the result is a full permutation.
   */
  private function reorderIndices(array $order, array $sections): array {
    $byId = [];
    foreach ($sections as $i => $section) {
      if (isset($section['id'])) {
        $byId[$section['id']] = $i;
      }
    }
    $allIds = $order !== [] && array_reduce(
      $order,
      static fn(bool $carry, $v): bool => $carry && is_string($v) && isset($byId[$v]),
      TRUE,
    );
    return $allIds
      ? array_map(static fn($id) => $byId[$id], $order)
      : array_map('intval', $order);
  }

  /**
   * Validate, split, and persist a schema as a new aincient_page node.
   *
   * New content starts as a DRAFT (the core decoupling: a page is no longer
   * auto-live on first save). content_moderation derives `status=FALSE` from the
   * draft state; publishing is a separate, explicit transition ({@see publish}).
   *
   * A `$langcode` sets the new node's SOURCE language (the `+` birth form sends
   * one drawn from the site's own language manifest). It is honoured only for a
   * real configured language; anything else falls through to the site default,
   * so an unknown/stale value can never mint an untranslatable node.
   *
   * @return string
   *   The new node's id.
   */
  public function store(array $schema, ?array $coauthors = NULL, ?string $langcode = NULL): string {
    $values = [
      'type' => 'aincient_page',
      'uid' => $this->currentUser->id(),
      'moderation_state' => 'draft',
    ];
    if ($langcode !== NULL && $langcode !== '' && $this->languageManager->getLanguage($langcode)) {
      $values['langcode'] = $langcode;
    }
    $node = $this->storage()->create($values);
    $this->writeSchema($node, $schema);
    $this->stampCoauthors($node, $coauthors);
    $node->save();
    return (string) $node->id();
  }

  /**
   * Stamp a revision's non-human co-authors (Studio + Agent) onto
   * `field_revision_coauthors`, alongside the human `revision_user` set by every
   * write. Provenance: the revision history then reads as the page's path (edited
   * by <human>, with <studio> · <agent>). Each entry is one JSON record
   * `{actor, id, thread?, run?}`; the set REPLACES (it describes THIS revision,
   * not an accumulation). A NULL/empty list clears it (a plain human edit with no
   * studio/agent context carries no co-authors). Non-translatable, so it's set on
   * the base entity and shared across the revision's translations.
   */
  private function stampCoauthors(ContentEntityInterface $node, ?array $coauthors): void {
    if (!$node->hasField('field_revision_coauthors')) {
      return;
    }
    $values = [];
    foreach ($coauthors ?? [] as $entry) {
      if (!is_array($entry) || !isset($entry['actor'], $entry['id'])) {
        continue;
      }
      // Keep only the known, non-empty keys so a hallucinated blob can't bloat
      // the record; actor/id are required, thread/run are optional metadata.
      $record = ['actor' => (string) $entry['actor'], 'id' => (string) $entry['id']];
      foreach (['thread', 'run'] as $k) {
        if (isset($entry[$k]) && $entry[$k] !== '') {
          $record[$k] = (string) $entry[$k];
        }
      }
      $values[] = json_encode($record);
    }
    $node->getUntranslated()->set('field_revision_coauthors', $values);
  }

  /**
   * Validate, split, and write a merged schema onto a node's structure +
   * content fields. The single write path — every persist (store/update, the
   * demo seed) routes through here so the split rule lives in one place.
   *
   * Writes to whichever translation $node represents (the active one). The
   * SOURCE language owns the canonical structure; a non-source translation
   * inherits it (SYMMETRIC) by leaving its own structure field EMPTY, so a
   * source layout edit propagates to every language. A translation that has
   * diverged (ASYMMETRIC — Phase 3b, signalled by its layout mode) writes its
   * own structure instead. Content is always per-language. Does NOT save.
   */
  public function writeSchema(ContentEntityInterface $node, array $schema): void {
    $clean = $this->validate($schema);
    $split = PageSchemaCodec::split($clean);
    // The entity's own label key: `title` for a page node, `name` for a block
    // media entity (DECISIONS 0138) — so the same schema write drives both.
    $node->set($node->getEntityType()->getKey('label'), $clean['title']);
    $node->set('field_page_content', json_encode($split['content']));
    // Source translation, or a translation that owns its layout, persists the
    // structure; a symmetric translation leaves it empty to inherit the source.
    if ($this->ownsStructure($node)) {
      $node->set('field_page_structure', json_encode($split['structure']));
    }
    else {
      $node->set('field_page_structure', '');
    }
    // SEO/meta is per-language content (like field_page_content), so it always
    // writes onto THIS translation — the override the studio staged.
    $this->writeMeta($node, $clean['meta'] ?? []);
    // The teaser is likewise per-language content — persists onto THIS
    // translation's dedicated teaser fields.
    $this->writeTeaser($node, $clean['teaser'] ?? []);
  }

  /**
   * Persist a page's SEO/meta override block onto its `field_metatag`.
   *
   * The block's keys ARE Metatag plugin ids, so it maps straight onto the field
   * (stored as one JSON value). Metatag's field preSave then drops any key whose
   * value equals the resolved site default — so what lands is a genuine PER-PAGE
   * OVERRIDE and a value matching the default silently inherits instead. An empty
   * block clears the field (the page inherits every default). No-op on a bundle
   * without the field (e.g. a global block), so writeSchema stays universal.
   */
  private function writeMeta(ContentEntityInterface $node, array $meta): void {
    if (!$node->hasField('field_metatag')) {
      return;
    }
    // A scalar set maps to the field's main `value` property; the metatag field
    // decodes both JSON (v2) and legacy serialized strings, so JSON is canonical.
    $node->set('field_metatag', $meta === [] ? NULL : json_encode($meta));
  }

  /**
   * Persist a page's teaser block onto its three dedicated teaser fields.
   *
   * The inverse of {@see readTeaser}: each {@see TEASER_KEYS} entry maps to
   * `field_teaser_{key}`, a value present in the block is written and an absent
   * key clears its field (NULL) so a removed teaser value doesn't linger. No-op
   * on a bundle without the fields (e.g. a global block), so writeSchema stays
   * universal.
   */
  private function writeTeaser(ContentEntityInterface $node, array $teaser): void {
    foreach (self::TEASER_KEYS as $key) {
      $field = 'field_teaser_' . $key;
      if (!$node->hasField($field)) {
        continue;
      }
      $value = $teaser[$key] ?? '';
      $node->set($field, $value === '' ? NULL : $value);
    }
  }

  /**
   * Whether THIS translation persists its own structure (vs inheriting the
   * source layout). True for the source language always; for a non-source
   * translation only when it has diverged to asymmetric layout (Phase 3b).
   * Until the layout-mode field exists every non-source translation is
   * symmetric, so the source is the sole structure owner.
   */
  private function ownsStructure(ContentEntityInterface $node): bool {
    if ($node->getUntranslated()->language()->getId() === $node->language()->getId()) {
      return TRUE;
    }
    return $this->modeOf($node) === self::MODE_ASYMMETRIC;
  }

  /**
   * Validate + persist a schema onto an EXISTING aincient_page node.
   *
   * The page studio's Publish for an already-saved page. The bundle has
   * new_revision on, so each save records an attributed revision (the page's
   * edit history, mirroring brand revisions). Returns FALSE if the id isn't an
   * aincient_page node, or if $langcode names a language this site doesn't have.
   *
   * @param string $id
   *   The node id.
   * @param array $schema
   *   The merged page-schema to persist.
   * @param string|null $langcode
   *   Persist a specific translation — auto-created if absent. NULL (or the
   *   source langcode) writes the source. A non-source translation is written
   *   per its layout mode (symmetric inherits the source structure).
   */
  public function update(string $id, array $schema, ?string $langcode = NULL): bool {
    // Edit the LATEST revision (the studio's head), not the published default —
    // a write must never fork from a stale default and drop a pending draft, and
    // the result must be the revision the studio reads back ({@see loadLatest}).
    $node = $this->loadHead($id);
    if ($node === NULL) {
      return FALSE;
    }
    $target = $this->translationToWrite($node, $langcode);
    if ($target === NULL) {
      return FALSE;
    }
    $this->writeSchema($target, $schema);
    if ($node instanceof NodeInterface) {
      $node->setNewRevision(TRUE);
      $node->setRevisionUserId((int) $this->currentUser->id());
      $node->setRevisionLogMessage('Updated via the page studio.');
    }
    // Saving the node persists every translation it carries.
    $node->save();
    return TRUE;
  }

  /**
   * Save the working schema as a DRAFT — a forward revision that does NOT change
   * the live page. The decoupled "Update": create-or-update, never publishes.
   *
   * On a brand-new page this is {@see store} (new draft node). On an existing
   * node it writes the schema onto a NEW revision of the editable head and sets
   * `moderation_state=draft`; if the node's default is already published, that
   * yields a pending (forward) revision and the live page is untouched. The
   * write is pinned to $baseVid (optimistic concurrency) — see {@see editRevision}.
   *
   * @return array|null
   *   The state-legibility envelope ({@see NodeModeration::legibility} + url), or
   *   NULL if the id isn't an aincient_page / the langcode is unconfigured.
   *
   * @throws \Drupal\aincient_pages\Exception\RevisionConflictException
   *   When $baseVid is stale (the node advanced since it was loaded).
   */
  public function saveDraft(array $schema, ?string $id = NULL, ?string $langcode = NULL, ?int $baseVid = NULL, ?array $coauthors = NULL): ?array {
    if ($id === NULL) {
      $newId = $this->store($schema, $coauthors, $langcode);
      return $this->stateEnvelope($newId);
    }
    return $this->editRevision($id, $schema, 'draft', $langcode, $baseVid, 'Saved draft via the page studio.', $coauthors);
  }

  /**
   * Write the latest schema (if given) then PUBLISH — the one-click save+go-live.
   *
   * Sets `moderation_state=published`, which makes the saved revision the default
   * published revision (content_moderation flips `status` + the default-revision
   * pointer). The same path serves Approve (needs_review → published): both land
   * on `published`, and the transition the user actually holds is validated by
   * {@see NodeModeration::canReachState}.
   *
   * @throws \Drupal\aincient_pages\Exception\RevisionConflictException
   *   When $baseVid is stale.
   */
  public function publish(string $id, ?array $schema = NULL, ?string $langcode = NULL, ?int $baseVid = NULL, ?array $coauthors = NULL): ?array {
    return $this->editRevision($id, $schema, 'published', $langcode, $baseVid, 'Published via the page studio.', $coauthors);
  }

  /**
   * Apply a pure editorial transition (submit-for-review / reject / archive /
   * restore) — no schema write, just a state change on a new revision.
   *
   * The target state is derived from the transition id against the node's
   * workflow; the change is refused (returns NULL) if the transition isn't legal
   * for the current user from the node's current state.
   *
   * @throws \Drupal\aincient_pages\Exception\RevisionConflictException
   *   When $baseVid is stale.
   */
  public function transition(string $id, string $transitionId, ?int $baseVid = NULL, ?array $coauthors = NULL): ?array {
    $node = $this->moderation->loadLatestRevision($id, 'aincient_page');
    if ($node === NULL) {
      return NULL;
    }
    $target = $this->moderation->targetState($node, $transitionId);
    if ($target === NULL) {
      return NULL;
    }
    return $this->editRevision($id, NULL, $target, NULL, $baseVid, sprintf('Editorial transition: %s.', $transitionId), $coauthors);
  }

  /**
   * The shared moderation-aware write: pin to the base revision, optionally write
   * the schema onto the editable head, set the target state (validating the
   * transition is legal for the current user when the state actually changes),
   * stamp a revision, and save.
   *
   * @return array|null
   *   The post-write state envelope, or NULL if the node/langcode is invalid or
   *   the state change is not a legal transition for the current user.
   */
  private function editRevision(string $id, ?array $schema, string $targetState, ?string $langcode, ?int $baseVid, string $log, ?array $coauthors = NULL): ?array {
    // Pin the write to the revision the studio based it on (409 on a stale base).
    $this->moderation->assertHead($id, $baseVid);
    $node = $this->moderation->loadLatestRevision($id, 'aincient_page');
    if (!$node instanceof NodeInterface) {
      return NULL;
    }
    // A state change must be a transition the current user legally holds — direct
    // $node->save() does NOT enforce content_moderation's constraint, so we gate
    // it here (never trust the caller; the agent never reaches this path).
    if ($this->moderation->state($node) !== $targetState
      && !$this->moderation->canReachState($node, $targetState)) {
      return NULL;
    }
    if ($schema !== NULL) {
      $target = $this->translationToWrite($node, $langcode);
      if ($target === NULL) {
        return NULL;
      }
      $this->writeSchema($target, $schema);
    }
    $node->set('moderation_state', $targetState);
    $node->setNewRevision(TRUE);
    $node->setRevisionUserId((int) $this->currentUser->id());
    $node->setRevisionLogMessage($log);
    $this->stampCoauthors($node, $coauthors);
    $node->save();
    return $this->stateEnvelope($id);
  }

  /**
   * Load the LATEST revision of a page for editing — the editable head, which may
   * be a forward draft ahead of the published default — as the merged schema plus
   * its moderation/legibility envelope and the base `vid` to pin the next write.
   *
   * This is the studio's load path (vs {@see load}, the published-default read the
   * public route uses). Returns NULL if the id isn't an aincient_page.
   *
   * @return array|null
   *   `{ node_id, langcode, schema, url, ...legibility, base_vid }` or NULL.
   */
  public function loadLatest(string $id, ?string $langcode = NULL): ?array {
    $node = $this->moderation->loadLatestRevision($id, 'aincient_page', $langcode);
    if ($node === NULL) {
      return NULL;
    }
    $envelope = $this->moderation->legibility($node, (bool) $node->access('update'));
    return [
      'node_id' => $id,
      'langcode' => $langcode,
      'schema' => $this->resolve($node),
      'url' => $this->url($id, FALSE),
    ] + $envelope;
  }

  /**
   * The post-write state envelope for a node id (state + legal transitions + base
   * vid + live url), reloaded fresh so it reflects the just-saved revision.
   */
  private function stateEnvelope(string $id): ?array {
    $node = $this->moderation->loadLatestRevision($id, 'aincient_page');
    if ($node === NULL) {
      return NULL;
    }
    return [
      'node_id' => $id,
      'url' => $this->url($id),
    ] + $this->moderation->legibility($node, (bool) $node->access('update'));
  }

  /**
   * Resolve the translation a write should land on, creating it if needed.
   *
   * Returns the source entity for a NULL/source langcode; an existing or freshly
   * added translation otherwise; NULL if $langcode isn't a configured language
   * (so a typo can't silently spawn a bogus translation).
   */
  private function translationToWrite(ContentEntityInterface $node, ?string $langcode): ?ContentEntityInterface {
    $source = $node->getUntranslated()->language()->getId();
    if ($langcode === NULL || $langcode === $source) {
      return $node->getUntranslated();
    }
    if (!$this->languageManager->getLanguage($langcode)) {
      return NULL;
    }
    return $node->hasTranslation($langcode)
      ? $node->getTranslation($langcode)
      : $node->addTranslation($langcode);
  }

  /**
   * The resolved (merged) schema for a stored page, or NULL if not a page.
   *
   * @param string $id
   *   The node id.
   * @param string|null $langcode
   *   Resolve a specific translation (defaults to the node's default language).
   */
  public function load(string $id, ?string $langcode = NULL): ?array {
    $node = $this->storage()->load($id);
    if (!$node || $node->bundle() !== 'aincient_page') {
      return NULL;
    }
    if ($langcode !== NULL && $node instanceof ContentEntityInterface && $node->hasTranslation($langcode)) {
      $node = $node->getTranslation($langcode);
    }
    return $node instanceof ContentEntityInterface ? $this->resolve($node) : NULL;
  }

  /**
   * Resolve a (possibly translated) page node into its merged schema.
   *
   * The language-aware READ — the inverse of {@see writeSchema}. The split
   * fields enable symmetric translation: a translation that hasn't diverged
   * structurally leaves `field_page_structure` EMPTY and inherits the source
   * layout, while `field_page_content` overlays only the words it localised
   * (untranslated slots/fields fall back to the source copy). For the source /
   * default language — and any monolingual site — this is a plain merge of the
   * node's own two fields (identity round-trip with what was stored).
   *
   * A SYMMETRIC translation (Phase 3a default) inherits the source structure; an
   * ASYMMETRIC one (Phase 3b — copy-on-write) uses its own. The mode flag is the
   * source of truth, so a symmetric translation inherits even if a stale
   * structure value lingers. Content always overlays the source copy per-slot.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $node
   *   The aincient_page node, in the translation to resolve.
   */
  public function resolve(ContentEntityInterface $node): array {
    $source = $node->getUntranslated();

    // Structure: the source owns it; a translation uses its own only when it has
    // diverged to asymmetric layout, otherwise it inherits the source.
    $structure = $this->ownsStructure($node)
      ? $this->decode($node, 'field_page_structure')
      : $this->decode($source, 'field_page_structure');

    // Content: a non-source translation overlays the source copy (per-slot,
    // per-key fallback); the source translation IS the base.
    $content = $this->decode($node, 'field_page_content');
    if ($node->language()->getId() !== $source->language()->getId()) {
      $content = $this->overlayContent($this->decode($source, 'field_page_content'), $content);
    }

    $merged = PageSchemaCodec::merge($structure, $content);
    // Surface THIS translation's own SEO/meta override (raw — not merged with
    // site defaults) so the studio's SEO editor shows what's set on the page and
    // round-trips it. Inherited defaults stay implicit (the audit reports those).
    $meta = $this->readMeta($node);
    if ($meta !== []) {
      $merged['meta'] = $meta;
    }
    // Teaser: THIS translation's own dedicated teaser fields (raw, like meta —
    // each language carries its own teaser copy; no source overlay).
    $teaser = $this->readTeaser($node);
    if ($teaser !== []) {
      $merged['teaser'] = $teaser;
    }
    return $merged;
  }

  /**
   * The page's OWN SEO/meta override map ({@see META_KEYS} only), decoded from
   * `field_metatag` — the inverse of {@see writeMeta}. Empty when the field is
   * unset (the page inherits every default) or the bundle has no such field.
   * Reads only the allow-listed keys, so a stray stored tag can't leak into the
   * draft. Decodes Metatag v2 JSON and legacy v1 serialized values alike.
   *
   * @return array<string, string>
   */
  private function readMeta(ContentEntityInterface $node): array {
    if (!$node->hasField('field_metatag')) {
      return [];
    }
    $raw = (string) $node->get('field_metatag')->value;
    if (trim($raw) === '') {
      return [];
    }
    $data = str_starts_with($raw, 'a:')
      ? @unserialize($raw, ['allowed_classes' => FALSE])
      : json_decode($raw, TRUE);
    if (!is_array($data)) {
      return [];
    }
    $out = [];
    foreach (self::META_KEYS as $key) {
      if (isset($data[$key]) && is_scalar($data[$key]) && (string) $data[$key] !== '') {
        $out[$key] = (string) $data[$key];
      }
    }
    return $out;
  }

  /**
   * The page's OWN teaser block, read from its dedicated teaser fields — the
   * inverse of {@see writeTeaser}. Empty keys are omitted, so a page with no
   * teaser yields the empty block. No-op keys on a bundle without the fields.
   *
   * @return array<string, string>
   */
  private function readTeaser(ContentEntityInterface $node): array {
    $out = [];
    foreach (self::TEASER_KEYS as $key) {
      $field = 'field_teaser_' . $key;
      if (!$node->hasField($field)) {
        continue;
      }
      $value = trim((string) $node->get($field)->value);
      if ($value !== '') {
        $out[$key] = $value;
      }
    }
    return $out;
  }

  /**
   * The studio's translation bootstrap: the site's languages (source first) plus
   * the governance flags it needs to render the language switcher + the
   * inherit/diverge affordance. Sourced once with the component manifest.
   *
   * @return array{languages: array<int, array{id: string, label: string, default: bool}>, multilingual: bool, allow_divergence: bool}
   */
  public function translationContext(): array {
    $default = $this->languageManager->getDefaultLanguage()->getId();
    $languages = [];
    foreach ($this->languageManager->getLanguages() as $language) {
      $languages[] = [
        'id' => $language->getId(),
        'label' => $language->getName(),
        'default' => $language->getId() === $default,
      ];
    }
    return [
      'languages' => $languages,
      'multilingual' => count($languages) > 1,
      'allow_divergence' => (bool) $this->configFactory->get('aincient_pages.settings')->get('translation.allow_divergence'),
    ];
  }

  /**
   * The non-source langcodes a page already has a translation for — so the studio
   * can mark which languages exist vs. would be created on first save. Empty for
   * a missing page or a monolingual site.
   *
   * @return string[]
   */
  public function translationsOf(string $id): array {
    // Reflect the head (a translation may be pending), matching the studio reads.
    $node = $this->loadHead($id);
    if ($node === NULL) {
      return [];
    }
    $source = $node->getUntranslated()->language()->getId();
    return array_values(array_filter(
      array_keys($node->getTranslationLanguages()),
      static fn(string $lc): bool => $lc !== $source,
    ));
  }

  /**
   * The effective layout mode of a translation: 'symmetric' (inherits the source
   * layout) or 'asymmetric' (owns its own). The source language is always
   * structurally canonical and reports symmetric. Absent flag → symmetric.
   */
  public function layoutMode(string $id, string $langcode): string {
    // Read the editable head so the mode reflects a pending diverge/converge the
    // studio just made (consistent with loadLatest), not the published default.
    $node = $this->loadHead($id);
    if ($node === NULL) {
      return self::MODE_SYMMETRIC;
    }
    if ($node->getUntranslated()->language()->getId() === $langcode || !$node->hasTranslation($langcode)) {
      return self::MODE_SYMMETRIC;
    }
    return $this->modeOf($node->getTranslation($langcode));
  }

  /**
   * Diverge a translation to its OWN (asymmetric) layout — copy-on-write.
   *
   * Snapshots the *resolved* source skeleton into the translation's structure
   * field and flips its mode to asymmetric, so the translation detaches: later
   * source layout edits no longer propagate, and the studio may now restructure
   * this language independently. Sticky until {@see converge}. Governed by the
   * `translation.allow_divergence` setting. Returns FALSE if the page/language
   * isn't valid, divergence is disabled, or it's the source language.
   */
  public function diverge(string $id, string $langcode): bool {
    if (!$this->configFactory->get('aincient_pages.settings')->get('translation.allow_divergence')) {
      return FALSE;
    }
    $node = $this->loadHead($id);
    if ($node === NULL) {
      return FALSE;
    }
    $source = $node->getUntranslated()->language()->getId();
    if ($langcode === $source || !$node->hasTranslation($langcode)) {
      return FALSE;
    }
    $translation = $node->getTranslation($langcode);
    // Copy-on-write: seed the translation's structure from the source skeleton so
    // it starts identical, then owns it. (Content overlay is untouched.)
    $translation->set('field_page_structure', $node->getUntranslated()->get('field_page_structure')->value);
    $translation->set('field_layout_mode', self::MODE_ASYMMETRIC);
    $this->stampRevision($node, sprintf('Diverged %s layout to asymmetric.', $langcode));
    $node->save();
    return TRUE;
  }

  /**
   * Re-inherit the source layout for a translation (asymmetric → symmetric).
   *
   * The reverse of {@see diverge}: discards the translation's own structure and
   * flips its mode back to symmetric, so source layout edits propagate again.
   * Always permitted (re-inheriting is never a governance risk). The translation
   * keeps its localised CONTENT — only the structural divergence is dropped.
   */
  public function converge(string $id, string $langcode): bool {
    $node = $this->loadHead($id);
    if ($node === NULL) {
      return FALSE;
    }
    if ($node->getUntranslated()->language()->getId() === $langcode || !$node->hasTranslation($langcode)) {
      return FALSE;
    }
    $translation = $node->getTranslation($langcode);
    $translation->set('field_page_structure', '');
    $translation->set('field_layout_mode', self::MODE_SYMMETRIC);
    $this->stampRevision($node, sprintf('Converged %s layout back to symmetric (inherit source).', $langcode));
    $node->save();
    return TRUE;
  }

  /**
   * The stored layout mode of a (translation) entity — asymmetric only when the
   * flag explicitly says so; absent/blank/unknown → symmetric.
   */
  private function modeOf(ContentEntityInterface $node): string {
    $mode = $node->hasField('field_layout_mode') ? $node->get('field_layout_mode')->value : NULL;
    return $mode === self::MODE_ASYMMETRIC ? self::MODE_ASYMMETRIC : self::MODE_SYMMETRIC;
  }

  /**
   * Record a new attributed revision on the node (shared by diverge/converge).
   */
  private function stampRevision(ContentEntityInterface $node, string $message): void {
    if ($node instanceof NodeInterface) {
      $node->setNewRevision(TRUE);
      $node->setRevisionUserId((int) $this->currentUser->id());
      $node->setRevisionLogMessage($message);
    }
  }

  /**
   * Decode a node's JSON field value to an array (empty array if absent/blank).
   */
  private function decode(ContentEntityInterface $node, string $field): array {
    if (!$node->hasField($field)) {
      return [];
    }
    $data = json_decode((string) $node->get($field)->value, TRUE);
    return is_array($data) ? $data : [];
  }

  /**
   * Overlay a translation's content onto the source content (symmetric mode).
   *
   * The translation wins WHERE it provides a value; the source fills the rest.
   * Handles both the landing shape ({title, slots:{id:{…}}}) and the flat blog
   * shape ({title, category, …}). Empty translation values fall back to source,
   * so an untranslated field shows the source copy rather than a blank.
   */
  private function overlayContent(array $source, array $lang): array {
    $out = $source;
    foreach ($lang as $key => $value) {
      if ($key === 'slots' && is_array($value)) {
        $slots = is_array($source['slots'] ?? NULL) ? $source['slots'] : [];
        foreach ($value as $id => $props) {
          $base = is_array($slots[$id] ?? NULL) ? $slots[$id] : [];
          $slots[$id] = array_merge($base, $this->present(is_array($props) ? $props : []));
        }
        $out['slots'] = $slots;
      }
      elseif ($this->isPresent($value)) {
        $out[$key] = $value;
      }
    }
    return $out;
  }

  /**
   * Keep only present (non-empty) values from a map — so a blank localised prop
   * falls back to the source rather than overriding it with "".
   */
  private function present(array $props): array {
    return array_filter($props, [$this, 'isPresent']);
  }

  /**
   * A value is "present" (worth overriding the source with) when it's not null,
   * not an empty string, and not an empty array.
   */
  private function isPresent(mixed $value): bool {
    return $value !== NULL && $value !== '' && $value !== [];
  }

  /**
   * A list of stored pages for the studio's page picker, newest-edited first.
   *
   * Each entry is `{ id, title, changed, url }` — enough to populate the picker
   * and the datatable presenter without loading every schema. Sorted by the
   * node's changed timestamp so the most recently touched pages surface first.
   *
   * @return array<int, array{id: string, title: string, changed: int, url: string}>
   */
  public function list(int $limit = 50): array {
    $ids = $this->storage()->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'aincient_page')
      ->sort('changed', 'DESC')
      ->range(0, $limit)
      ->execute();
    $out = [];
    foreach ($this->storage()->loadMultiple($ids) as $node) {
      $out[] = [
        'id' => (string) $node->id(),
        'title' => (string) $node->label(),
        'changed' => (int) $node->get('changed')->value,
        'url' => $node->toUrl('canonical')->toString(),
      ];
    }
    return $out;
  }

  /**
   * Total number of stored pages, for "showing N of M" affordances.
   *
   * A bare count query (no entity load), so it stays cheap no matter how many
   * pages a site accumulates — {@see self::list()} only ever hydrates a page.
   */
  public function count(): int {
    return (int) $this->storage()->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'aincient_page')
      ->count()
      ->execute();
  }

  /**
   * A paginated, optionally-filtered page directory for the studio content
   * browser (the pick-from-the-canvas surface in Content + Checks).
   *
   * Unlike {@see list} (a flat, capped quick-picker feed), this windows the
   * full set with `offset`/`limit` and reports the matching `total` so the
   * browser can render "N–M of T" + Prev/Next no matter how many pages a site
   * accumulates. Each item also carries the editorial state (badge), resolved
   * from the {@see NodeModeration} service on the default revision.
   *
   * @param int $offset
   *   Zero-based offset into the (changed-DESC) result set.
   * @param int $limit
   *   Page size (callers clamp; a defensive 1–50 clamp is applied here too).
   * @param string|null $q
   *   Optional case-insensitive title filter (CONTAINS).
   * @param string|null $state
   *   Optional state facet (the ledger toolbar's segmented filter, study 02,
   *   Plate 10): 'live' = published default revision, 'drafts' = not live
   *   (working drafts, in-review, archived). Anything else means no filter.
   *
   * @return array{items: array<int, array{id: string, title: string, changed: int, url: string, sections: int, state: string, state_label: string, has_pending_draft: bool}>, total: int, offset: int, limit: int}
   */
  public function directory(int $offset = 0, int $limit = 12, ?string $q = NULL, ?string $state = NULL): array {
    $offset = max(0, $offset);
    $limit = max(1, min(50, $limit));
    $q = $q !== NULL && trim($q) !== '' ? trim($q) : NULL;

    // The state facet queries the stored `status` flag (the moderation state
    // itself is a computed field and can't be windowed server-side): live =
    // published default revision; drafts = everything not live.
    $predicate = function () use ($q, $state) {
      $query = $this->storage()->getQuery()
        ->accessCheck(TRUE)
        ->condition('type', 'aincient_page');
      if ($q !== NULL) {
        $query->condition('title', $q, 'CONTAINS');
      }
      if ($state === 'live' || $state === 'drafts') {
        $query->condition('status', $state === 'live' ? 1 : 0);
      }
      return $query;
    };

    $total = (int) $predicate()->count()->execute();
    $ids = $predicate()->sort('changed', 'DESC')->range($offset, $limit)->execute();

    $items = [];
    foreach ($this->storage()->loadMultiple($ids) as $node) {
      $moderated = $this->moderation->isModerated($node);
      // The ledger row's mono fact line is "path · N sections" (study 02,
      // Plate 10) — the section count comes off the already-loaded node's
      // structure field (its `slots` are the page's sections), so it costs no
      // extra query. Blog pages have no slots and read 0 → the fact line just
      // shows the path.
      $structure = $this->decode($node, 'field_page_structure');
      $items[] = [
        'id' => (string) $node->id(),
        'title' => (string) $node->label(),
        'changed' => (int) $node->get('changed')->value,
        'url' => $node->toUrl('canonical')->toString(),
        'sections' => is_array($structure['slots'] ?? NULL) ? count($structure['slots']) : 0,
        'state' => $moderated ? $this->moderation->state($node) : '',
        'state_label' => $moderated ? $this->moderation->stateLabel($node) : '',
        'has_pending_draft' => $moderated && $this->moderation->hasPendingDraft($node),
      ];
    }

    return ['items' => $items, 'total' => $total, 'offset' => $offset, 'limit' => $limit];
  }

  public function url(string $id, bool $absolute = TRUE): string {
    $node = $this->storage()->load($id);
    if (!$node || $node->bundle() !== 'aincient_page') {
      return '';
    }
    // The node canonical IS the chrome-less page (PageRouteSubscriber), so this
    // resolves to the pathauto alias once one is generated.
    return $node->toUrl('canonical', ['absolute' => $absolute])->toString();
  }

  /**
   * Load the LATEST revision of an aincient_page — the editable head the studio
   * reads and writes (a forward draft may be ahead of the published default).
   * NULL if the id isn't an aincient_page. The studio-facing read/write path uses
   * this; {@see load} (default revision) is the public render path.
   */
  private function loadHead(string $id): ?ContentEntityInterface {
    return $this->moderation->loadLatestRevision($id, 'aincient_page');
  }

  private function storage(): \Drupal\Core\Entity\EntityStorageInterface {
    return $this->entityTypeManager->getStorage('node');
  }

}
