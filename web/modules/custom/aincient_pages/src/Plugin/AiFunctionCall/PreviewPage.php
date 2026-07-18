<?php

declare(strict_types=1);

namespace Drupal\aincient_pages\Plugin\AiFunctionCall;

use Drupal\aincient_pages\ComponentCatalog;
use Drupal\aincient_pages\PageStore;
use Drupal\aincient_pages\SchemaLinter;
use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * AIncient capability: preview page edits in the user's LIVE page studio.
 *
 * The page agent's ONLY edit tool — and, like its brand sibling
 * {@see \Drupal\aincient_brand\Plugin\AiFunctionCall\PreviewBrand}, it writes
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
 * only STRUCTURALLY validate the ops (known op, known component) for fast agent
 * feedback; the authoritative clamp (index bounds, enum coercion) is PageStore's
 * when the ops are applied. The grammar (sections + props) is in the prompt.
 *
 * @see \Drupal\aincient_pages\PageStore::applyOps()
 * @see \Drupal\aincient_pages\Controller\PageController
 */
#[FunctionCall(
  id: 'aincient_pages:preview_page',
  function_name: 'aincient_preview_page',
  name: 'Preview page',
  description: 'Edit the page the user is composing in the LIVE page studio by emitting a list of section OPS. This recomposes the preview INSTANTLY and stages it as an unsaved draft — it does NOT publish (the user does that with the Publish button). Use it for every change so the user sees it happen. Ops (JSON array): set_meta {type?,title?,description?,canonical_url?,og_title?,og_description?,og_image?}; set_teaser {title?,description?,image?}; set_content {category?,lead?,author?,author_bio?,date?,cover?,body_md?}; add_section {component, props?, after?}; update_section {id, props}; remove_section {id}; reorder {order:[…]}. set_content writes a BLOG post (only when the page type is "blog"): the article body goes in body_md as MARKDOWN (## headings, **bold**, lists, > quotes, `code`, links — NOT HTML), cover is a media:<id> token, and lead/author/author_bio/date/category are plain text; a field set to empty string clears it. To create a post: set_meta {type:"blog",title:…} then set_content. set_meta also sets the page\'s SEO/meta tags — description (~50–160 chars), canonical_url, and Open Graph og_title/og_description/og_image — as per-page overrides; pass an empty string to clear one back to the site default. og_image is an image: give it a media:<id> token (preferred, like the teaser image) or a full URL. set_teaser sets how the page shows up when REFERENCED as a card (in-site listings/teasers) — its teaser title, a short teaser description, and a teaser image given as a media:<id> token (NOT a URL); this is distinct from both the page body and the SEO/meta tags. Pass an empty string to clear a teaser field. set_teaser\'s fields ride FLAT on the op, e.g. {"op":"set_teaser","title":"…","description":"…"} — NEVER nest them under a "teaser" key, and NEVER put teaser fields on set_meta (they will be dropped). Target sections by their stable "id" from LIVE PAGE STATE (preferred — survives reordering); a numeric "index"/"after" still works as a fallback. The current draft — sections (with ids) and any "meta" overrides — is shown in the system prompt as LIVE PAGE STATE; component names + props are listed there too.',
  context_definitions: [
    'ops' => new ContextDefinition(
      data_type: 'list',
      label: new TranslatableMarkup('Section ops'),
      description: new TranslatableMarkup('An ARRAY of ops to apply to the current page draft (each op is an object), e.g. [{"op":"set_meta","title":"Lumen","description":"A calm place to write."},{"op":"set_teaser","title":"Lumen","description":"Write without friction."},{"op":"add_section","component":"hero","props":{"heading":"Hi","variant":"split"}},{"op":"update_section","id":"a1b2c3d4","props":{"tone":"brand"}}]. Teaser fields are FLAT on set_teaser — do not nest them under "teaser" or place them on set_meta. Target a section by its "id" from the LIVE PAGE STATE in the system prompt (numeric "index" still works).'),
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
  ],
)]
final class PreviewPage extends FunctionCallBase implements ExecutableFunctionCallInterface {

  /**
   * The current user.
   */
  protected AccountInterface $currentUser;

  /**
   * The readable output (the widget envelope, or an error).
   */
  protected string $result = '';

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->currentUser = $container->get('current_user');
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
        . implode(', ', ComponentCatalog::placeableNames()) . '.';
      return;
    }

    // Structural lint (advisory): the ops still apply, but flag sections whose
    // props won't render as intended — an unknown prop, an empty content array,
    // a misnamed row field — so the agent self-corrects on the next call. We can
    // only lint add_section here (it carries component + props); update_section
    // targets a draft section this tool can't see, so PageStore clamps that.
    $warnings = [];
    foreach ($valid as $op) {
      if (($op['op'] ?? '') === 'add_section' && is_array($op['props'] ?? NULL)) {
        $warnings = array_merge($warnings, SchemaLinter::lint((string) $op['component'], $op['props']));
      }
    }

    $count = count($valid);
    $summary = 'Previewing ' . $count . ' page ' . ($count === 1 ? 'edit' : 'edits')
      . ' — watch the live preview, then Publish to save the page.';
    if ($rejected !== []) {
      $summary .= ' (Skipped: ' . implode(' ', $rejected) . ')';
    }
    if ($warnings !== []) {
      $summary .= ' ⚠ Fix and re-send: ' . implode(' ', $warnings);
    }

    $this->result = (string) json_encode([
      '__widget__' => 'page_preview',
      'payload' => [
        'ops' => $valid,
        'rejected' => $rejected,
        'warnings' => $warnings,
      ],
      'summary' => $summary,
    ]);
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
      'add_section' => ['fields' => ['component'], 'optional' => ['props', 'after']],
      'update_section' => ['fields' => ['id', 'index'], 'optional' => ['props']],
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

    // Per-op requirements.
    switch ($type) {
      case 'add_section':
        $component = (string) ($op['component'] ?? '');
        if (!in_array($component, ComponentCatalog::placeableNames(), TRUE)) {
          return sprintf('add_section needs a known component (got "%s").', $component);
        }
        break;

      case 'update_section':
      case 'remove_section':
        if (!((isset($op['id']) && is_string($op['id']) && $op['id'] !== '')
          || (isset($op['index']) && is_numeric($op['index'])))) {
          return sprintf('%s needs a section "id" (or numeric "index").', $type);
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
   * {@inheritdoc}
   */
  public function getReadableOutput(): string {
    return $this->result;
  }

}
