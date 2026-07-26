<?php

declare(strict_types=1);

namespace Drupal\aincient_core;

/**
 * The AIncient model-role taxonomy — product vocabulary, not operator data.
 *
 * AIncient never hard-codes a vendor model id in a workflow. Instead the product
 * speaks in *roles* (semantic capability tiers); an operator binds each role to a
 * concrete `provider:model` once, and {@see ModelRoleResolver} projects those
 * bindings onto drupal/ai's operation-type defaults so the framework's native
 * routing carries them to every LLM call (FlowDrop agent nodes inherit via their
 * empty-model → site-default fallback).
 *
 * This class holds the parts that are *product definition* (the role set, labels,
 * the operation types each role drives, and per-provider model preferences). The
 * mutable parts — which provider+model an operator actually picked — live in the
 * `aincient_core.model_roles` config object, owned by the resolver.
 *
 * The role set is intentionally small and extensible: add a key here (+ schema +
 * UI) and the rest of the system follows.
 */
final class ModelRoles {

  /**
   * "High thinking" — deep reasoning, planning, complex/structured JSON, tools.
   */
  public const REASONING = 'reasoning';

  /**
   * "Task executor" — the everyday tier: fast chat turns, bulk operations.
   */
  public const TASK = 'task';

  /**
   * "Fast" — cheapest/quickest tier for trivial classify/extract calls.
   */
  public const FAST = 'fast';

  /**
   * "Image" — image generation/editing (text→image, image→image).
   *
   * The odd one out: it is NOT a chat tier. It binds to an *image* provider
   * (e.g. `nanobanana`), not a chat one, so it lives OUTSIDE {@see self::definitions()}
   * — the chat-role taxonomy that onboarding auto-binds and the role form renders.
   * Keeping it out of that set means a chat-provider connect never accidentally
   * binds it (which would falsely light up the Media studio's AI rail). It is bound
   * explicitly (Media studio provider config) and consumed only through
   * {@see ModelRoleResolver::imageBinding()} — never the ambiguous op-default,
   * since more than one installed provider advertises `text_to_image`.
   */
  public const IMAGE = 'image';

  /**
   * "Vision" — image→text: reads an image and describes it (alt text, captions).
   *
   * The other capability role that lives OUTSIDE {@see self::definitions()} (the
   * chat-tier taxonomy). Unlike {@see self::IMAGE}, vision is NOT its own provider
   * class: drupal/ai has no `image_to_text` operation type — "seeing" an image is a
   * {@see \Drupal\ai\OperationType\Chat\ChatInterface} call with the image attached,
   * so this role binds to a vision-capable *chat* model (Gemini, GPT-4o, Claude),
   * drawn from the chat pool like the tiers. It is kept out of the tier set only so
   * it reads as a distinct capability on the models page — not a thinking tier.
   *
   * Consumed through {@see ModelRoleResolver::resolve()} (NOT a hard gate like
   * image): when unbound it falls back to the default chat role, so alt-text
   * generation works out of the box; the explicit binding is an override that
   * pins a specific vision model. It carries NO operation-type projection so it
   * never clobbers the tier that already owns `chat_with_image_vision`.
   */
  public const VISION = 'vision';

  /**
   * Human-facing label + description per role, in display order.
   *
   * @return array<string, array{label: string, description: string}>
   */
  public static function definitions(): array {
    return [
      self::REASONING => [
        'label' => 'High thinking',
        'description' => 'Deep reasoning, planning, complex and structured JSON, tool use. The most capable (and costliest) tier.',
      ],
      self::TASK => [
        'label' => 'Task executor',
        'description' => 'The everyday tier — fast chat turns and bulk operations. Drives the assistant console by default.',
      ],
      self::FAST => [
        'label' => 'Fast',
        'description' => 'Cheapest, quickest tier for trivial classify/extract steps. Reserved until per-node roles land.',
      ],
    ];
  }

  /**
   * The drupal/ai operation-type defaults each role projects onto.
   *
   * Mirrors how drupal/ai already splits work: plain `chat` for everyday turns,
   * the `complex`/`structured`/`tools` variants for harder calls. `fast` has no
   * native operation type yet, so it carries no targets in v1 — it's bound and
   * resolvable, but only consumed once a node can request a role explicitly.
   *
   * @return array<string, list<string>>
   */
  public static function operationTypeMap(): array {
    return [
      self::TASK => ['chat', 'chat_with_image_vision'],
      self::REASONING => [
        'chat_with_complex_json',
        'chat_with_structured_response',
        'chat_with_tools',
      ],
      self::FAST => [],
      // The image role drives both generation modes. When bound, {@see
      // ModelRoleResolver::project()} writes it onto BOTH operation-type
      // defaults so any op-default lookup also lands on the image provider —
      // but the Media studio always resolves it through the explicit binding.
      self::IMAGE => ['text_to_image', 'image_to_image'],
    ];
  }

  /**
   * Providers that RE-SERVE other vendors' models under one credential.
   *
   * A proxy's catalogue is other people's models, namespaced by vendor
   * (`anthropic/claude-opus-5`, `openai/gpt-5.6-sol`). Everything we curate is
   * written about the UPSTREAM vendor, so both halves of that curation have to
   * look through the proxy to find it: {@see ModelPresetResolver} for which model
   * a profile picks, {@see ModelRecommendations} for the quality label a model
   * carries. Neither works on a proxy-only site without this.
   *
   * Lives here, in the product vocabulary, precisely so the two cannot drift into
   * disagreeing about what a proxy is.
   */
  public const PROXY_PROVIDERS = ['litellm', 'openrouter'];

  /**
   * Whether a provider id re-serves other vendors' models.
   */
  public static function isProxyProvider(string $providerId): bool {
    return in_array($providerId, self::PROXY_PROVIDERS, TRUE);
  }

  /**
   * The upstream vendor a proxy model id names, and the id without it.
   *
   * `anthropic/claude-opus-5` → `['anthropic', 'claude-opus-5']`. A proxy id with
   * no vendor segment yields an empty vendor and the id unchanged, which callers
   * read as "the vendor is unknown, match across all of them".
   *
   * The vendor is the FIRST segment — LiteLLM namespaces as `<provider>/<model>`,
   * and a deeper id (`vertex_ai/google/gemini-3`) names its provider first, with
   * the rest being route detail. What is left keeps any inner segments, which is
   * harmless: every needle we match with it is a substring test.
   *
   * @return array{0: string, 1: string}
   *   The vendor id (or '') and the model id without the vendor segment.
   */
  public static function splitProxyModel(string $modelId): array {
    $modelId = strtolower(trim($modelId));
    $slash = strpos($modelId, '/');
    if ($slash === FALSE) {
      return ['', $modelId];
    }
    return [substr($modelId, 0, $slash), substr($modelId, $slash + 1)];
  }

  /**
   * Per-provider, per-role model preference (ordered substring needles).
   *
   * When an operator connects a provider, {@see ModelRoleResolver::suggestForProvider()}
   * picks each role's model by walking these needles against the provider's
   * available chat models — the first match wins; no match ⇒ the first model.
   * A provider absent here just gets "first available" for every role, which is a
   * safe neutral default for anything we haven't tuned (e.g. Ollama).
   *
   * @return array<string, array<string, list<string>>>
   *   provider id => role id => ordered needles.
   */
  public static function tierHints(): array {
    return [
      'anthropic' => [
        self::REASONING => ['opus'],
        self::TASK => ['sonnet'],
        self::FAST => ['haiku'],
      ],
      'openai' => [
        self::REASONING => ['o3', 'o1', 'gpt-4o'],
        self::TASK => ['gpt-4o', 'gpt-4.1', 'gpt-4-'],
        self::FAST => ['gpt-4o-mini', 'mini', 'gpt-3.5'],
      ],
      // OpenRouter aggregates many vendors; ids are namespaced (e.g.
      // "anthropic/claude-opus-4", "openai/gpt-4o-mini"), so the needles favour
      // a strong frontier model for reasoning and a cheap one for fast.
      'openrouter' => [
        self::REASONING => ['opus', 'o3', 'o1', 'gpt-4o', 'gemini-2.5-pro', 'pro'],
        self::TASK => ['sonnet', 'gpt-4o', 'gpt-4.1', 'gemini-2.5-flash'],
        self::FAST => ['haiku', 'gpt-4o-mini', 'mini', 'flash'],
      ],
      // A LiteLLM proxy re-serves other vendors' models under vendor-namespaced
      // ids ("anthropic/claude-opus-5", "openai/gpt-5.4"), and WHICH ids exist is
      // the proxy operator's choice — a hosted demo may expose three models, a
      // company proxy fifty. So these needles are families rather than model ids,
      // and they are only reached when the curated document named nothing the
      // proxy serves ({@see ModelPresetResolver}, which tries the document's
      // candidates against proxies first). The point is that a role still lands on
      // a model of roughly the right cost, instead of whatever the catalogue
      // happens to list first.
      'litellm' => [
        self::REASONING => ['opus', 'gpt-5.6-sol', 'gpt-5.6-terra', 'sonnet', 'gpt-5', 'mistral-large', 'pro'],
        self::TASK => ['sonnet', 'gpt-5.6-luna', 'haiku', 'gpt-5', 'mistral-medium', 'flash'],
        self::FAST => ['haiku', 'nano', 'mini', 'flash-lite', 'flash', 'small'],
      ],
    ];
  }

  /**
   * All role ids, in display order.
   *
   * @return list<string>
   */
  public static function ids(): array {
    return array_keys(self::definitions());
  }

  /**
   * Whether a string is a known role id.
   *
   * Covers the chat-tier roles ({@see self::definitions()}) PLUS the out-of-band
   * capability roles ({@see self::IMAGE}, {@see self::VISION}), so {@see
   * ModelRoleResolver::bind()}/`resolve()` accept them even though they never
   * appear in the chat-tier UI.
   */
  public static function isRole(string $role): bool {
    return isset(self::definitions()[$role]) || in_array($role, [self::IMAGE, self::VISION], TRUE);
  }

}
