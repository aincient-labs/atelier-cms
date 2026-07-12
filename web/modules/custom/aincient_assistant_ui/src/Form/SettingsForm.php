<?php

declare(strict_types=1);

namespace Drupal\aincient_assistant_ui\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Settings for the AIncient assistant-ui operator console.
 */
final class SettingsForm extends ConfigFormBase {

  private const SETTINGS = 'aincient_assistant_ui.settings';

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'aincient_assistant_ui_settings';
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

    $form['theme'] = [
      '#type' => 'select',
      '#title' => $this->t('Default theme'),
      '#options' => [
        'dark' => $this->t('Dark'),
        'light' => $this->t('Light'),
      ],
      '#default_value' => $config->get('theme') ?: 'dark',
      '#description' => $this->t('The default appearance of the AIncient operator console.'),
    ];

    $form['allow_user_switch'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Allow users to switch theme'),
      '#default_value' => $config->get('allow_user_switch') ?? TRUE,
      '#description' => $this->t('Show a light/dark toggle in the console. Each user’s choice is remembered in their browser.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config(self::SETTINGS)
      ->set('theme', $form_state->getValue('theme'))
      ->set('allow_user_switch', (bool) $form_state->getValue('allow_user_switch'))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
