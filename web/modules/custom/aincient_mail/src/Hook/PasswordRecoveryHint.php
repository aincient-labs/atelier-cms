<?php

declare(strict_types=1);

namespace Drupal\aincient_mail\Hook;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Tells a locked-out user the way back in when email can't be delivered.
 *
 * On the Day-1 `local` transport every mail is logged and dropped, so core's
 * "reset your password" form promises an email that never arrives — and an
 * admin who never set a password is locked out (atelier-cms#34). While that
 * transport is active, the sign-in form says so and the reset form explains the
 * real recovery path (a one-time link from the manager, then a password under
 * My account) instead of offering a send that goes nowhere.
 *
 * Both forms render on page-cached anonymous pages, so the output carries the
 * settings' cache tag: connecting a transport brings the normal forms back.
 */
final class PasswordRecoveryHint {

  use StringTranslationTrait;

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  #[Hook('form_user_login_form_alter')]
  public function loginForm(array &$form, FormStateInterface $form_state): void {
    if ($this->deliversMail($form)) {
      return;
    }
    $form['ain_password_recovery'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#attributes' => ['class' => ['ain-auth-alt']],
      '#value' => $this->t("Can't sign in? This site can't send email yet, so password reset by email won't arrive. If the site runs in the Atelier manager, use <strong>Edit my site</strong> there."),
      '#weight' => 200,
    ];
  }

  #[Hook('form_user_pass_alter')]
  public function resetForm(array &$form, FormStateInterface $form_state): void {
    if ($this->deliversMail($form)) {
      return;
    }
    foreach (['name', 'mail', 'actions'] as $key) {
      if (isset($form[$key])) {
        $form[$key]['#access'] = FALSE;
      }
    }
    $form['ain_password_recovery'] = [
      '#type' => 'container',
      // The auth theme's compiled utilities; plain stacked paragraphs elsewhere.
      '#attributes' => ['class' => ['grid', 'gap-3']],
      'unavailable' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t("This site can't send email yet, so a reset link can't be sent."),
      ],
      'manager' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('If the site runs in the Atelier manager, use <strong>Edit my site</strong> there to sign in, then set a new password under <strong>My account</strong>.'),
      ],
      'admin' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Otherwise, ask a site administrator to set your password, or to connect email under <strong>System › Mail delivery</strong>.'),
      ],
    ];
  }

  /**
   * Whether a real transport is configured; tags $form with the settings.
   */
  private function deliversMail(array &$form): bool {
    $settings = $this->configFactory->get('aincient_mail.settings');
    CacheableMetadata::createFromRenderArray($form)
      ->addCacheableDependency($settings)
      ->applyTo($form);
    return ($settings->get('transport_type') ?: 'local') !== 'local';
  }

}
