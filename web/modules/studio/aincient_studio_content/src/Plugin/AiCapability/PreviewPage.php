<?php

declare(strict_types=1);

namespace Drupal\aincient_studio_content\Plugin\AiCapability;

use Aincient\PageModel\Catalog\ComponentCatalogInterface;
use Aincient\PageModel\Catalog\EffectiveCatalog;
use Drupal\aincient_pages\PageStore;
use Aincient\PageModel\SchemaLinter;
use Drupal\aincient_core\Attribute\Capability;
use Drupal\aincient_core\Capability\CapabilityBase;
use Drupal\aincient_core\Capability\ExecutableCapabilityInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * AIncient capability: preview page edits in the user's LIVE page studio.
 *
 * The page agent's ONLY edit tool — and, like its brand sibling
 * {@see \Drupal\aincient_brand\Plugin\AiCapability\PreviewBrand}, it writes
 * NOTHING to the live site. A page can only be persisted through the studio's
 * Publish button (there is no "save page" tool); the agent's job is to drive the
 * studio's live preview by emitting incremental SECTION OPS against the page the
 * user is watching.
 *
 * It emits a generative-UI widget envelope (`{"__widget__": "page_preview",
 * "payload": {"ops": …}}`). The dispatcher harvests it out of the agent's tool
 * results; the `page_preview` widget applies the ops to the SAME unsaved-draft
 * store the studio's controls write (server-side via /atelier/page/apply, which
 * runs {@see \Drupal\aincient_pages\PageStore::applyOps}), so the preview
 * recomposes instantly and shows as an unsaved edit. The deliberate write stays
 * the studio's Publish (/atelier/page/save) — so the agent can iterate freely
 * ("add a stats band" → "make the hero split") without touching the live site.
 *
 * The current draft (with each section's index) is shown to the agent in the
 * system prompt as the LIVE PAGE STATE, so it can target ops precisely. Here we
 * STRUCTURALLY validate the ops (known op, known component) for fast agent
 * feedback and — when the workflow wires the draft into `page_state` — dry-run
 * them through PageStore::applyOps, the same authority the studio's apply runs,
 * so the result carries a `status` (applied / partially_applied / refused /
 * unverified), the refused ops with reasons, and `sections_now` (the order the
 * page actually has). The client still applies the ops to its own draft; the
 * dry-run only makes the model's tool result tell the truth (bug B3).
 *
 * @see \Drupal\aincient_pages\PageStore::applyOps()
 * @see \Drupal\aincient_studio_content\Controller\PageController
 */
#[Capability(
  id: 'aincient_studio_content:preview_page',
  function_name: 'aincient_preview_page',
  name: 'Preview page',
  description: 'Edit the page the user is composing in the LIVE page studio by emitting a list of section OPS. This recomposes the preview INSTANTLY and stages it as an unsaved draft — it does NOT publish (the user does that with the Publish button). Use it for every change so the user sees it happen. Ops (JSON array): set_meta {type?,title?,description?,canonical_url?,og_title?,og_description?,og_image?}; set_teaser {title?,description?,image?}; set_content {category?,lead?,author?,author_bio?,date?,cover?,body_md?}; add_section {component, props?, after?, replaces?}; update_section {id, props?, component?}; remove_section {id}; reorder {order:[…]}. To CONVERT a section to another component keep its id: update_section {id, component:"grid", props} or add_section {replaces:id,…} — never remove+add, that discards the section\'s translations. A page\'s TYPE (its page KIND — the site\'s kinds are listed in the system prompt as PAGE KINDS) is FIXED WHEN THE PAGE IS CREATED and cannot be changed afterwards — set_meta {type:…} is only accepted while the page has never been saved, and is REFUSED on an existing page. If the user asks to change an existing page\'s kind (e.g. turn a landing page into a blog post), do not try: tell them a new page of that kind has to be created instead. set_content writes a recipe-kind page, e.g. a blog post (ONLY on a page whose kind is a locked recipe — on a composition page it is refused, not silently ignored): the article body goes in body_md as MARKDOWN (## headings, **bold**, lists, > quotes, `code`, links — NOT HTML), cover is a media:<id> token, and lead/author/author_bio/date/category are plain text; a field set to empty string clears it. On a NEW, never-saved draft you may open with set_meta {type:…,title:…} (e.g. type:"blog") and then set_content. set_meta also sets the page\'s SEO/meta tags — description (~50–160 chars), canonical_url, and Open Graph og_title/og_description/og_image — as per-page overrides; pass an empty string to clear one back to the site default. og_image is an image: give it a media:<id> token (preferred, like the teaser image) or a full URL. set_teaser sets how the page shows up when REFERENCED as a card (in-site listings/teasers) — its teaser title, a short teaser description, and a teaser image given as a media:<id> token (NOT a URL); this is distinct from both the page body and the SEO/meta tags. Pass an empty string to clear a teaser field. set_teaser\'s fields ride FLAT on the op, e.g. {"op":"set_teaser","title":"…","description":"…"} — NEVER nest them under a "teaser" key, and NEVER put teaser fields on set_meta (they will be dropped). Target sections by their stable "id" from LIVE PAGE STATE (preferred — survives reordering); a numeric "index"/"after" still works as a fallback. The current draft — sections (with ids) and any "meta" overrides — is shown in the system prompt as LIVE PAGE STATE; component names + props are listed there too.',
  context_definitions: [
    'ops' => new ContextDefinition(
      data_type: 'list',
      label: new TranslatableMarkup('Section ops'),
      description: new TranslatableMarkup('An ARRAY of ops to apply to the current page draft (each op is an object), e.g. [{"op":"set_meta","title":"Lumen","description":"A calm place to write."},{"op":"set_teaser","title":"Lumen","description":"Write without friction."},{"op":"add_section","component":"hero","props":{"heading":"Hi","variant":"split"}},{"op":"update_section","id":"a1b2c3d4","props":{"tone":"brand"}},{"op":"update_section","id":"a1b2c3d4","component":"grid"}]. To CONVERT a section to another component, keep its id — update_section {id, component} (or add_section {replaces:id, component, props}), never remove_section + add_section, which discards that section\'s translations. Teaser fields are FLAT on set_teaser — do not nest them under "teaser" or place them on set_meta. Target a section by its "id" from the LIVE PAGE STATE in the system prompt (numeric "index" still works).'),
      required: TRUE,
      // Declare the element shape so the tool projects as a real JSON array of
      // objects (not a string the model must remember to JSON-encode). Every
      // schema-respecting provider then emits a native array; the AI data-type
      // layer still decodes a JSON-string fallback from a looser provider.
      constraints: [
        'SimpleToolItems' => [
          'type' => 'object',
          'description' => 'One section op — an object whose "op" key selects the action (set_meta, set_teaser, set_content, add_section, update_section, remove_section, reorder) and whose other keys carry that op\'s fields, e.g. {"op":"add_section","component":"hero","props":{"heading":"Hi"}}.',
        ],
      ],
    ),
    // GRAPH-supplied, never model-supplied: the workflow wires its system-prompt
    // `variables` bag (which carries `live_page_state` + `working_node_id`) into
    // this port, so FlowDrop injects it from run state and withholds it from the
    // model's tool schema (a connected input). With it, the tool dry-runs the ops
    // against the SAME draft the studio holds and reports what actually landed —
    // refused ops by name and the resulting section order — instead of a blanket
    // "previewing N edits" the model then reports as done (bug B3).
    'page_state' => new ContextDefinition(
      data_type: 'map',
      label: new TranslatableMarkup('Live page state'),
      description: new TranslatableMarkup('Wired from the workflow, not authored by the model.'),
      required: FALSE,
    ),
  ],
)]
final class PreviewPage extends CapabilityBase implements ExecutableCapabilityInterface {

  /**
   * The current user.
   */
  protected AccountInterface $currentUser;

  /**
   * The readable output (the widget envelope, or an error).
   */
  protected string $result = '';

  /**
   * The component catalog.
   *
   * This tool can't see WHICH page the ops will land on (the widget applies
   * them client-side against the studio draft), so the fast structural check
   * here accepts any component SOME kind allows — the union across kinds. The
   * authoritative per-kind clamp stays PageStore::applyOps(), which knows the
   * draft's type.
   */
  protected ComponentCatalogInterface $catalog;

  /**
   * The page store — its applyOps() is the dry-run authority.
   */
  protected PageStore $store;

  /**
   * Per-request dry-run results, keyed by the turn's injected page state.
   *
   * The injected state is the draft as the turn STARTED, but a second
   * preview_page call in the same turn lands on top of the first (the client
   * serialises the applies). So each dry-run remembers its result under the
   * turn's starting state, and the next call in the same request builds on it.
   *
   * @var array<string, array>
   */
  private static array $chained = [];

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->currentUser = $container->get('current_user');
    $instance->catalog = $container->get('aincient_pages.catalog');
    $instance->store = $container->get('aincient_pages.store');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function execute(): void {
    if (!$this->currentUser->hasPermission('administer aincient pages')) {
      $this->result = 'Error: you do not have permission to edit pages.';
      return;
    }

    // `ops` is an array param: a schema-respecting provider sends a native list,
    // and the AI data-type layer decodes a JSON-string fallback (a looser
    // provider, or a string port) into the same array before it reaches us.
    // Accept either shape.
    $decoded = $this->getContextValue('ops');
    if (is_string($decoded)) {
      $trimmed = trim($decoded);
      $decoded = $trimmed === '' ? NULL : json_decode($trimmed, TRUE);
    }
    if (!is_array($decoded) || $decoded === []) {
      $this->result = 'Error: provide a non-empty array of {op: …} objects.';
      return;
    }

    $valid = [];
    $rejected = [];
    foreach ($decoded as $op) {
      // Repair a stringified `props` object BEFORE validating or linting: the
      // repaired op is what travels in the payload and comes back to
      // PageStore::applyOps, and the linter below only sees an array props.
      if (is_array($op)) {
        $op = PageStore::normalizeOpShape($op);
      }
      $reason = $this->structuralError($op);
      if ($reason === NULL) {
        $valid[] = $op;
      }
      else {
        $rejected[] = $reason;
      }
    }

    if ($valid === []) {
      $this->result = 'Error: no usable ops. ' . implode(' ', $rejected)
        . ' Valid ops: set_meta, set_teaser, set_content, add_section, update_section, remove_section, reorder. Components: '
        . implode(', ', $this->placeableUnion()) . '.';
      return;
    }

    // Structural lint (advisory): the ops still apply, but flag sections whose
    // props won't render as intended — an unknown prop, an empty content array,
    // a misnamed row field — so the agent self-corrects on the next call. We can
    // only lint add_section here (it carries component + props); update_section
    // targets a draft section this tool can't see, so PageStore clamps that.
    $warnings = [];
    foreach ($valid as $op) {
      // add_section always names its component; update_section does so only when
      // it is CONVERTING the section — in both cases the props are lintable here.
      if (in_array($op['op'] ?? '', ['add_section', 'update_section'], TRUE)
        && is_string($op['component'] ?? NULL) && is_array($op['props'] ?? NULL)) {
        // A conversion is a PARTIAL edit: props the op omits may already sit on
        // the draft section, so the "renders empty" nag is suppressed for it.
        $full = ($op['op'] ?? '') === 'add_section';
        $warnings = array_merge($warnings, SchemaLinter::lint($this->catalogHolding((string) $op['component']), (string) $op['component'], $op['props'], $full));
      }
    }

    // Dry-run the usable ops against the live draft (when the workflow wires
    // it) through the SAME PageStore::applyOps the studio's apply endpoint runs,
    // so the result says what actually landed. Without the wire the outcome is
    // honestly "unverified" rather than a claimed success.
    $outcome = $this->dryRun($valid);
    $refused = array_merge(
      array_map(static fn(string $reason): array => ['op' => 'invalid', 'reason' => $reason], $rejected),
      $outcome['refused'] ?? [],
    );
    $sent = count($valid) + count($rejected);
    $appliedCount = $outcome === NULL ? count($valid) : count($valid) - count($outcome['refused']);
    $status = self::status($outcome === NULL, $appliedCount, count($refused));

    $this->result = (string) json_encode(array_filter([
      '__widget__' => 'page_preview',
      'payload' => [
        'ops' => $valid,
        'rejected' => $rejected,
        'warnings' => $warnings,
      ],
      'summary' => self::summary($status, $sent, $appliedCount, $refused, $warnings, $outcome['sections'] ?? NULL),
      // The model-facing verdict: what landed, what was refused and why, and —
      // when the draft was visible — the page as it now stands.
      'status' => $status,
      'applied' => $appliedCount,
      'refused_count' => count($refused),
      'refused' => $refused,
      'sections_now' => $outcome['sections'] ?? NULL,
      'note' => in_array($status, ['refused', 'partially_applied'], TRUE)
        ? 'Tell the user which change was refused and why, and what the page looks like now (sections_now). Do not report a refused change as done.'
        : NULL,
    ], static fn($v): bool => $v !== NULL));
  }

  /**
   * The overall verdict for one call.
   *
   * @return string
   *   `applied` (every op landed), `partially_applied` (some refused),
   *   `refused` (nothing landed), or `unverified` (no live draft was wired, so
   *   only the structural check ran — nothing is confirmed).
   */
  public static function status(bool $unverified, int $applied, int $refused): string {
    if ($applied === 0 && $refused > 0) {
      return 'refused';
    }
    if ($unverified) {
      return 'unverified';
    }
    return $refused > 0 ? 'partially_applied' : 'applied';
  }

  /**
   * The one-line summary — the card's caption and the model's plain reading.
   *
   * A refusal LEADS the sentence and names the page as it now stands, because
   * a summary that opened with "Previewing N edits" is what the agent echoed
   * back as done while the studio had refused the move (bug B3).
   *
   * @param array<int, array{op: string, reason: string}> $refused
   * @param string[] $warnings
   * @param array<int, array{id: string, component: string, label?: string}>|null $sections
   */
  public static function summary(string $status, int $sent, int $applied, array $refused, array $warnings, ?array $sections): string {
    $reasons = implode(' ', array_map(static fn(array $r): string => rtrim($r['op'] . ': ' . $r['reason'], '.') . '.', $refused));
    $summary = match ($status) {
      'refused' => ($sent === 1 ? 'REFUSED — the page edit was not applied' : sprintf('REFUSED — none of the %d page edits were applied', $sent)) . ', so the page did NOT change: ' . $reasons,
      'partially_applied' => sprintf('PARTIALLY applied — %d of %d page edits applied; %d REFUSED (those changed nothing): %s', $applied, $sent, count($refused), $reasons),
      'unverified' => sprintf('Sent %d page %s to the live preview (not verified against the draft)%s', $applied, $applied === 1 ? 'edit' : 'edits', $refused !== [] ? '; ' . count($refused) . ' REFUSED: ' . $reasons : '.'),
      default => sprintf('Applied %d page %s to the live preview — Publish to save the page.', $applied, $applied === 1 ? 'edit' : 'edits'),
    };
    if ($sections !== NULL) {
      $summary .= ' Sections now, in order: ' . ($sections === []
        ? '(none)'
        : implode(', ', array_map(static fn(array $s): string => $s['component'] . ' [' . $s['id'] . ']', $sections))) . '.';
    }
    if ($warnings !== []) {
      $summary .= ' ⚠ Fix and re-send: ' . implode(' ', $warnings);
    }
    return $summary;
  }

  /**
   * Dry-run the ops against the wired live draft.
   *
   * @param array $ops
   *   The structurally valid ops.
   *
   * @return array{refused: array<int, array{op: string, reason: string}>, sections: array}|null
   *   What PageStore refused and the resulting section order, or NULL when no
   *   page state was wired (nothing to verify against).
   */
  private function dryRun(array $ops): ?array {
    // Only a value the graph actually supplied counts: an unset optional map
    // context can read back as an empty map, which would masquerade as "a
    // fresh, empty draft" and dry-run against the wrong page.
    if (!isset($this->context['page_state'])) {
      return NULL;
    }
    $state = $this->getContextValue('page_state');
    if (is_string($state)) {
      $state = json_decode($state, TRUE);
    }
    if (!is_array($state)) {
      return NULL;
    }
    // The bag carries the draft as a JSON string under `live_page_state`; a
    // fresh page sends none, which is the studio's empty draft too.
    $schema = $state['live_page_state'] ?? [];
    if (is_string($schema)) {
      $schema = json_decode($schema, TRUE);
    }
    $schema = is_array($schema) ? $schema : [];
    // A saved page's type is locked at birth (DECISIONS 0378) — the same signal
    // the studio's apply endpoint reads (its node id).
    $locked = !empty($state['working_node_id']);

    $key = md5((string) json_encode([$schema, $locked]));
    $base = self::$chained[$key] ?? $schema;
    $result = $this->store->applyOps($base, $ops, $locked);
    self::$chained[$key] = $result['schema'];

    return [
      'refused' => array_values($result['rejected']),
      'sections' => self::sectionList($result['schema']['sections'] ?? []),
    ];
  }

  /**
   * A compact, ordered view of the sections: id, component, and a label.
   *
   * @return array<int, array{id: string, component: string, label?: string}>
   */
  public static function sectionList(mixed $sections): array {
    $list = [];
    foreach (is_array($sections) ? $sections : [] as $section) {
      if (!is_array($section)) {
        continue;
      }
      $row = [
        'id' => (string) ($section['id'] ?? ''),
        'component' => (string) ($section['component'] ?? ''),
      ];
      $props = is_array($section['props'] ?? NULL) ? $section['props'] : [];
      foreach (['heading', 'title', 'eyebrow'] as $key) {
        if (is_string($props[$key] ?? NULL) && trim($props[$key]) !== '') {
          $row['label'] = mb_strimwidth(trim($props[$key]), 0, 60, '…');
          break;
        }
      }
      $list[] = $row;
    }
    return $list;
  }

  /**
   * Reset the per-request dry-run chain (tests).
   */
  public static function resetChain(): void {
    self::$chained = [];
  }

  /**
   * The per-op grammar: for each op, the keys it accepts alongside "op".
   *
   * This is the schema the {@see self::structuralError} validator enforces — a
   * discriminated union keyed on "op", with a closed key set per variant (the
   * equivalent of JSON Schema's `additionalProperties: false`). Keeping it
   * declarative makes the allowed shape self-documenting and lets the validator
   * name the exact offending key back to the agent so it self-corrects. Built at
   * runtime (not a const) so the metadata ops single-source their key lists from
   * {@see PageStore::META_KEYS} / {@see PageStore::TEASER_KEYS}.
   *
   * `fields` are the value-bearing keys that make the op DO something (a set_meta
   * / set_teaser with none of these is a no-op we reject rather than silently
   * drop — the class of bug where the agent nested teaser fields on set_meta and
   * got a false "done"). `optional` are accepted-but-not-effective extras.
   *
   * @return array<string, array{fields: string[], optional: string[]}>
   */
  private static function opSpecs(): array {
    return [
      'set_meta' => ['fields' => array_merge(['type', 'title'], PageStore::META_KEYS), 'optional' => []],
      'set_teaser' => ['fields' => PageStore::TEASER_KEYS, 'optional' => []],
      'set_content' => ['fields' => PageStore::BLOG_CONTENT_KEYS, 'optional' => []],
      'add_section' => ['fields' => ['component'], 'optional' => ['props', 'after', 'replaces']],
      'update_section' => ['fields' => ['id', 'index'], 'optional' => ['props', 'component']],
      'remove_section' => ['fields' => ['id', 'index'], 'optional' => []],
      'reorder' => ['fields' => ['order'], 'optional' => []],
    ];
  }

  /**
   * Structurally validate one op against {@see self::opSpecs}; returns an error
   * string (agent-facing — it names the fix), or NULL if usable.
   *
   * Light validation for fast agent feedback — the authoritative clamp (index
   * bounds, enum coercion, prop merge) is {@see PageStore::applyOps}. This layer
   * exists to turn a MIS-SHAPED op into a visible rejection instead of a silent
   * no-op: an unknown key (e.g. a teaser field nested on set_meta), a missing
   * required field, or an op that carries nothing actionable.
   */
  private function structuralError(mixed $op): ?string {
    if (!is_array($op)) {
      return 'an op must be an object.';
    }
    $type = (string) ($op['op'] ?? '');
    $specs = self::opSpecs();
    $spec = $specs[$type] ?? NULL;
    if ($spec === NULL) {
      return sprintf('unknown op "%s". Valid ops: %s.', $type === '' ? '(missing)' : $type, implode(', ', array_keys($specs)));
    }

    // Closed key set (additionalProperties: false): reject any key that is not
    // "op", a field, or an accepted optional — with a targeted hint when the
    // stray key belongs to a sibling op (the set_meta ⇄ set_teaser confusion).
    $allowed = array_merge(['op'], $spec['fields'], $spec['optional']);
    foreach (array_keys($op) as $key) {
      if (in_array($key, $allowed, TRUE)) {
        continue;
      }
      if ($type === 'set_meta' && ($key === 'teaser' || in_array($key, PageStore::TEASER_KEYS, TRUE))) {
        return sprintf('set_meta does not accept "%s" — teaser fields go on a separate set_teaser op (flat: {"op":"set_teaser","title":…}).', $key);
      }
      return sprintf('%s does not accept "%s" (allowed: %s).', $type, $key, implode(', ', $allowed));
    }

    // `props` must be an object. normalizeOpShape() has already decoded a
    // stringified one by the time we get here, so anything still not an array
    // is unusable — and used to be swapped for `[]`, adding a blank section
    // while the agent reported the content it believed it had sent.
    if (array_key_exists('props', $op) && $op['props'] !== NULL && !is_array($op['props'])) {
      return sprintf('%s "props" must be an object of prop values, got %s — send props as JSON, not as a string.', $type, get_debug_type($op['props']));
    }

    // Per-op requirements.
    switch ($type) {
      case 'add_section':
        $component = (string) ($op['component'] ?? '');
        if (!in_array($component, $this->placeableUnion(), TRUE)) {
          return sprintf('add_section needs a known component (got "%s").', $component);
        }
        break;

      case 'update_section':
      case 'remove_section':
        if (!((isset($op['id']) && is_string($op['id']) && $op['id'] !== '')
          || (isset($op['index']) && is_numeric($op['index'])))) {
          return sprintf('%s needs a section "id" (or numeric "index").', $type);
        }
        // An optional `component` CONVERTS the section in place (keeping its id,
        // and with it the slot's translations) — so it must name a real one.
        if ($type === 'update_section' && array_key_exists('component', $op)
          && !in_array((string) ($op['component'] ?? ''), $this->placeableUnion(), TRUE)) {
          return sprintf('update_section "component" must be a known component (got "%s").', (string) ($op['component'] ?? ''));
        }
        break;

      case 'reorder':
        if (!(isset($op['order']) && is_array($op['order']))) {
          return 'reorder needs an "order" array.';
        }
        break;
    }

    // Effectiveness: a set_meta / set_teaser must carry at least one field, else
    // it is a no-op that would report a false success. (The other ops already
    // require a field above, so this only bites the fieldless metadata ops.)
    if (in_array($type, ['set_meta', 'set_teaser', 'set_content'], TRUE)
      && !array_intersect($spec['fields'], array_keys($op))) {
      return sprintf('%s needs at least one field to set (%s).', $type, implode(', ', $spec['fields']));
    }

    return NULL;
  }

  /**
   * Every component SOME kind allows — the fast-feedback allow-list (Phase 5).
   *
   * Kind-blind by design: this tool can't see the target page, and rejecting a
   * component another kind allows would be a false negative. PageStore is the
   * per-kind authority when the ops apply.
   *
   * @return string[]
   */
  private function placeableUnion(): array {
    $names = [];
    foreach (array_keys($this->catalog->kinds()) as $kind) {
      foreach ($this->catalog->for($kind)->placeableNames() as $name) {
        $names[$name] = TRUE;
      }
    }
    return array_keys($names);
  }

  /**
   * The first kind-catalog holding $component (landing when none) — gives the
   * advisory linter a def for components that a narrowed landing palette or a
   * client kind carries.
   */
  private function catalogHolding(string $component): EffectiveCatalog {
    foreach (array_keys($this->catalog->kinds()) as $kind) {
      $catalog = $this->catalog->for($kind);
      if (in_array($component, $catalog->placeableNames(), TRUE)) {
        return $catalog;
      }
    }
    return $this->catalog->for('landing');
  }

  /**
   * {@inheritdoc}
   */
  public function getReadableOutput(): string {
    return $this->result;
  }

}
