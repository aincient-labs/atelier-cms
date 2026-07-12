<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Drush\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\aincient_core\ModelRoleResolver;
use Drupal\aincient_core\ModelRoles;
use Drupal\ai\AiProviderPluginManager;
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
    private readonly AiProviderPluginManager $providerManager,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('aincient_core.model_role_resolver'),
      $container->get('ai.provider'),
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
  #[CLI\Argument(name: 'provider', description: 'A drupal/ai provider plugin id (e.g. anthropic, openai, ollama).')]
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

    $definitions = $this->providerManager->getDefinitions();
    if (!isset($definitions[$provider])) {
      throw new \InvalidArgumentException(sprintf(
        'Unknown provider "%s". Installed providers: %s.',
        $provider,
        implode(', ', array_keys($definitions)) ?: '(none)',
      ));
    }

    // A best-effort warning if the model isn't in the provider's catalogue — we
    // still bind it (the provider may not be usable from the shell, or the model
    // list may be gated behind a credential), but flag the likely typo.
    try {
      $models = $this->providerManager->createInstance($provider)->getConfiguredModels('chat');
      if (!empty($models) && !isset($models[$model])) {
        $this->logger()->warning(dt('Model "@model" is not in @provider\'s current chat catalogue — binding it anyway.', [
          '@model' => $model,
          '@provider' => $provider,
        ]));
      }
    }
    catch (\Throwable) {
      // Provider not usable from here — bind without the catalogue check.
    }

    $this->resolver->bind($role, $provider, $model);
    $this->resolver->project();

    $this->logger()->success(dt('Bound role @role to @provider:@model and projected it.', [
      '@role' => $role,
      '@provider' => $provider,
      '@model' => $model,
    ]));
  }

}
