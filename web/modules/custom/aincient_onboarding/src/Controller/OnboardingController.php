<?php

declare(strict_types=1);

namespace Drupal\aincient_onboarding\Controller;

use Drupal\aincient_core\ModelPresetResolver;
use Drupal\aincient_core\ModelRoles;
use Drupal\aincient_core\RecommendationSource;
use Drupal\aincient_onboarding\ProfileApplier;
use Drupal\aincient_onboarding\ProviderCatalog;
use Drupal\aincient_onboarding\ProviderConnector;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Receives the chosen provider + credential from the onboarding wizard.
 *
 * Two endpoints, two halves of the handshake:
 * - {@see self::validate()} proves a credential works and returns the
 *   provider's chat models + a suggested model per AIncient role, WITHOUT
 *   persisting anything — so the wizard can render pre-filled per-role pickers.
 * - {@see self::save()} validates again and, on success, persists: it stores
 *   the credential, pins the default chat provider, and binds every model role.
 *
 * Both take an optional `endpoint` alongside the credential, for the providers
 * whose credential is a key AND a base URL (an OpenAI-compatible endpoint). It
 * is a separate field rather than a second meaning for `credential` because the
 * two are stored separately and only one of them is a secret.
 *
 * `save()` accepts `{ "provider": <id>, "credential": <key|url>, "model"?: …,
 * "roles"?: { reasoning|task|fast: <model id> } }` (the legacy in-chat panel
 * posts `{ "api_key": …, "model"? }` — still accepted). An invalid credential
 * stores nothing and leaves the site unconfigured, so the onboarding gate keeps
 * firing.
 */
final class OnboardingController extends ControllerBase {

  public function __construct(
    private readonly ProviderConnector $connector,
    private readonly ProviderCatalog $catalog,
    private readonly ModelPresetResolver $presets,
    private readonly RecommendationSource $recommendations,
    private readonly ProfileApplier $profiles,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('aincient_onboarding.provider_connector'),
      $container->get('aincient_onboarding.provider_catalog'),
      $container->get('aincient_core.model_preset_resolver'),
      $container->get('aincient_core.recommendation_source'),
      $container->get('aincient_onboarding.profile_applier'),
    );
  }

  /**
   * Validate a credential and return its models + per-role suggestions.
   *
   * Persists nothing — this is the wizard's "Connect" step, which then renders
   * the per-role model pickers from the returned data.
   */
  public function validate(Request $request): JsonResponse {
    $data = json_decode((string) $request->getContent(), TRUE);
    if (!is_array($data)) {
      return new JsonResponse(['ok' => FALSE, 'error' => 'Expected a JSON object.'], 400);
    }

    $provider = trim((string) ($data['provider'] ?? ''));
    $credential = trim((string) ($data['credential'] ?? $data['api_key'] ?? ''));
    if ($provider === '') {
      return new JsonResponse(['ok' => FALSE, 'error' => 'Choose a provider.'], 400);
    }

    $result = $this->connector->validate($provider, $credential, trim((string) ($data['endpoint'] ?? '')));
    if (!$result['ok']) {
      return new JsonResponse(['ok' => FALSE, 'error' => $result['message']], 422);
    }

    return new JsonResponse([
      'ok' => TRUE,
      'models' => $result['models'],
      'suggested' => $result['suggested'],
    ]);
  }

  /**
   * Connect one provider (or key group): validate + store, no role binding yet.
   *
   * The multi-connect wizard's per-provider step. Proves the credential works
   * and persists it (for a key group like Google, against every member at once),
   * then returns the provider's chat + image models and a suggested
   * `provider:model` per role — WITHOUT finalising. The wizard can call this for
   * several providers before {@see self::finalize()} binds roles across them.
   */
  public function connectProvider(Request $request): JsonResponse {
    $data = json_decode((string) $request->getContent(), TRUE);
    if (!is_array($data)) {
      return new JsonResponse(['ok' => FALSE, 'error' => 'Expected a JSON object.'], 400);
    }

    $provider = trim((string) ($data['provider'] ?? ''));
    $credential = trim((string) ($data['credential'] ?? $data['api_key'] ?? ''));
    if ($provider === '') {
      return new JsonResponse(['ok' => FALSE, 'error' => 'Choose a provider.'], 400);
    }

    // The second field, sent only by the `api_key_endpoint` shape. Absent for
    // every other provider, and ignored by the connector when it arrives anyway.
    $endpoint = trim((string) ($data['endpoint'] ?? ''));

    $result = $this->connector->connectAndStore($provider, $credential, $endpoint);
    if (!$result['ok']) {
      return new JsonResponse(['ok' => FALSE, 'error' => $result['message']], 422);
    }

    return new JsonResponse([
      'ok' => TRUE,
      'models' => $result['models'],
      'suggested' => $result['suggested'],
      // Recomputed across EVERY connected provider, not just this one — a preset
      // is a whole-site answer, and connecting a second provider can legitimately
      // move a role onto it. Without this the profile summary would go stale the
      // moment the operator connects anything.
      'presets' => $this->currentPresets(),
    ]);
  }

  /**
   * Fetch the published recommendations and recompute the presets.
   *
   * Wired to the wizard's (and the models form's) "Check for updates" affordance.
   * A failure is reported inline and changes nothing: the previously held
   * document — bundled or fetched — stays in force, so the operator is never left
   * worse off than before they clicked.
   */
  public function refreshRecommendations(): JsonResponse {
    try {
      $meta = $this->recommendations->refresh();
    }
    catch (\RuntimeException $e) {
      return new JsonResponse([
        'ok' => FALSE,
        'error' => $e->getMessage(),
        'meta' => $this->recommendations->meta(),
      ], 502);
    }

    // Auto mode's side of the bargain: the operator chose a tier, not a list of
    // models, so a new document is ours to act on rather than to ask about. Re-
    // resolve the stored tier and move the bindings with it — quietly. `changed`
    // carries what moved so the UI can report it after the fact. A Custom site
    // is left strictly alone.
    $reapplied = $this->profiles->reapplyStored();

    return new JsonResponse([
      'ok' => TRUE,
      'meta' => $meta,
      'profiles' => $this->presets->profiles(),
      'defaultProfile' => $this->presets->defaultProfile(),
      'presets' => $this->currentPresets(),
      'activeProfile' => $reapplied['profile'],
      'reapplied' => $reapplied['applied'],
      'changed' => $reapplied['changed'],
    ]);
  }

  /**
   * Disconnect a provider (or key group): remove its stored credential.
   *
   * The inverse of {@see self::connectProvider()}. Deletes the secret and unbinds
   * any role that pointed at it (see {@see ProviderConnector::disconnect()}), then
   * returns the refreshed provider rows so the Connect step can update its
   * connected badges without a reload.
   */
  public function disconnectProvider(Request $request): JsonResponse {
    $data = json_decode((string) $request->getContent(), TRUE);
    if (!is_array($data)) {
      return new JsonResponse(['ok' => FALSE, 'error' => 'Expected a JSON object.'], 400);
    }

    $provider = trim((string) ($data['provider'] ?? ''));
    if ($provider === '') {
      return new JsonResponse(['ok' => FALSE, 'error' => 'Choose a provider.'], 400);
    }

    $this->connector->disconnect($provider);

    return new JsonResponse([
      'ok' => TRUE,
      'providers' => $this->catalog->providers(),
    ]);
  }

  /**
   * Finalise onboarding: bind each role to a chosen provider:model, then finish.
   *
   * The wizard's last step. `roles` maps each AIncient role to a `provider:model`
   * string that may point at a DIFFERENT connected provider (chat on Anthropic,
   * image on Nano Banana). Credentials were already stored by
   * {@see self::connectProvider()}; this only binds + projects + flags complete.
   */
  public function finalize(Request $request): JsonResponse {
    $data = json_decode((string) $request->getContent(), TRUE);
    if (!is_array($data)) {
      return new JsonResponse(['ok' => FALSE, 'error' => 'Expected a JSON object.'], 400);
    }

    $bindings = $this->sanitizeRoleBindings($data['roles'] ?? NULL);
    if ($bindings === []) {
      return new JsonResponse(['ok' => FALSE, 'error' => 'Choose at least one model.'], 400);
    }

    // WHICH tier produced these bindings, or '' when the operator picked per
    // role. Without it the site can only ever describe itself as "Custom", and a
    // standing "keep me on balanced" has nothing to re-resolve when the
    // recommendations change. An unrecognised id is treated as Custom rather
    // than trusted — it would otherwise pin the site to a tier that cannot be
    // resolved again.
    $profile = trim((string) ($data['profile'] ?? ''));
    if ($profile !== '' && !$this->presets->hasProfile($profile)) {
      $profile = '';
    }

    $result = $this->connector->finalizeRoles(
      $bindings,
      $profile,
      $profile !== '' ? (string) ($this->recommendations->meta()['updated'] ?? '') : '',
    );
    if (!$result['ok']) {
      return new JsonResponse(['ok' => FALSE, 'error' => $result['message']], 422);
    }

    return new JsonResponse([
      'ok' => TRUE,
      'message' => $result['message'],
      'configured' => TRUE,
    ]);
  }

  /**
   * Validate and connect the chosen provider, binding each model role.
   */
  public function save(Request $request): JsonResponse {
    $data = json_decode((string) $request->getContent(), TRUE);
    if (!is_array($data)) {
      return new JsonResponse(['ok' => FALSE, 'error' => 'Expected a JSON object.'], 400);
    }

    // The legacy in-chat panel sends no provider; default it to whatever the
    // catalog recommends (empty on a neutral site). The wizard always sends one.
    $provider = trim((string) ($data['provider'] ?? ''));
    if ($provider === '') {
      $provider = $this->catalog->recommendedProviderId();
    }
    // `credential` is the wizard's field; `api_key` is the legacy panel's.
    $credential = trim((string) ($data['credential'] ?? $data['api_key'] ?? ''));
    $model = trim((string) ($data['model'] ?? ''));
    $roles = $this->sanitizeRoles($data['roles'] ?? NULL);

    if ($provider === '') {
      return new JsonResponse(['ok' => FALSE, 'error' => 'Choose a provider.'], 400);
    }

    $result = $this->connector->connect($provider, $credential, $model, $roles);
    if (!$result['ok']) {
      return new JsonResponse(['ok' => FALSE, 'error' => $result['message']], 422);
    }

    return new JsonResponse([
      'ok' => TRUE,
      'message' => $result['message'],
      'model' => $result['model'] ?? '',
      'configured' => TRUE,
    ]);
  }

  /**
   * Every profile resolved against what the site currently has connected.
   *
   * @return array<string, array<string, string>>
   *   profile id => role id => "provider:model".
   */
  private function currentPresets(): array {
    $catalog = $this->catalog->storedCatalog();
    return $this->presets->applyAll($catalog['chat'], $catalog['image']);
  }

  /**
   * Parse a role => "provider:model" map into validated {provider,model} pairs.
   *
   * The multi-connect finalise shape: each value is provider-qualified because a
   * role can bind to any connected provider. Unknown roles, empty values, and
   * values without a "provider:model" shape are dropped.
   *
   * @return array<string, array{provider_id: string, model_id: string}>
   */
  private function sanitizeRoleBindings(mixed $roles): array {
    if (!is_array($roles)) {
      return [];
    }
    $out = [];
    foreach ($roles as $role => $value) {
      if (!is_string($role) || !ModelRoles::isRole($role)) {
        continue;
      }
      $value = trim((string) $value);
      if ($value === '' || !str_contains($value, ':')) {
        continue;
      }
      [$provider, $model] = explode(':', $value, 2);
      $provider = trim($provider);
      $model = trim($model);
      if ($provider !== '' && $model !== '') {
        $out[$role] = ['provider_id' => $provider, 'model_id' => $model];
      }
    }
    return $out;
  }

  /**
   * Keep only known role ids mapped to non-empty string model ids.
   *
   * @return array<string, string>
   */
  private function sanitizeRoles(mixed $roles): array {
    if (!is_array($roles)) {
      return [];
    }
    $out = [];
    foreach ($roles as $role => $model) {
      if (is_string($role) && ModelRoles::isRole($role)) {
        $model = trim((string) $model);
        if ($model !== '') {
          $out[$role] = $model;
        }
      }
    }
    return $out;
  }

}
