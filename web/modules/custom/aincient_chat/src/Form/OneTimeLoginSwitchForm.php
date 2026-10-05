<?php

declare(strict_types=1);

namespace Drupal\aincient_chat\Form;

use Drupal\aincient_chat\Controller\OneTimeLoginController;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\user\UserInterface;

/**
 * "You're signed in as A — this link signs you in as B." Sign out and continue.
 *
 * Rendered by {@see OneTimeLoginController} only for a VALID link, so the
 * target's name is never shown for a forged one. Submitting signs the current
 * user out and replays the one-time link anonymously, where core consumes it.
 */
final class OneTimeLoginSwitchForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'aincient_one_time_login_switch';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?UserInterface $target = NULL, int $timestamp = 0, string $hash = ''): array {
    $form_state->set('link', [(int) $target->id(), $timestamp, $hash]);
    $form['#cache']['max-age'] = 0;
    $form['#title'] = $this->t('Switch account?');

    $form['message'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t("You're signed in as %current. This link signs you in as %target.", [
        '%current' => $this->currentUser()->getDisplayName(),
        '%target' => $target->getDisplayName(),
      ]),
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Sign out and continue'),
      '#button_type' => 'primary',
    ];
    $form['actions']['stay'] = [
      '#type' => 'link',
      '#title' => $this->t('Stay signed in as @name', ['@name' => $this->currentUser()->getDisplayName()]),
      '#url' => Url::fromUri(OneTimeLoginController::homeUrl($this->currentUser())),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    [$uid, $timestamp, $hash] = $form_state->get('link');
    $query = [];
    // Carry the destination INTO the replayed link; left on this request it
    // would override the redirect and skip the login altogether.
    $request = $this->getRequest();
    if ($request->query->has('destination')) {
      $query['destination'] = $request->query->get('destination');
      $request->query->remove('destination');
    }
    user_logout();
    $form_state->setRedirectUrl(Url::fromRoute('user.reset.login', [
      'uid' => $uid,
      'timestamp' => $timestamp,
      'hash' => $hash,
    ], ['query' => $query]));
  }

}
