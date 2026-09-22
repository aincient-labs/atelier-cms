<?php

declare(strict_types=1);

namespace Drupal\aincient_onboarding;

use Drupal\aincient_core\ModelPresetResolver;
use Drupal\aincient_core\ModelRecommendations;
use Drupal\aincient_core\ModelRoleResolver;
use Drupal\aincient_core\ModelRoles;
use Drupal\aincient_core\RecommendationSource;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Psr\Log\LoggerInterface;

/**
 * Builds the setup screen's (onboarding wizard's) settings payload.
 *
 * WHY THIS IS A SERVICE AND NOT AN ARRAY LITERAL IN THE HOOK
 * ---------------------------------------------------------
 * The payload used to be one expression computed inline inside
 * hook_aincient_console_settings_alter(), which runs while the console page is
 * being rendered. Most of its parts read STORED, upgraded-over state — a
 * catalogue enumerated from keys stored by an older release, a recommendations
 * document, a profile config — and each of those can legitimately be a shape
 * this release no longer understands. Any one throw took the WHOLE console page
 * down with "The website encountered an unexpected error" (atelier-cms #28: a
 * site upgraded from 0.8.0 with OpenAI already connected could not open
 * `/atelier?onboarding=1` at all).
 *
 * So every part is now computed behind {@see self::part()}: a failure is logged
 * with its exception, the part falls back to the empty shape the wizard already
 * tolerates, and the screen still renders. The payload says so honestly —
 * `degraded` plus the list of `unavailable` part keys — so the wizard can tell
 * the operator that saved model data could not be read instead of silently
 * showing them an empty picker. Degrading is not the same as hiding.
 */
final class WizardPayload {

  public function __construct(
    private readonly ProviderCatalog $catalog,
    private readonly ModelPresetResolver $presets,
    private readonly RecommendationSource $recommendationSource,
    private readonly ModelRoleResolver $roleResolver,
    private readonly ModelRecommendations $recommendations,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly AccountInterface $currentUser,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * The part keys that could not be built during the last build() call.
   *
   * @var list<string>
   */
  private array $unavailable = [];

  /**
   * Build the `onboarding` settings block for a run that shows the wizard.
   *
   * @param bool $forced
   *   TRUE when `?onboarding=1` re-opened the wizard on a configured site (a
   *   re-run: skippable + pre-filled), FALSE on a genuine first run.
   * @param bool $isAdmin
   *   Whether this user may configure the site.
   *
   * @return array<string, mixed>
   *   The payload. Never throws: unreadable parts degrade to their empty shape.
   */
  public function build(bool $forced, bool $isAdmin): array {
    $this->unavailable = [];

    // Enumerate the model catalogue from stored keys ONCE, then derive labels
    // and presets from it (avoids probing every provider twice). The derived
    // parts take whatever the catalogue part produced — including its fallback,
    // which is why they can still be attempted after it failed.
    $providers = $this->part('providers', fn (): array => $this->catalog->providers(), []);
    $storedCatalog = $this->part(
      'catalog',
      fn (): array => $this->catalog->storedCatalog($providers),
      ['chat' => [], 'image' => []],
    );
    $storedCatalog += ['chat' => [], 'image' => []];

    return [
      'needed' => TRUE,
      // A re-run (vs genuine first-run) is skippable + pre-filled, and its
      // finalize must never clobber what an earlier run earned (Law 14).
      'forced' => $forced,
      'canReenter' => $isAdmin,
      // The existing role bindings as `{role: "provider:model"}` — the wizard
      // opens the models step on these instead of blank, so a no-op re-run
      // re-binds the same models rather than wiping them.
      'current' => $this->part('current', fn (): array => $this->currentBindings(), []),
      // The multi-connect handshake: connect one provider (or key group) at a
      // time, then finalise role bindings across all of them.
      'connectProviderUrl' => Url::fromRoute('aincient_onboarding.connect_provider')->toString(),
      'finalizeUrl' => Url::fromRoute('aincient_onboarding.finalize')->toString(),
      // Remove a provider's stored credential (the Connect step's Disconnect).
      'disconnectUrl' => Url::fromRoute('aincient_onboarding.disconnect_provider')->toString(),
      // Legacy single-provider endpoints, kept for the in-chat onboarding panel.
      'validateUrl' => Url::fromRoute('aincient_onboarding.validate')->toString(),
      'saveUrl' => Url::fromRoute('aincient_onboarding.save')->toString(),
      // Each provider carries its auth shape (api_key vs host URL) and its
      // capabilities (chat / image) so the connect step renders the right field
      // and badges. Key groups (e.g. Google's gemini + nanobanana) appear as ONE
      // row. Models are fetched per provider by the connect step — they can't be
      // known before a credential validates.
      'providers' => $providers,
      'recommended' => $this->part('recommended', fn (): string => $this->catalog->recommendedProviderId(), ''),
      // The model catalogue enumerated from STORED keys (chat + image, each
      // "provider:model" => label), so the models step opens fully populated on
      // load — independent of which providers were (re)connected this session.
      // This is what decouples "Choose your models" from "Connect your AI".
      'catalog' => $storedCatalog,
      // Curated quality label per available model ("provider:model" =>
      // recommended|tested|not-recommended). Untested models are omitted — the
      // picker defaults anything absent to "untested".
      'modelLabels' => $this->part('modelLabels', fn (): array => $this->modelLabels($storedCatalog), []),
      // The curated PROFILES — "what are you optimising for?" — and, for each,
      // the model every role resolves to given what's actually connected.
      // Precomputed server-side so the wizard's one beginner-facing control
      // switches instantly. The five per-role pickers are still there, behind a
      // disclosure; this just means a beginner no longer has to open them.
      'profiles' => $this->part('profiles', fn (): array => $this->presets->profiles(), []),
      'defaultProfile' => $this->part('defaultProfile', fn (): string => $this->presets->defaultProfile(), ''),
      // The tier ACTUALLY in force, and the recommendations date it resolved
      // against — '' means Custom (the operator picked per role and owns them).
      'activeProfile' => $this->part('activeProfile', fn (): string => $this->roleResolver->profile(), ''),
      'activeProfileUpdated' => $this->part('activeProfileUpdated', fn (): string => $this->roleResolver->profileUpdated(), ''),
      'presets' => $this->part(
        'presets',
        fn (): array => $this->presets->applyAll($storedCatalog['chat'], $storedCatalog['image']),
        [],
      ),
      // Where the suggestions came from + when, so the wizard can offer "Check
      // for updates" honestly. The fetch is an explicit click, never automatic.
      'recommendationsMeta' => $this->part(
        'recommendationsMeta',
        fn (): array => $this->recommendationSource->meta(),
        ['updated' => '', 'source' => 'bundled', 'fetchedAt' => NULL, 'url' => ''],
      ),
      'refreshRecommendationsUrl' => Url::fromRoute('aincient_onboarding.refresh_recommendations')->toString(),
      // Whether this site narrows what the tiers may pick
      // (`aincient_core.model_preferences`). A tier that has been quietly
      // reshaped is a trap: the operator sees "Balanced" produce something our
      // own documentation doesn't describe and has nothing to attribute it to. A
      // boolean is enough — the wizard only needs to say that a local rule is in
      // play and where it lives, not to render or edit it.
      'preferencesDeclared' => $this->part('preferencesDeclared', fn (): bool => $this->preferencesDeclared(), FALSE),
      // The AIncient model roles (id/label/description/pool), in display order —
      // the taxonomy behind the per-role model pickers the wizard shows once
      // providers are connected. Chat tiers + Vision draw from the chat pool;
      // Image draws from the image pool.
      'roles' => $this->part('roles', fn (): array => $this->roleTaxonomy(), []),
      // Connecting AI is a site-wide admin action; non-admins see guidance only.
      'canConfigure' => $this->currentUser->hasPermission('administer site configuration'),
      // Honesty about what the screen could not read. The wizard renders one
      // notice; every empty picker below it then has an attributable cause.
      'degraded' => $this->unavailable !== [],
      'unavailable' => $this->unavailable,
    ];
  }

  /**
   * Compute one part of the payload, or degrade to its empty shape.
   *
   * @param string $key
   *   The payload key, as the wizard names it — what `unavailable` reports.
   * @param callable $fn
   *   Produces the part.
   * @param mixed $fallback
   *   The empty shape the wizard already tolerates for this key.
   *
   * @return mixed
   *   The part, or $fallback when producing it threw.
   */
  private function part(string $key, callable $fn, mixed $fallback): mixed {
    try {
      return $fn();
    }
    catch (\Throwable $e) {
      $this->unavailable[] = $key;
      $this->logger->error('Setup screen: part @part could not be built: @class: @message', [
        '@part' => $key,
        '@class' => $e::class,
        '@message' => $e->getMessage(),
      ]);
      return $fallback;
    }
  }

  /**
   * The site's current role bindings as a `{role: "provider:model"}` map.
   *
   * Reads the raw `aincient_core.model_roles:roles` config (all roles — chat
   * tiers, vision, image — not just the three definitions
   * ModelRoleResolver::roles() returns), and emits the wizard's `roleModels`
   * value format ("provider:model") for every role that has BOTH a provider and
   * a model bound. Roles bound to nothing are omitted, so the wizard leaves them
   * for the suggestion-seeding to fill. This is what makes a re-run pre-filled +
   * idempotent (Law 14).
   *
   * @return array<string, string>
   */
  private function currentBindings(): array {
    $roles = $this->configFactory->get('aincient_core.model_roles')->get('roles') ?? [];
    $out = [];
    foreach ($roles as $role => $binding) {
      $provider = trim((string) ($binding['provider_id'] ?? ''));
      $model = trim((string) ($binding['model_id'] ?? ''));
      if ($provider !== '' && $model !== '') {
        $out[$role] = $provider . ':' . $model;
      }
    }
    return $out;
  }

  /**
   * Whether this site has declared any model preference of its own.
   *
   * True as soon as either list has an entry — we don't try to work out whether
   * a declaration actually changed a pick, because the honest statement to the
   * operator is "a local rule is in play here", not "a local rule altered
   * exactly this row".
   */
  private function preferencesDeclared(): bool {
    $preferences = $this->configFactory->get('aincient_core.model_preferences');
    if (array_filter((array) $preferences->get('avoid') ?: []) !== []) {
      return TRUE;
    }
    foreach ((array) $preferences->get('prefer') ?: [] as $patterns) {
      if (array_filter((array) $patterns) !== []) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * The model-role taxonomy as a display-ordered list for the wizard.
   *
   * Shape-shifting only: the five rows themselves are
   * {@see ModelRoles::pickerDefinitions()}, because the rate sheet
   * (/admin/config/aincient/pricing) shows the SAME five and the operator is
   * meant to read one against the other. This wizard wants a JSON list with the
   * id inlined; the definition is a keyed map. That is the whole difference, and
   * it is the only thing this method is still allowed to know.
   *
   * @return list<array{id: string, label: string, description: string, pool: string, optional: bool}>
   */
  private function roleTaxonomy(): array {
    $roles = [];
    foreach (ModelRoles::pickerDefinitions() as $id => $def) {
      $roles[] = ['id' => $id] + $def;
    }
    return $roles;
  }

  /**
   * A curated quality label per available "provider:model" (untested omitted).
   *
   * Joins the stored catalogue against the recommendation registry so the picker
   * can chip + rank each option. Only non-"untested" models are emitted — the
   * frontend defaults anything absent to "untested", keeping the payload small.
   *
   * @param array{chat: array<string, string>, image: array<string, string>} $catalog
   *   The merged stored catalogue.
   *
   * @return array<string, string>
   *   "provider:model" => recommended|tested|not-recommended.
   */
  private function modelLabels(array $catalog): array {
    $out = [];
    foreach ([...array_keys($catalog['chat']), ...array_keys($catalog['image'])] as $value) {
      if (isset($out[$value]) || !str_contains((string) $value, ':')) {
        continue;
      }
      [$provider, $model] = explode(':', (string) $value, 2);
      $label = $this->recommendations->labelForModel($provider, $model);
      if ($label !== ModelRecommendations::UNTESTED) {
        $out[$value] = $label;
      }
    }
    return $out;
  }

}
