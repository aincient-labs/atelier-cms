<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Form;

use Drupal\aincient_core\Inference\ProviderInventory;
use Drupal\aincient_core\Usage\UnpricedNotice;
use Drupal\aincient_core\ModelPresetResolver;
use Drupal\aincient_core\ModelRoleResolver;
use Drupal\aincient_core\ModelRoles;
use Drupal\aincient_core\RecommendationSource;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Console settings for the AIncient model roles.
 *
 * Binds each semantic role ({@see ModelRoles}) to a concrete `provider:model`
 * sourced from the providers this site can actually serve, then projects the
 * bindings onto drupal/ai's operation-type defaults so stock FlowDrop inherits
 * them ({@see ModelRoleResolver::project()}). This is the in-Drupal twin of the
 * onboarding pickers and the `drush aincient:model-set` command — all three
 * write the same source of truth.
 *
 * THE POOLS ARE NOW HONEST, which changed this form in two visible ways. It used
 * to list every installed `drupal/ai` provider, including four this product has
 * no adapter for, so an operator could bind `mistral` and get a
 * ProviderConfigurationException on the next turn. And the image pool came from
 * an operation-type string, so it filled up with Anthropic's chat models. Both
 * are gone ({@see ProviderInventory}). What replaces them is the third case:
 * a binding SAVED before this change can name a provider that is no longer
 * servable, and that must never disappear quietly — {@see self::orphanedRoles()}
 * keeps it selectable and says what to do about it.
 */
final class ModelRolesForm extends ConfigFormBase {

  private const SETTINGS = 'aincient_core.model_roles';

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    private readonly ModelRoleResolver $resolver,
    private readonly ProviderInventory $providerManager,
    private readonly ModelPresetResolver $presets,
    private readonly RecommendationSource $recommendations,
    private readonly UnpricedNotice $unpricedNotice,
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
      $container->get('aincient_core.inference.provider_inventory'),
      $container->get('aincient_core.model_preset_resolver'),
      $container->get('aincient_core.recommendation_source'),
      $container->get('aincient_core.unpriced_notice'),
    );
  }

  /**
   * Whether this site has declared any model preference of its own.
   *
   * Mirrors `_aincient_onboarding_preferences_declared()`: true as soon as either
   * list has an entry, without trying to prove a declaration changed this
   * particular pick.
   */
  private function preferencesDeclared(): bool {
    $preferences = $this->configFactory()->get('aincient_core.model_preferences');
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
    // The pools as offered, kept separate from the selects below: a saved binding
    // that can no longer be served is added to the SELECTS so the operator's choice
    // survives a page load, but it must never enter the pool a preset resolves
    // against — "apply Balanced" would then be able to pick the very binding that
    // stopped working.
    $chatPool = $this->groupedOptions(ProviderInventory::CHAT);
    $imagePool = $this->groupedOptions(ProviderInventory::IMAGE);

    $options = $chatPool;
    // Make sure each current binding is selectable even if its provider can no
    // longer serve it (so the form never silently drops a saved choice).
    foreach ($roles as $role) {
      $value = $this->bindingValue($role['provider_id'], $role['model_id']);
      if ($value !== '' && !$this->optionExists($options, $value)) {
        $options[$this->currentGroupLabel($role['provider_id'])][$value] = $value;
      }
    }

    // The image role lives outside the chat-role taxonomy (see ModelRoles::IMAGE)
    // and binds to a provider that can actually draw, so its options come from a
    // separate capability pool.
    $imageOptions = $imagePool;
    $imageBinding = $this->resolver->imageBinding();
    $imageValue = $imageBinding !== NULL
      ? $this->bindingValue($imageBinding['provider_id'], $imageBinding['model_id'])
      : '';
    if ($imageValue !== '' && !$this->optionExists($imageOptions, $imageValue)) {
      $imageOptions[$this->currentGroupLabel($imageBinding['provider_id'])][$imageValue] = $imageValue;
    }

    // Vision draws from the CHAT pool (image→text is a chat call with the image
    // attached), so it reuses $options. Its readback is the EXPLICIT binding —
    // empty means "use the default chat model" (resolve() falls back).
    $visionBinding = $this->resolver->visionBinding();
    $visionValue = $visionBinding !== NULL
      ? $this->bindingValue($visionBinding['provider_id'], $visionBinding['model_id'])
      : '';
    if ($visionValue !== '' && !$this->optionExists($options, $visionValue)) {
      $options[$this->currentGroupLabel($visionBinding['provider_id'])][$visionValue] = $visionValue;
    }

    // Warn BEFORE the early return: a site whose only bindings are orphaned has
    // no pools at all, and that is exactly the operator who most needs to be told
    // why rather than shown an empty page.
    $form['orphaned'] = $this->orphanedWarning();
    $form['unpriced'] = $this->unpricedWarning();

    // The rate sheet is this form's read-only twin — same five roles, same
    // bindings, priced. Linked BEFORE the no-providers early return below,
    // because a site with nothing connected yet is exactly where an operator is
    // still deciding what a model will cost them.
    $form['rates_link'] = [
      '#type' => 'item',
      '#markup' => $this->t('What each role costs to run is on the <a href="@url">model rates</a> page.', [
        '@url' => Url::fromRoute('aincient_core.pricing')->toString(),
      ]),
      '#weight' => 100,
    ];

    if ($this->flatCount($options) === 0 && $this->flatCount($imageOptions) === 0) {
      $form['none'] = [
        '#type' => 'item',
        '#markup' => $this->t('No AI providers are connected yet. Connect one through onboarding and its models will appear here.'),
      ];
      return parent::buildForm($form, $form_state);
    }

    $form['intro'] = [
      '#type' => 'item',
      '#markup' => $this->t('AIncient speaks in <em>roles</em>, not vendor model ids. Bind each role to a model from any connected provider; the choices are projected onto the framework so every assistant flow inherits them.'),
    ];

    $form['presets'] = $this->presetSection($chatPool, $imagePool);

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
        '#markup' => $this->imageAbsenceNotice(),
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
   * What to say when the image role has nothing to offer.
   *
   * TWO DIFFERENT SILENCES, and telling them apart is the whole point (issue #12).
   * An operator whose only connection is an aggregating gateway — a LiteLLM or
   * OpenRouter endpoint reached as `openai_compatible`, the shape the Forge demo
   * runs on — has connected something, seen chat work, and then finds an image
   * section with no options and no next step. "Connect an image provider" reads as
   * a contradiction to them: they DID connect a provider, and its catalogue even
   * advertises a picture model. So the sentence has to name the actual constraint.
   *
   * WHY THE CONDITION IS NOT `isProxy()`. It cannot be: a LiteLLM or OpenRouter
   * endpoint reached through `openai_compatible` reports `isProxy() === FALSE`
   * (only the baked presets say TRUE), so keying the copy off it would stay silent
   * for precisely the install that reported this. What IS knowable without a
   * network call, for every provider, is the pair the inventory already publishes:
   * is a credential stored, and does the adapter claim image capability. So the
   * condition is "something is connected, and nothing connected can draw" — true
   * of a gateway-only install, and equally true of a chat-only one, which needs the
   * same sentence for the same reason.
   *
   * Nothing here promises a gateway will never draw. It says drawing needs a
   * provider connected directly, which is what this build actually supports.
   */
  private function imageAbsenceNotice(): \Stringable|string {
    $connected = array_filter(
      $this->providerManager->providers(),
      static fn (array $row): bool => !empty($row['connected']),
    );
    $canDraw = array_filter(
      $connected,
      static fn (array $row): bool => !empty($row['capabilities'][ProviderInventory::IMAGE]),
    );

    if ($connected !== [] && $canDraw === []) {
      return $this->t('Nothing you have connected can make pictures. A shared gateway passes your words along to other services, and that works for writing and describing — but pictures need a provider connected directly. Add Google Gemini with a Google AI Studio key that has billing enabled, and picture models appear here.');
    }

    return $this->t('No image providers are connected yet. Connect one that can draw (Google Gemini, from a Google AI Studio key) and its models will appear here.');
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

    // Saving this form IS the hand-pick: the operator opened the per-role
    // pickers and chose. Auto mode is all-or-nothing, so the site drops to
    // Custom and no recommendations refresh will move these bindings again.
    // Applying a profile goes through applyPreset() instead, which re-arms it.
    $this->resolver->clearProfile();

    // One projection after all bindings are written.
    $this->resolver->project();

    parent::submitForm($form, $form_state);
  }

  /**
   * The "apply a curated preset" section — the form's twin of the wizard's
   * segmented control.
   *
   * Binds every role in one action from a published profile, for an operator who
   * would rather answer "what am I optimising for?" than fill five selects. The
   * selects below stay authoritative: applying a preset just writes them.
   *
   * @param array<string, array<string, string>> $chatOptions
   *   Grouped chat options.
   * @param array<string, array<string, string>> $imageOptions
   *   Grouped image options.
   *
   * @return array<string, mixed>
   *   A render array (empty when the document defines no profiles).
   */
  private function presetSection(array $chatOptions, array $imageOptions): array {
    $profiles = $this->presets->profiles();
    if ($profiles === []) {
      return [];
    }
    $labels = [];
    foreach ($profiles as $profile) {
      $labels[$profile['id']] = $profile['description'] !== ''
        ? $profile['label'] . ' — ' . $profile['description']
        : $profile['label'];
    }

    $meta = $this->recommendations->meta();
    $provenance = $meta['source'] === 'remote'
      ? $this->t('Suggestions were updated @date from @url.', ['@date' => $meta['updated'], '@url' => $meta['url']])
      : $this->t('Suggestions bundled with this release (@date). Checking for updates fetches @url — nothing is sent from this site.', [
        '@date' => $meta['updated'],
        '@url' => $meta['url'],
      ]);

    return [
      '#type' => 'details',
      '#title' => $this->t('Suggested presets'),
      '#open' => FALSE,
      '#tree' => TRUE,
      '#description' => $this->t('Bind every role at once from a curated profile, resolved against the providers you have connected. You can still change any individual role below.'),
      'profile' => [
        '#type' => 'select',
        '#title' => $this->t('Optimise for'),
        '#options' => $labels,
        '#default_value' => $this->presets->defaultProfile(),
      ],
      'actions' => [
        '#type' => 'actions',
        'apply' => [
          '#type' => 'submit',
          '#value' => $this->t('Apply preset'),
          '#submit' => ['::applyPreset'],
          // This button writes bindings on its own; it must not require (or
          // clobber) the role selects the operator hasn't touched.
          '#limit_validation_errors' => [['presets', 'profile']],
        ],
        'refresh' => [
          '#type' => 'submit',
          '#value' => $this->t('Check for updates'),
          '#submit' => ['::refreshRecommendations'],
          '#limit_validation_errors' => [],
        ],
      ],
      'provenance' => [
        '#type' => 'item',
        '#markup' => $provenance,
      ],
      // Same reason as the onboarding wizard's note: a preset that has been
      // narrowed by this site's own rules looks exactly like one of ours, and an
      // operator surprised by a pick needs somewhere to go and look.
      'preferences' => [
        '#type' => 'item',
        '#access' => $this->preferencesDeclared(),
        '#markup' => $this->t('This site also narrows which models may be chosen, so a preset here can differ from the suggestions above. The rules live in <code>aincient_core.model_preferences</code>.'),
      ],
      // Stashed so the submit handlers resolve against exactly what this build
      // offered, rather than re-probing every provider.
      '#chat_pool' => $this->flatten($chatOptions),
      '#image_pool' => $this->flatten($imageOptions),
    ];
  }

  /**
   * Bind every role from the chosen profile, then project.
   */
  public function applyPreset(array &$form, FormStateInterface $form_state): void {
    $profileId = (string) $form_state->getValue(['presets', 'profile'], '');
    if (!$this->presets->hasProfile($profileId)) {
      $this->messenger()->addError($this->t('That preset is no longer available. Try checking for updates.'));
      return;
    }

    $picked = $this->presets->apply(
      $profileId,
      $form['presets']['#chat_pool'] ?? [],
      $form['presets']['#image_pool'] ?? [],
    );
    if ($picked === []) {
      $this->messenger()->addWarning($this->t('Nothing to apply — no connected provider offers a model for these roles.'));
      return;
    }

    foreach ($picked as $role => $value) {
      [$provider, $model] = $this->splitBinding($value);
      $this->resolver->bind($role, $provider, $model);
    }
    // Back into auto mode: record the tier + the document it resolved against,
    // so the site can name what it is on and a later refresh can keep it there.
    $this->resolver->setProfile($profileId, (string) ($this->recommendations->meta()['updated'] ?? ''));
    $this->resolver->project();

    $this->messenger()->addStatus($this->t('Applied @profile: @bindings', [
      '@profile' => $profileId,
      '@bindings' => implode(', ', array_map(
        static fn (string $role, string $value): string => $role . ' → ' . $value,
        array_keys($picked),
        $picked,
      )),
    ]));
    $form_state->setRebuild();
  }

  /**
   * Fetch the published recommendations. Explicit operator action only.
   */
  public function refreshRecommendations(array &$form, FormStateInterface $form_state): void {
    try {
      $meta = $this->recommendations->refresh();
      $this->messenger()->addStatus($this->t('Suggestions updated (@date).', ['@date' => $meta['updated']]));
    }
    catch (\RuntimeException $e) {
      // Never fatal: the document already in force keeps working, which is the
      // whole reason a snapshot ships with the release.
      $this->messenger()->addWarning($e->getMessage());
    }
    $form_state->setRebuild();
  }

  /**
   * Flatten grouped options into one "provider:model" => label map.
   *
   * @param array<string, array<string, string>> $options
   *   Grouped options.
   *
   * @return array<string, string>
   */
  private function flatten(array $options): array {
    $flat = [];
    foreach ($options as $group) {
      $flat += $group;
    }
    return $flat;
  }

  /**
   * Grouped `provider:model` options for a role select (optgroup per provider).
   *
   * The single source for every pool: the chat roles + vision draw from
   * {@see ProviderInventory::CHAT}, the image role from
   * {@see ProviderInventory::IMAGE}. A provider with no models simply does not
   * appear — which now covers "not connected" and nothing else, since a provider
   * the site cannot serve is not in the inventory to begin with. No try/catch:
   * the inventory answers [] rather than throwing, so guarding here would be
   * guarding against nothing.
   *
   * @param string $capability
   *   {@see ProviderInventory::CHAT} or {@see ProviderInventory::IMAGE}.
   *
   * @return array<string, array<string, string>>
   *   provider label => ("provider_id:model_id" => model label).
   */
  private function groupedOptions(string $capability): array {
    $grouped = [];
    foreach ($this->providerManager->providersWith($capability) as $id => $row) {
      foreach ($this->providerManager->models($id, $capability) as $modelId => $modelLabel) {
        $grouped[$row['label']][$id . ':' . $modelId] = (string) $modelLabel;
      }
    }
    ksort($grouped);
    return $grouped;
  }

  /**
   * The optgroup a saved-but-unavailable binding is kept under.
   *
   * Two different situations wear the same shape in this form — a model this
   * provider no longer lists (renamed, retired), and a provider the site can no
   * longer serve at all. The first is worth keeping quietly selectable; the second
   * needs a name that says so, because the select alone would read as a working
   * choice. {@see self::orphanedWarning()} carries the remedy.
   */
  private function currentGroupLabel(string $providerId): string {
    return (string) ($this->providerManager->has($providerId)
      ? $this->t('Current')
      : $this->t('Bound previously — not available on this site'));
  }

  /**
   * The roles bound to a provider this site has no adapter for.
   *
   * @return array<string, string>
   *   Role id => provider id.
   */
  private function orphanedRoles(): array {
    $bindings = (array) ($this->configFactory()->get(self::SETTINGS)->get('roles') ?? []);
    $orphaned = [];
    foreach ($bindings as $role => $binding) {
      $providerId = (string) ($binding['provider_id'] ?? '');
      if ($providerId !== '' && !$this->providerManager->has($providerId)) {
        $orphaned[(string) $role] = $providerId;
      }
    }
    return $orphaned;
  }

  /**
   * A warning naming every orphaned binding, and what to do instead.
   *
   * The visible-degradation path. A stored binding to a provider we cannot serve
   * fails at call time as {@see \Drupal\aincient_core\Inference\AiGateway::STATUS_UNUSABLE},
   * which is honest but only reaches the operator when they next use the feature.
   * This says it on the page where it can be fixed, names the provider (so the
   * sentence is actionable rather than mysterious), and points at the one
   * genuinely working replacement for most of the providers this affects.
   *
   * @return array<string, mixed>
   *   A render array (empty when nothing is orphaned).
   */
  private function orphanedWarning(): array {
    $orphaned = $this->orphanedRoles();
    if ($orphaned === []) {
      return [];
    }
    $items = [];
    foreach ($orphaned as $role => $providerId) {
      $items[] = $this->t('@role → @provider', [
        '@role' => $role,
        '@provider' => $providerId,
      ]);
    }
    return [
      '#type' => 'item',
      '#markup' => $this->t('Some roles are bound to a provider this site can no longer serve, so those roles will fail until you rebind them. Your saved choice is kept in the selects below (under “Bound previously”) so nothing is lost. OpenAI and Mistral are connectable by name again — reconnect them in the onboarding wizard. The rest (OpenRouter, a LiteLLM proxy, and anything else speaking OpenAI’s API) are offered there too, as the <em>OpenAI-compatible endpoint</em> provider: give it the base URL as well as the key.'),
      'roles' => [
        '#theme' => 'item_list',
        '#items' => $items,
      ],
    ];
  }

  /**
   * A warning naming every bound model this site cannot put a price on.
   *
   * THE SAME CLASS OF PROBLEM as {@see self::orphanedWarning()} above, and
   * deliberately the same shape: a binding that is saved, selectable and
   * apparently fine, but degraded in a way that only shows up later. The orphan
   * fails loudly at call time; this one fails QUIETLY — every call the role
   * serves is recorded at $0.00 and the usage dashboard reads as if the model
   * were free. That is the worse failure of the two, which is why it is said
   * here, on the page where the model is being chosen, and not only on the
   * status report where it is also reported.
   *
   * Rendered after save as well as before it, because saving this form rebuilds
   * it: pick an unpriced model and the warning appears in the same page load.
   * The wording itself lives in {@see UnpricedNotice} — the pricing page shows
   * the same warning over the same rate table, and two pages that phrase one
   * silent failure two ways read as two different problems.
   *
   * @return array<string, mixed>
   *   A render array (empty when every bound model is priced).
   */
  private function unpricedWarning(): array {
    return $this->unpricedNotice->build(
      (array) ($this->configFactory()->get(self::SETTINGS)->get('roles') ?? []),
    );
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
