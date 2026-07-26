<?php

declare(strict_types=1);

namespace Drupal\aincient_core;

/**
 * Turns "what are you optimising for?" into a model per role.
 *
 * The onboarding wizard used to ask a beginner to make five independent
 * vendor-model decisions before they had ever used the product. This service
 * collapses that into ONE question — Best value / Balanced / Best quality — by
 * resolving a named *profile* from the curated document ({@see RecommendationSource})
 * against the models the operator's connected providers actually offer.
 *
 * The per-role pickers didn't go anywhere; they sit behind a disclosure. This is
 * about the default path, not about taking control away.
 *
 * ## The resolution contract
 *
 * A profile gives each role an ORDERED list of `provider:model` candidates, best
 * first. Per role, first hit wins:
 *
 *   1. the region's candidates for that role (when a region is given), then the
 *      profile's — a region only overrides the roles it actually improves;
 *   2. a candidate that EXACTLY matches a key in the role's pool;
 *   3. the same candidate's model half as a FAMILY match against that provider's
 *      models in the pool ({@see self::matches()}) — this is what lets
 *      `claude-sonnet-5` keep resolving after the vendor starts returning
 *      `claude-sonnet-5-20260401`, with no edit to the published document;
 *   4. the same candidates again, this time against the PROXY providers
 *      ({@see self::PROXY_PROVIDERS}) — a second pass, so a direct key always
 *      beats the same model reached through a proxy;
 *   5. {@see ModelRoles::tierHints()} — the pre-existing, provider-local
 *      preference needles, so a provider the document says nothing about (Ollama,
 *      Mistral) behaves exactly as it did before this service existed;
 *   6. the first model in the pool.
 *
 * Every step reads from the POOL — the models we probed with the operator's own
 * credential. A candidate naming a model they can't serve is skipped silently, so
 * the published document can safely recommend models most operators won't have,
 * and a stale entry degrades to the next candidate instead of binding something
 * broken. An empty pool yields an empty string: unresolvable roles are left
 * UNBOUND rather than guessed at.
 */
final class ModelPresetResolver {

  /**
   * Providers that RE-SERVE other vendors' models under one credential.
   *
   * A proxy's catalogue is other people's models, namespaced by vendor
   * (`anthropic/claude-opus-5`, `openai/gpt-5.6-sol`) — so the curated document's
   * candidates describe them perfectly well, they just don't carry the proxy's
   * provider id. Without this, every profile collapses to "the first model in the
   * pool" for an operator whose only connected provider is a proxy, which is
   * exactly the case for a hosted demo with a pre-wired LiteLLM key: three
   * profiles, one arbitrary answer, and a choice that means nothing.
   *
   * Tried in a SECOND pass over the candidates ({@see self::pick()}), so a direct
   * key always wins over the same model reached through a proxy. The list itself is
   * {@see ModelRoles::PROXY_PROVIDERS} — shared with {@see ModelRecommendations},
   * which has to look through a proxy for the same reason.
   */
  private const PROXY_PROVIDERS = ModelRoles::PROXY_PROVIDERS;

  /**
   * Shortest pool model id allowed to capture a longer candidate.
   *
   * Guards the reverse half of {@see self::matches()}: `claude-haiku-4-5` (16)
   * may resolve the document's dated `claude-haiku-4-5-20251001`, but a short,
   * generic id like `gpt-5` must never capture `gpt-5.6-sol` — which is a
   * different model at a different price.
   */
  private const FAMILY_MIN = 8;

  public function __construct(private readonly RecommendationSource $source) {}

  /**
   * The selectable profiles, in document order.
   *
   * @return list<array{id: string, label: string, description: string}>
   *   One row per profile, in the order the document defines them.
   */
  public function profiles(): array {
    $out = [];
    foreach ($this->source->document()['profiles'] ?? [] as $id => $profile) {
      if (!is_array($profile)) {
        continue;
      }
      $out[] = [
        'id' => (string) $id,
        'label' => (string) ($profile['label'] ?? $id),
        'description' => (string) ($profile['description'] ?? ''),
      ];
    }
    return $out;
  }

  /**
   * The profile applied when the operator hasn't chosen one.
   *
   * The document names one; we fall back to the first defined profile so a
   * document that forgets `default_profile` still works.
   */
  public function defaultProfile(): string {
    $document = $this->source->document();
    $named = (string) ($document['default_profile'] ?? '');
    if ($named !== '' && isset($document['profiles'][$named])) {
      return $named;
    }
    return (string) (array_key_first($document['profiles'] ?? []) ?? '');
  }

  /**
   * Whether a profile id exists in the document in force.
   */
  public function hasProfile(string $profileId): bool {
    return isset($this->source->document()['profiles'][$profileId]);
  }

  /**
   * The regions the document defines, in document order.
   *
   * Empty in v1 — the schema carries regions so the day we add a role with a
   * genuine regional winner (Sarvam for Indian speech, say) needs no shape
   * change, but nothing in the product selects one yet.
   *
   * @return list<array{id: string, label: string}>
   *   One row per region, in the order the document defines them.
   */
  public function regions(): array {
    $out = [];
    foreach ($this->source->document()['regions'] ?? [] as $id => $region) {
      if (!is_array($region)) {
        continue;
      }
      $out[] = ['id' => (string) $id, 'label' => (string) ($region['label'] ?? $id)];
    }
    return $out;
  }

  /**
   * Resolve a profile to one `provider:model` per role.
   *
   * @param string $profileId
   *   A profile id from {@see self::profiles()}. An unknown id resolves as if no
   *   profile were given — every role falls through to the tier hints.
   * @param array<string, string> $chatPool
   *   Available chat models, "provider:model" => label.
   * @param array<string, string> $imagePool
   *   Available image models, "provider:model" => label.
   * @param string|null $region
   *   An optional region id whose candidates are tried before the profile's.
   *
   * @return array<string, string>
   *   role id => "provider:model". Roles that resolve to nothing are OMITTED, not
   *   set to an empty string — an absent key means "leave this unbound".
   */
  public function apply(string $profileId, array $chatPool, array $imagePool, ?string $region = NULL): array {
    $document = $this->source->document();
    $profileRoles = $document['profiles'][$profileId]['roles'] ?? [];
    $regionRoles = $region !== NULL ? ($document['regions'][$region]['roles'] ?? []) : [];

    $out = [];
    foreach (self::rolePools() as $role => $which) {
      $pool = $which === 'image' ? $imagePool : $chatPool;
      if ($pool === []) {
        continue;
      }
      $candidates = [
        ...(is_array($regionRoles[$role] ?? NULL) ? $regionRoles[$role] : []),
        ...(is_array($profileRoles[$role] ?? NULL) ? $profileRoles[$role] : []),
      ];
      $picked = $this->pick($role, $candidates, $pool);
      if ($picked !== '') {
        $out[$role] = $picked;
      }
    }
    return $out;
  }

  /**
   * Resolve every profile at once ({@see self::apply()} per profile).
   *
   * The wizard precomputes all of them server-side so switching between Best
   * value / Balanced / Best quality is instant — a round-trip per click would
   * make the one control we're asking a beginner to use feel sluggish.
   *
   * @param array<string, string> $chatPool
   *   Available chat models, "provider:model" => label.
   * @param array<string, string> $imagePool
   *   Available image models, "provider:model" => label.
   * @param string|null $region
   *   An optional region id.
   *
   * @return array<string, array<string, string>>
   *   profile id => role id => "provider:model".
   */
  public function applyAll(array $chatPool, array $imagePool, ?string $region = NULL): array {
    $out = [];
    foreach ($this->profiles() as $profile) {
      $out[$profile['id']] = $this->apply($profile['id'], $chatPool, $imagePool, $region);
    }
    return $out;
  }

  /**
   * Every role that can be preset, and which pool it draws from.
   *
   * The three chat tiers ({@see ModelRoles::definitions()}) plus the two
   * capability roles that live outside that set: vision reads from the chat pool
   * (it is a chat call with an image attached), image from the image pool.
   *
   * @return array<string, string>
   *   role id => 'chat'|'image'.
   */
  public static function rolePools(): array {
    $pools = [];
    foreach (ModelRoles::ids() as $role) {
      $pools[$role] = 'chat';
    }
    $pools[ModelRoles::VISION] = 'chat';
    $pools[ModelRoles::IMAGE] = 'image';
    return $pools;
  }

  /**
   * Walk one role's candidates against its pool; '' when nothing resolves.
   *
   * @param string $role
   *   The role being resolved (only used for the tier-hint fallback).
   * @param list<mixed> $candidates
   *   Ordered "provider:model" candidates.
   * @param array<string, string> $pool
   *   Available models for the role, "provider:model" => label.
   */
  private function pick(string $role, array $candidates, array $pool): string {
    // Parse once: both passes below walk the same candidates.
    $parsed = [];
    foreach ($candidates as $candidate) {
      $candidate = trim((string) $candidate);
      [$providerId, $modelNeedle] = array_pad(explode(':', $candidate, 2), 2, '');
      if ($providerId === '' || $modelNeedle === '') {
        continue;
      }
      $parsed[] = [$candidate, $providerId, $modelNeedle];
    }

    // Pass 1 — each candidate against its OWN provider. Scoping matters: without
    // it a candidate could resolve to a same-named model on a different vendor,
    // which is never what the document meant.
    foreach ($parsed as [$candidate, $providerId, $modelNeedle]) {
      // Exact match — the common case, and the only one that is unambiguous.
      if (isset($pool[$candidate])) {
        return $candidate;
      }
      $match = $this->firstMatching($pool, $providerId, $modelNeedle);
      if ($match !== '') {
        return $match;
      }
    }

    // Pass 2 — the same candidates against any PROXY provider in the pool, whose
    // catalogue is these very models under a `vendor/model` id. A separate pass
    // rather than an inner loop, so the ordering rule is "every direct key first,
    // then the proxies" instead of "candidate 1 via a proxy beats candidate 2 on a
    // key the operator actually connected".
    foreach ($parsed as [, $providerId, $modelNeedle]) {
      foreach (self::PROXY_PROVIDERS as $proxy) {
        if ($proxy === $providerId) {
          // Already covered by pass 1 — the document named the proxy itself.
          continue;
        }
        $match = $this->firstMatching($pool, $proxy, $modelNeedle);
        if ($match !== '') {
          return $match;
        }
      }
    }

    // Nothing the document named is available. Fall back to the per-provider tier
    // hints, which is exactly what onboarding did before profiles existed — so a
    // provider the document is silent about is no worse off than it was.
    return $this->fromTierHints($role, $pool);
  }

  /**
   * The first pool entry for a provider whose model id matches a needle.
   *
   * @param array<string, string> $pool
   *   Available models, "provider:model" => label.
   * @param string $providerId
   *   The provider the match is scoped to.
   * @param string $needle
   *   A case-insensitive model id or family from the document.
   */
  private function firstMatching(array $pool, string $providerId, string $needle): string {
    $prefix = $providerId . ':';
    $needle = strtolower($needle);
    foreach (array_keys($pool) as $key) {
      if (!str_starts_with($key, $prefix)) {
        continue;
      }
      if ($this->matches(strtolower(substr($key, strlen($prefix))), $needle)) {
        return $key;
      }
    }
    return '';
  }

  /**
   * Whether a pool model id and a document needle name the same model family.
   *
   * Two directions, because the two sides drift the other way round:
   *
   *   forward — the POOL is more specific: the document says `claude-sonnet-5`,
   *     the vendor now returns `claude-sonnet-5-20260401`. A plain substring.
   *   reverse — the DOCUMENT is more specific: it names the dated snapshot
   *     `claude-haiku-4-5-20251001` and the catalogue serves the undated family id
   *     (which is what proxies like LiteLLM do). Guarded by a prefix requirement
   *     plus {@see self::FAMILY_MIN}, so a match means "the same family, less
   *     precisely named" and never "a shorter id that happens to share a prefix".
   *
   * A proxy id carries its vendor as a path segment (`anthropic/claude-opus-5`);
   * the reverse direction compares the LAST segment — the model name proper, which
   * is what the document's needle is written against. (Not the same split as
   * {@see ModelRoles::splitProxyModel()}, which wants the vendor and so takes the
   * first segment.)
   *
   * @param string $model
   *   The pool entry's model id, lower-cased.
   * @param string $needle
   *   The document's needle, lower-cased.
   */
  private function matches(string $model, string $needle): bool {
    if (str_contains($model, $needle)) {
      return TRUE;
    }
    $bare = str_contains($model, '/') ? substr($model, strrpos($model, '/') + 1) : $model;
    return strlen($bare) >= self::FAMILY_MIN && str_starts_with($needle, $bare);
  }

  /**
   * Walk {@see ModelRoles::tierHints()}, else fall back to the pool's first entry.
   *
   * Mirrors {@see ModelRoleResolver::suggestForProvider()}, but across ALL the
   * connected providers in the pool rather than one — which is the difference
   * between "you just connected Anthropic" and "here is everything you have".
   *
   * @param string $role
   *   The role whose tier hints to consult.
   * @param array<string, string> $pool
   *   Available models, "provider:model" => label.
   */
  private function fromTierHints(string $role, array $pool): string {
    $hints = ModelRoles::tierHints();
    foreach (array_keys($pool) as $key) {
      $providerId = explode(':', $key, 2)[0];
      foreach ($hints[$providerId][$role] ?? [] as $needle) {
        $match = $this->firstMatching($pool, $providerId, (string) $needle);
        if ($match !== '') {
          return $match;
        }
      }
    }
    return (string) (array_key_first($pool) ?? '');
  }

}
