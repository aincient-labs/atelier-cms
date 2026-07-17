<?php

declare(strict_types=1);

namespace Drupal\aincient_onboarding\Controller;

use Drupal\aincient_core\ModelRoles;
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
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('aincient_onboarding.provider_connector'),
      $container->get('aincient_onboarding.provider_catalog'),
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

    $result = $this->connector->validate($provider, $credential);
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

    $result = $this->connector->connectAndStore($provider, $credential);
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

    $result = $this->connector->finalizeRoles($bindings);
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
