<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Form;

use Drupal\aincient_core\ModelRoleResolver;
use Drupal\aincient_core\ModelRoles;
use Drupal\ai\AiProviderPluginManager;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Console settings for the AIncient model roles.
 *
 * Binds each semantic role ({@see ModelRoles}) to a concrete `provider:model`
 * sourced from the installed, usable chat providers, then projects the bindings
 * onto drupal/ai's operation-type defaults so stock FlowDrop inherits them
 * ({@see ModelRoleResolver::project()}). This is the in-Drupal twin of the
 * onboarding pickers and the `drush aincient:model-set` command — all three
 * write the same source of truth.
 */
final class ModelRolesForm extends ConfigFormBase {

  private const SETTINGS = 'aincient_core.model_roles';

  /**
   * Providers whose models are only offered once the operator curates them.
   *
   * Aggregators like OpenRouter proxy hundreds of upstream models and return
   * the *entire* catalog from getConfiguredModels() — and, worse, return that
   * same text-model catalog for `text_to_image` (they don't filter by modality),
   * so an uncurated OpenRouter dumps ~340 non-image models into the image pool.
   * We therefore hide such a provider from the role selects until its curation
   * shortlist ({@see self::isCurated()}) is non-empty. Keyed by provider id →
   * the config that holds the shortlist + the route to curate it.
   *
   * @var array<string, array{config: string, key: string, route: string}>
   */
  private const CURATED_AGGREGATORS = [
    'openrouter' => [
      'config' => 'ai_provider_openrouter.settings',
      'key' => 'enabled_models',
      'route' => 'ai_provider_openrouter.settings',
    ],
  ];

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    private readonly ModelRoleResolver $resolver,
    private readonly AiProviderPluginManager $providerManager,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('aincient_core.model_role_resolver'),
      $container->get('ai.provider'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'aincient_core_model_roles';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [self::SETTINGS];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $roles = $this->resolver->roles();
    // Aggregators skipped for lack of a curated shortlist, collected across
    // both pools so the form can prompt the operator to curate them.
    $uncurated = [];
    $options = $this->groupedOptions('chat', $uncurated);
    // Make sure each current binding is selectable even if its provider is no
    // longer usable (so the form never silently drops a saved choice).
    foreach ($roles as $role) {
      $value = $this->bindingValue($role['provider_id'], $role['model_id']);
      if ($value !== '' && !$this->optionExists($options, $value)) {
        $options[(string) $this->t('Current')][$value] = $value;
      }
    }

    // The image role lives outside the chat-role taxonomy (see ModelRoles::IMAGE)
    // and binds to an *image* provider, so its options come from a separate
    // operation-type pool.
    $imageOptions = $this->groupedOptions('text_to_image', $uncurated);
    $imageBinding = $this->resolver->imageBinding();
    $imageValue = $imageBinding !== NULL
      ? $this->bindingValue($imageBinding['provider_id'], $imageBinding['model_id'])
      : '';
    if ($imageValue !== '' && !$this->optionExists($imageOptions, $imageValue)) {
      $imageOptions[(string) $this->t('Current')][$imageValue] = $imageValue;
    }

    // Vision draws from the CHAT pool (image→text is a chat call with the image
    // attached), so it reuses $options. Its readback is the EXPLICIT binding —
    // empty means "use the default chat model" (resolve() falls back).
    $visionBinding = $this->resolver->visionBinding();
    $visionValue = $visionBinding !== NULL
      ? $this->bindingValue($visionBinding['provider_id'], $visionBinding['model_id'])
      : '';
    if ($visionValue !== '' && !$this->optionExists($options, $visionValue)) {
      $options[(string) $this->t('Current')][$visionValue] = $visionValue;
    }

    if ($this->flatCount($options) === 0 && $this->flatCount($imageOptions) === 0) {
      // Distinguish "nothing connected" from "connected but uncurated" — the
      // latter just needs the operator to pick a shortlist.
      $form['none'] = $uncurated !== [] ? $this->curationHint($uncurated) : [
        '#type' => 'item',
        '#markup' => $this->t('No usable AI providers yet. Connect a provider through onboarding (or configure one under AI settings) and its models will appear here.'),
      ];
      return parent::buildForm($form, $form_state);
    }

    $form['intro'] = [
      '#type' => 'item',
      '#markup' => $this->t('AIncient speaks in <em>roles</em>, not vendor model ids. Bind each role to a model from any connected provider; the choices are projected onto the framework so every assistant flow inherits them.'),
    ];

    $form['curation_hint'] = $this->curationHint($uncurated);

    if ($this->flatCount($options) > 0) {
      $form['default_role'] = [
        '#type' => 'select',
        '#title' => $this->t('Default role'),
        '#options' => $this->roleLabels(),
        '#default_value' => $this->resolver->defaultRole(),
        '#required' => TRUE,
        '#description' => $this->t('The role the chat console (and stock FlowDrop chat nodes) inherit by default.'),
      ];

      $form['roles'] = [
        '#type' => 'details',
        '#title' => $this->t('Chat roles'),
        '#open' => TRUE,
        '#tree' => TRUE,
      ];
      foreach ($roles as $id => $role) {
        $form['roles'][$id] = [
          '#type' => 'select',
          '#title' => $role['label'],
          '#description' => $role['description'],
          '#options' => ['' => $this->t('- Not set -')] + $options,
          '#default_value' => $this->bindingValue($role['provider_id'], $role['model_id']),
        ];
      }
    }

    // Image generation — the gate for the Media studio's AI rail. Bound
    // independently of the Drupal AI operation-type defaults: the Media studio
    // resolves it only through the explicit binding, never the op-default.
    $form['image'] = [
      '#type' => 'details',
      '#title' => $this->t('Image generation'),
      '#open' => TRUE,
      '#description' => $this->t('Bind an image model to turn on the Media studio\'s AI rail (text→image and image→image). Leave unset to keep the Media studio non-AI editor only.'),
    ];
    if ($this->flatCount($imageOptions) === 0) {
      $form['image']['image_none'] = [
        '#type' => 'item',
        '#markup' => $this->t('No usable image providers yet. Connect one that advertises image generation (e.g. Nano Banana / Gemini) and its models will appear here.'),
      ];
    }
    else {
      $form['image']['image_model'] = [
        '#type' => 'select',
        '#title' => $this->t('Image model'),
        '#options' => ['' => $this->t('- Not set (AI rail off) -')] + $imageOptions,
        '#default_value' => $imageValue,
      ];
    }

    // Image description (alt text) — image→text. Bound to a vision-capable chat
    // model, not an image provider (there is no image_to_text op type; "seeing"
    // an image is a chat call with the image attached). Unset falls back to the
    // default chat model, so alt-text works even with no explicit pick.
    if ($this->flatCount($options) > 0) {
      $form['vision'] = [
        '#type' => 'details',
        '#title' => $this->t('Image description (alt text)'),
        '#open' => TRUE,
        '#description' => $this->t('The model that describes images — the Media studio uses it to write alt text. Pick a <em>vision-capable</em> chat model (Gemini, GPT-4o, Claude, …). Leave unset to use the default chat model.'),
      ];
      $form['vision']['vision_model'] = [
        '#type' => 'select',
        '#title' => $this->t('Vision model'),
        '#options' => ['' => $this->t('- Use default chat model -')] + $options,
        '#default_value' => $visionValue,
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Only touch the chat-role bindings when the chat section actually rendered
    // (an image-only site never shows it — don't clear bindings that weren't on
    // the form).
    if (isset($form['roles'])) {
      foreach (ModelRoles::ids() as $role) {
        $value = (string) $form_state->getValue(['roles', $role], '');
        [$provider, $model] = $this->splitBinding($value);
        $this->resolver->bind($role, $provider, $model);
      }

      $default = (string) $form_state->getValue('default_role');
      if (ModelRoles::isRole($default)) {
        $this->config(self::SETTINGS)->set('default_role', $default)->save();
      }
    }

    // The image role, bound from its own section when image providers exist.
    if (isset($form['image']['image_model'])) {
      [$provider, $model] = $this->splitBinding((string) $form_state->getValue('image_model', ''));
      $this->resolver->bind(ModelRoles::IMAGE, $provider, $model);
    }

    // The vision role, bound from its own section (chat-pool models).
    if (isset($form['vision']['vision_model'])) {
      [$provider, $model] = $this->splitBinding((string) $form_state->getValue('vision_model', ''));
      $this->resolver->bind(ModelRoles::VISION, $provider, $model);
    }

    // One projection after all bindings are written.
    $this->resolver->project();

    parent::submitForm($form, $form_state);
  }

  /**
   * Grouped `provider:model` options for a role select (optgroup per provider).
   *
   * The single source for every pool: the chat roles + vision draw from `chat`,
   * the image role from `text_to_image`. Uncurated aggregators
   * ({@see self::CURATED_AGGREGATORS}) are skipped so the selects stay a
   * hand-picked set instead of an unusable hundreds-of-models dump; each one
   * skipped is recorded in $uncurated so the form can prompt the operator to
   * curate it.
   *
   * @param string $operationType
   *   The AI operation type ('chat' or 'text_to_image').
   * @param array<string, array{label: string, config: string, key: string, route: string}> $uncurated
   *   Collects the aggregators that were skipped for lack of a shortlist,
   *   keyed by provider id (deduped across pools).
   *
   * @return array<string, array<string, string>>
   *   provider label => ("provider_id:model_id" => model label).
   */
  private function groupedOptions(string $operationType, array &$uncurated = []): array {
    $grouped = [];
    foreach ($this->providerManager->getProvidersForOperationType($operationType, FALSE) as $id => $definition) {
      $label = (string) ($definition['label'] ?? $id);
      // Hide a noisy aggregator until the operator has curated a shortlist —
      // otherwise it floods the select with its entire upstream catalog.
      if (isset(self::CURATED_AGGREGATORS[$id]) && !$this->isCurated($id)) {
        $uncurated[$id] = ['label' => $label] + self::CURATED_AGGREGATORS[$id];
        continue;
      }
      try {
        $models = $this->providerManager->createInstance($id)->getConfiguredModels($operationType);
      }
      catch (\Throwable) {
        $models = [];
      }
      if (empty($models)) {
        continue;
      }
      foreach ($models as $modelId => $modelLabel) {
        $grouped[$label][$id . ':' . $modelId] = (string) $modelLabel;
      }
    }
    ksort($grouped);
    return $grouped;
  }

  /**
   * Whether a curated-aggregator provider has a non-empty shortlist.
   *
   * Non-aggregator providers are always "curated" (they ship a sane vendor
   * list). An aggregator counts as curated once its configured shortlist holds
   * at least one model id.
   */
  private function isCurated(string $providerId): bool {
    $spec = self::CURATED_AGGREGATORS[$providerId] ?? NULL;
    if ($spec === NULL) {
      return TRUE;
    }
    $list = $this->configFactory()->get($spec['config'])->get($spec['key']) ?? [];
    return (bool) array_filter((array) $list);
  }

  /**
   * A hint render element pointing at each uncurated aggregator's settings.
   *
   * @param array<string, array{label: string, config: string, key: string, route: string}> $uncurated
   *   The aggregators skipped by {@see self::groupedOptions()}.
   *
   * @return array<string, mixed>
   *   A render array (empty when nothing was skipped).
   */
  private function curationHint(array $uncurated): array {
    if ($uncurated === []) {
      return [];
    }
    $items = [];
    foreach ($uncurated as $info) {
      $items[] = Link::fromTextAndUrl(
        $this->t('Choose @provider models', ['@provider' => $info['label']]),
        Url::fromRoute($info['route']),
      )->toRenderable();
    }
    return [
      '#type' => 'item',
      '#markup' => $this->t('Some providers proxy hundreds of upstream models and stay hidden here until you pick a shortlist:'),
      'links' => [
        '#theme' => 'item_list',
        '#items' => $items,
      ],
    ];
  }

  /**
   * Role id => label, for the default-role select.
   *
   * @return array<string, string>
   */
  private function roleLabels(): array {
    $labels = [];
    foreach (ModelRoles::definitions() as $id => $def) {
      $labels[$id] = $def['label'];
    }
    return $labels;
  }

  /**
   * The "provider:model" select value for a binding ('' when unbound).
   */
  private function bindingValue(string $providerId, string $modelId): string {
    return ($providerId !== '' && $modelId !== '') ? $providerId . ':' . $modelId : '';
  }

  /**
   * Split a "provider:model" select value into [provider, model].
   *
   * @return array{0: string, 1: string}
   */
  private function splitBinding(string $value): array {
    if ($value === '' || !str_contains($value, ':')) {
      return ['', ''];
    }
    [$provider, $model] = explode(':', $value, 2);
    return [trim($provider), trim($model)];
  }

  /**
   * Whether a flat option value exists anywhere in the grouped options.
   *
   * @param array<string, array<string, string>> $options
   */
  private function optionExists(array $options, string $value): bool {
    foreach ($options as $group) {
      if (isset($group[$value])) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Count of leaf options across all groups.
   *
   * @param array<string, array<string, string>> $options
   */
  private function flatCount(array $options): int {
    $count = 0;
    foreach ($options as $group) {
      $count += count($group);
    }
    return $count;
  }

}
