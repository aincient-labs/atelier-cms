<?php

declare(strict_types=1);

namespace Drupal\aincient_chat\Form;

use Drupal\aincient_chat\Studio;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Settings for the AIncient chat layer.
 *
 * Owns the studio → agents map the console runs. The console is a workspace
 * switcher: always in exactly one studio (default General), each studio owning
 * a set of agents (FlowDrop workflows) plus a default a new conversation pins.
 * An agent belongs to at most one studio (so a thread's pinned workflow buckets
 * to a single studio in history); an agent in no studio simply isn't offered. A
 * thread pins its workflow when its first message creates the FlowDrop session,
 * so changes here affect new conversations only.
 */
final class SettingsForm extends ConfigFormBase {

  private const SETTINGS = 'aincient_chat.settings';

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
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
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'aincient_chat_settings';
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
    $config = $this->config(self::SETTINGS);

    $options = $this->workflowOptions();
    if ($options === []) {
      $form['no_workflows'] = [
        '#type' => 'item',
        '#markup' => $this->t('No FlowDrop workflows are available yet. Enable FlowDrop and create a workflow first.'),
      ];
      return parent::buildForm($form, $form_state);
    }

    $studios = (array) $config->get('studios');

    $form['default_studio'] = [
      '#type' => 'select',
      '#title' => $this->t('Default studio'),
      '#options' => $this->studioLabels(),
      '#default_value' => $config->get('default_studio') ?: Studio::default()->value,
      '#required' => TRUE,
      '#description' => $this->t('The studio a fresh console session opens in. The console is always in exactly one studio.'),
    ];

    $form['studios'] = [
      '#type' => 'details',
      '#title' => $this->t('Studios'),
      '#description' => $this->t('Each studio is a workspace with its own agents. An agent may belong to at most one studio. The “default” agent is what a new conversation in that studio runs; when a studio has more than one agent, the console shows an agent picker.'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];
    foreach (Studio::cases() as $studio) {
      $key = $studio->value;
      $entry = (array) ($studios[$key] ?? []);
      $form['studios'][$key] = [
        '#type' => 'details',
        '#title' => $studio->label(),
        '#open' => TRUE,
      ];
      $form['studios'][$key]['agents'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Agents'),
        '#options' => $options,
        '#default_value' => array_map('strval', (array) ($entry['agents'] ?? [])),
        '#description' => $this->t('The FlowDrop workflows this studio can run.'),
      ];
      $form['studios'][$key]['default'] = [
        '#type' => 'select',
        '#title' => $this->t('Default agent'),
        '#options' => ['' => $this->t('- First agent -')] + $options,
        '#default_value' => (string) ($entry['default'] ?? ''),
        '#description' => $this->t('Must be one of the agents ticked above. Leave empty to use the first.'),
      ];
    }

    $form['workflow_metadata'] = [
      '#type' => 'details',
      '#title' => $this->t('Per-flow welcome screen'),
      '#description' => $this->t('Customise the heading, description, and suggested prompts each flow shows on a fresh conversation. Leave a field empty to use the console default.'),
      '#tree' => TRUE,
    ];
    $metadata = (array) $config->get('workflow_metadata');
    foreach ($options as $id => $label) {
      $entry = (array) ($metadata[$id] ?? []);
      $form['workflow_metadata'][$id] = [
        '#type' => 'details',
        '#title' => $label,
      ];
      $form['workflow_metadata'][$id]['welcome'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Welcome heading'),
        '#default_value' => $entry['welcome'] ?? '',
        '#description' => $this->t('Shown on a fresh conversation. Defaults to “What would you like to create?”.'),
      ];
      $form['workflow_metadata'][$id]['description'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Welcome description'),
        '#default_value' => $entry['description'] ?? '',
        '#description' => $this->t('The line beneath the heading.'),
      ];
      $form['workflow_metadata'][$id]['sample_asks'] = [
        '#type' => 'textarea',
        '#title' => $this->t('Sample asks'),
        '#rows' => 3,
        '#default_value' => implode("\n", (array) ($entry['sample_asks'] ?? [])),
        '#description' => $this->t('One suggested prompt per line, shown as clickable chips. Leave empty to use the defaults, or tick “Freeform only” to show none.'),
      ];
      $form['workflow_metadata'][$id]['freeform_only'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Freeform only (no sample asks)'),
        '#default_value' => !empty($entry['freeform_only']),
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    $seen = [];
    foreach (Studio::cases() as $studio) {
      $key = $studio->value;
      $agents = $this->tickedAgents($form_state->getValue(['studios', $key, 'agents'], []));
      $default = (string) $form_state->getValue(['studios', $key, 'default'], '');
      if ($default !== '' && !in_array($default, $agents, TRUE)) {
        $form_state->setErrorByName("studios][$key][default", $this->t('The default agent for the %studio studio must be one of its ticked agents.', ['%studio' => $studio->label()]));
      }
      foreach ($agents as $agent) {
        if (isset($seen[$agent])) {
          $form_state->setErrorByName("studios][$key][agents", $this->t('Agent %agent is already assigned to the %other studio — an agent may belong to only one studio.', [
            '%agent' => $agent,
            '%other' => $seen[$agent],
          ]));
        }
        else {
          $seen[$agent] = $studio->label();
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $studios = [];
    foreach (Studio::cases() as $studio) {
      $key = $studio->value;
      $agents = $this->tickedAgents($form_state->getValue(['studios', $key, 'agents'], []));
      if ($agents === []) {
        continue;
      }
      $default = (string) $form_state->getValue(['studios', $key, 'default'], '');
      $studios[$key] = [
        'agents' => $agents,
        'default' => in_array($default, $agents, TRUE) ? $default : reset($agents),
      ];
    }

    $metadata = [];
    foreach ((array) $form_state->getValue('workflow_metadata', []) as $id => $entry) {
      $welcome = trim((string) ($entry['welcome'] ?? ''));
      $description = trim((string) ($entry['description'] ?? ''));
      $asks = array_values(array_filter(
        array_map('trim', preg_split('/\r\n|\r|\n/', (string) ($entry['sample_asks'] ?? ''))),
        'strlen',
      ));
      $freeform = !empty($entry['freeform_only']);
      // Skip flows the admin left entirely at their defaults.
      if ($welcome === '' && $description === '' && $asks === [] && !$freeform) {
        continue;
      }
      $stored = [];
      if ($welcome !== '') {
        $stored['welcome'] = $welcome;
      }
      if ($description !== '') {
        $stored['description'] = $description;
      }
      if ($asks !== []) {
        $stored['sample_asks'] = $asks;
      }
      if ($freeform) {
        $stored['freeform_only'] = TRUE;
      }
      $metadata[(string) $id] = $stored;
    }

    $this->config(self::SETTINGS)
      ->set('default_studio', (string) $form_state->getValue('default_studio'))
      ->set('studios', $studios)
      ->set('workflow_metadata', $metadata)
      ->save();
    parent::submitForm($form, $form_state);
  }

  /**
   * The ticked workflow ids from a checkboxes value.
   *
   * @return list<string>
   */
  private function tickedAgents(mixed $value): array {
    return array_values(array_filter(array_map('strval', (array) $value)));
  }

  /**
   * Studio key => label for the default-studio select.
   *
   * @return array<string, string>
   */
  private function studioLabels(): array {
    $labels = [];
    foreach (Studio::cases() as $studio) {
      $labels[$studio->value] = $studio->label();
    }
    return $labels;
  }

  /**
   * All FlowDrop workflows as select options (id => label).
   *
   * @return array<string, string>
   */
  private function workflowOptions(): array {
    if (!$this->entityTypeManager->hasDefinition('flowdrop_workflow')) {
      return [];
    }
    $options = [];
    foreach ($this->entityTypeManager->getStorage('flowdrop_workflow')->loadMultiple() as $workflow) {
      $options[(string) $workflow->id()] = (string) $workflow->label();
    }
    asort($options);
    return $options;
  }

}
