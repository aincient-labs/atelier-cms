<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Drush\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\aincient_core\Inference\ProviderInventory;
use Drupal\aincient_core\ModelPresetResolver;
use Drupal\aincient_core\ModelRoleResolver;
use Drupal\aincient_core\ModelRoles;
use Drupal\aincient_core\RecommendationSource;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush commands for the AIncient model roles.
 *
 * The headless seam onto {@see ModelRoleResolver}: it lets an operator inspect
 * and rebind roles from the shell, and is what the `aincient` manager CLI shells
 * into (`docker compose exec app drush aincient:model-set …`). Same source of
 * truth as the onboarding pickers and the console form.
 */
final class ModelRolesCommands extends DrushCommands {

  public function __construct(
    private readonly ModelRoleResolver $resolver,
    private readonly ProviderInventory $providerManager,
    private readonly ModelPresetResolver $presets,
    private readonly RecommendationSource $recommendations,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('aincient_core.model_role_resolver'),
      $container->get('aincient_core.inference.provider_inventory'),
      $container->get('aincient_core.model_preset_resolver'),
      $container->get('aincient_core.recommendation_source'),
    );
  }

  /**
   * List the AIncient model roles and their current bindings.
   */
  #[CLI\Command(name: 'aincient:model-list', aliases: ['aml'])]
  #[CLI\FieldLabels(labels: [
    'role' => 'Role',
    'label' => 'Label',
    'provider' => 'Provider',
    'model' => 'Model',
    'default' => 'Default',
  ])]
  #[CLI\DefaultTableFields(fields: ['role', 'label', 'provider', 'model', 'default'])]
  #[CLI\Usage(name: 'drush aincient:model-list --format=json', description: 'Emit the bindings as JSON (what the manager reads).')]
  public function list(): RowsOfFields {
    $rows = [];
    foreach ($this->resolver->roles() as $id => $role) {
      $rows[] = [
        'role' => $id,
        'label' => $role['label'],
        'provider' => $role['provider_id'],
        'model' => $role['model_id'],
        'default' => $role['is_default'] ? 'yes' : '',
      ];
    }
    return new RowsOfFields($rows);
  }

  /**
   * Bind an AIncient model role to a provider and model, then project it.
   */
  #[CLI\Command(name: 'aincient:model-set', aliases: ['ams'])]
  #[CLI\Argument(name: 'role', description: 'The role id: reasoning, task, or fast.')]
  #[CLI\Argument(name: 'provider', description: 'A provider id this site can serve (e.g. anthropic, gemini, nanobanana, openai_compatible).')]
  #[CLI\Argument(name: 'model', description: 'A model id offered by that provider.')]
  #[CLI\Usage(name: 'drush aincient:model-set reasoning anthropic claude-opus-4-8', description: 'Point the reasoning role at Claude Opus.')]
  public function set(string $role, string $provider, string $model): void {
    if (!ModelRoles::isRole($role)) {
      throw new \InvalidArgumentException(sprintf(
        'Unknown role "%s". Known roles: %s.',
        $role,
        implode(', ', ModelRoles::ids()),
      ));
    }
    $provider = trim($provider);
    $model = trim($model);
    if ($provider === '' || $model === '') {
      throw new \InvalidArgumentException('Both a provider and a model are required.');
    }

    // Refusing an unservable provider here is the point, not a formality: this
    // command is what the manager CLI and every scripted install bind through, so
    // it is where a typo — or a provider id that was valid before the inference
    // layer moved to its own adapters — has to stop. The alternative is a site
    // that reports success and then cannot answer a single turn.
    if (!$this->providerManager->has($provider)) {
      throw new \InvalidArgumentException(sprintf(
        'Unknown provider "%s". This site can serve: %s.',
        $provider,
        implode(', ', array_keys($this->providerManager->providers())) ?: '(none)',
      ));
    }

    // A best-effort warning if the model isn't in the provider's catalogue — we
    // still bind it (the provider may not be connected yet, so the catalogue can
    // legitimately be empty), but flag the likely typo.
    $models = $this->providerManager->models($provider, ProviderInventory::CHAT);
    if (!empty($models) && !isset($models[$model])) {
      $this->logger()->warning(dt('Model "@model" is not in @provider\'s current chat catalogue — binding it anyway.', [
        '@model' => $model,
        '@provider' => $provider,
      ]));
    }

    $this->resolver->bind($role, $provider, $model);
    $this->resolver->project();

    $this->logger()->success(dt('Bound role @role to @provider:@model and projected it.', [
      '@role' => $role,
      '@provider' => $provider,
      '@model' => $model,
    ]));
  }

  /**
   * Bind every role from a curated preset, then project.
   *
   * The headless twin of the wizard's Best value / Balanced / Best quality
   * control. Resolves the profile against the models each connected provider
   * actually offers, so it is safe to run on any install without knowing what is
   * connected — which is exactly what a scripted or demo install needs instead of
   * hard-coding a model id per role.
   */
  #[CLI\Command(name: 'aincient:models-apply', aliases: ['ama'])]
  #[CLI\Argument(name: 'profile', description: 'A profile id from the recommendation document (e.g. value, balanced, quality). Omit to list them.')]
  #[CLI\Option(name: 'region', description: 'An optional region id whose candidates are tried first.')]
  #[CLI\Option(name: 'dry-run', description: 'Show what would be bound without writing anything.')]
  #[CLI\Usage(name: 'drush aincient:models-apply balanced', description: 'Bind every role from the Balanced preset.')]
  #[CLI\Usage(name: 'drush aincient:models-apply quality --dry-run', description: 'Preview the Best quality preset.')]
  public function apply(string $profile = '', array $options = ['region' => NULL, 'dry-run' => FALSE]): void {
    $available = array_column($this->presets->profiles(), 'id');
    if ($available === []) {
      throw new \RuntimeException('No presets available. Run drush aincient:models-refresh, or check the bundled model-recommendations.yml.');
    }
    if ($profile === '') {
      $this->io()->writeln('Available presets: ' . implode(', ', $available));
      return;
    }
    if (!$this->presets->hasProfile($profile)) {
      throw new \InvalidArgumentException(sprintf('Unknown preset "%s". Available: %s.', $profile, implode(', ', $available)));
    }

    $picked = $this->presets->apply(
      $profile,
      $this->pool(ProviderInventory::CHAT),
      $this->pool(ProviderInventory::IMAGE),
      $options['region'] !== NULL ? (string) $options['region'] : NULL,
    );
    if ($picked === []) {
      throw new \RuntimeException('No connected provider offers a model for any role — connect a provider first.');
    }

    foreach ($picked as $role => $value) {
      $this->io()->writeln(sprintf('%-10s %s', $role, $value));
    }
    if (!empty($options['dry-run'])) {
      $this->logger()->notice(dt('Dry run — nothing was bound.'));
      return;
    }

    foreach ($picked as $role => $value) {
      [$providerId, $modelId] = explode(':', $value, 2);
      $this->resolver->bind($role, $providerId, $modelId);
    }
    $this->resolver->project();

    $this->logger()->success(dt('Applied preset @profile to @count role(s) and projected them.', [
      '@profile' => $profile,
      '@count' => count($picked),
    ]));
  }

  /**
   * Fetch the latest curated recommendations from aincient-labs.com.
   *
   * Explicit by design — nothing fetches this on install, on cron, or in the
   * background. Failure leaves the document already in force untouched.
   */
  #[CLI\Command(name: 'aincient:models-refresh', aliases: ['amr'])]
  #[CLI\Usage(name: 'drush aincient:models-refresh', description: 'Fetch the current model recommendations.')]
  public function refresh(): void {
    $meta = $this->recommendations->refresh();
    $this->logger()->success(dt('Recommendations updated (@date) from @url.', [
      '@date' => $meta['updated'],
      '@url' => $meta['url'],
    ]));
  }

  /**
   * Every model a servable provider offers for a capability.
   *
   * A provider that cannot enumerate (no credential, unreachable host) is simply
   * not part of the pool — the inventory answers [] for it, so there is nothing
   * to catch here any more.
   *
   * @param string $capability
   *   {@see ProviderInventory::CHAT} or {@see ProviderInventory::IMAGE}.
   *
   * @return array<string, string>
   *   "provider:model" => label.
   */
  private function pool(string $capability): array {
    $pool = [];
    foreach (array_keys($this->providerManager->providersWith($capability)) as $providerId) {
      foreach ($this->providerManager->models($providerId, $capability) as $modelId => $label) {
        $pool[$providerId . ':' . $modelId] = (string) $label;
      }
    }
    return $pool;
  }

}
