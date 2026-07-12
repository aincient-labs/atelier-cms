<?php

declare(strict_types=1);

namespace Drupal\aincient_audit\Form;

use Drupal\aincient_audit\Entity\PolicyInterface;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * The bare dev-tuning form for a policy (Phase 3).
 *
 * Edit-only (policies are created from shipped config, not hand-added here). It
 * exposes the tunable surface — enable/disable, report weight, enforcement mode,
 * and the free-form `parameters` map — as the minimal pre-studio affordance.
 * The Phase-4 studio replaces this with agent-assisted authoring + generated,
 * per-parameter controls.
 */
final class PolicyForm extends EntityForm {

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    $policy = $this->entity;
    assert($policy instanceof PolicyInterface);

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#default_value' => $policy->label(),
      '#required' => TRUE,
    ];
    $form['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enabled'),
      '#default_value' => $policy->status(),
      '#description' => $this->t('Disabled policies are skipped by Checks entirely.'),
    ];
    $form['weight'] = [
      '#type' => 'number',
      '#title' => $this->t('Report weight'),
      '#default_value' => $policy->getWeight(),
      '#description' => $this->t('Lower sorts first in the Checks report.'),
    ];
    $form['enforcement'] = [
      '#type' => 'select',
      '#title' => $this->t('Enforcement'),
      '#options' => [
        PolicyInterface::ENFORCEMENT_ADVISORY => $this->t('Advisory (report only)'),
        PolicyInterface::ENFORCEMENT_ENFORCING => $this->t('Enforcing (gate publish — not yet active)'),
      ],
      '#default_value' => $policy->getEnforcement(),
      '#description' => $this->t('Advisory is honored today. Enforcing is stored but inert until publish-gating ships.'),
    ];
    $form['parameters'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Parameters (YAML)'),
      '#default_value' => $this->encodeParameters($policy->getParameters()),
      '#rows' => 6,
      '#description' => $this->t('Tunable knobs passed to the policy workflow, e.g. <code>title_min: 30</code>. Leave empty to use the check defaults.'),
    ];

    // Read-only context: what this policy runs and where it applies.
    $selector = $policy->getSelector();
    $form['context'] = [
      '#type' => 'item',
      '#title' => $this->t('Runtime'),
      '#markup' => $this->t('Workflow: <code>@wf</code> · mode: <code>@kind</code> · bundles: <code>@bundles</code>', [
        '@wf' => $policy->getWorkflow(),
        '@kind' => $policy->getKind(),
        '@bundles' => $selector['bundles'] ? implode(', ', $selector['bundles']) : 'any',
      ]),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    $raw = (string) $form_state->getValue('parameters');
    if (trim($raw) === '') {
      $form_state->setValue('parameters', []);
      return;
    }
    try {
      $decoded = \Drupal\Component\Serialization\Yaml::decode($raw);
    }
    catch (\Throwable $e) {
      $form_state->setErrorByName('parameters', $this->t('Parameters must be valid YAML: @msg', ['@msg' => $e->getMessage()]));
      return;
    }
    if (!is_array($decoded)) {
      $form_state->setErrorByName('parameters', $this->t('Parameters must be a YAML mapping (key: value pairs).'));
      return;
    }
    $form_state->setValue('parameters', $decoded);
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);
    $this->messenger()->addStatus($this->t('Saved policy %label.', ['%label' => $this->entity->label()]));
    $form_state->setRedirectUrl($this->entity->toUrl('collection'));
    return $result;
  }

  /**
   * Render a parameters map back to editable YAML (empty string when empty).
   *
   * @param array<string, mixed> $parameters
   *   The stored parameters.
   */
  private function encodeParameters(array $parameters): string {
    return $parameters === [] ? '' : rtrim(\Drupal\Component\Serialization\Yaml::encode($parameters));
  }

}
