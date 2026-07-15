<?php

declare(strict_types=1);

namespace Drupal\aincient_mail\Form;

use Drupal\aincient_mail\Mailer;
use Drupal\aincient_mail\MailKeyStore;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Mime\Email;

/**
 * Configures how Atelier delivers transactional email.
 *
 * Delivery only — sender identity lives in the Site information studio and is
 * shown here read-only (see {@see \Drupal\aincient_pages\Config\SiteInformationOverrider}).
 * Secrets are never written to config: the transport credential goes to State
 * behind a `key` entity via {@see MailKeyStore}, mirroring how AI provider keys
 * are stored during onboarding.
 */
final class MailSettingsForm extends ConfigFormBase {

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typedConfigManager,
    private readonly Mailer $mailer,
    private readonly MailKeyStore $keyStore,
  ) {
    parent::__construct($config_factory, $typedConfigManager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('aincient_mail.mailer'),
      $container->get('aincient_mail.key_store'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['aincient_mail.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'aincient_mail_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('aincient_mail.settings');

    // Sender identity is read-only here — it is owned by Site information.
    $site = $this->configFactory()->get('system.site');
    $name = trim((string) $site->get('name'));
    $mail = trim((string) $site->get('mail'));
    $form['identity'] = [
      '#type' => 'item',
      '#title' => $this->t('Sender identity'),
      '#markup' => $mail !== ''
        ? $this->t('Emails send as %name &lt;%mail&gt;. Change this in Site information.', ['%name' => $name, '%mail' => $mail])
        : $this->t('No sender address is set yet — add one in Site information, or mail falls back to a placeholder address.'),
    ];

    $form['transport_type'] = [
      '#type' => 'radios',
      '#title' => $this->t('Delivery integration'),
      '#default_value' => $config->get('transport_type') ?: 'local',
      '#options' => [
        'local' => $this->t('Log only (no delivery) — the appliance ships without a mail server'),
        'smtp' => $this->t('SMTP server'),
        'api' => $this->t('API provider'),
      ],
      '#required' => TRUE,
    ];

    // --- SMTP ---------------------------------------------------------------
    $smtpVisible = ['visible' => [':input[name="transport_type"]' => ['value' => 'smtp']]];
    $form['smtp'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('SMTP'),
      '#states' => $smtpVisible,
    ];
    $form['smtp']['host'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Host'),
      '#default_value' => $config->get('host'),
    ];
    $form['smtp']['port'] = [
      '#type' => 'number',
      '#title' => $this->t('Port'),
      '#min' => 1,
      '#max' => 65535,
      '#default_value' => $config->get('port') ?: 587,
    ];
    $form['smtp']['username'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Username'),
      '#default_value' => $config->get('username'),
    ];
    $form['smtp']['smtp_password'] = [
      '#type' => 'password',
      '#title' => $this->t('Password'),
      '#description' => $this->keyStore->hasSmtpPassword()
        ? $this->t('A password is stored. Leave blank to keep it.')
        : $this->t('Stored securely (in State, never in config or git).'),
    ];
    $form['smtp']['encryption'] = [
      '#type' => 'select',
      '#title' => $this->t('Encryption'),
      '#default_value' => $config->get('encryption') ?: 'tls',
      '#options' => [
        'tls' => $this->t('STARTTLS (usually port 587)'),
        'ssl' => $this->t('SSL/TLS (usually port 465)'),
        'none' => $this->t('None'),
      ],
    ];

    // --- API ----------------------------------------------------------------
    $apiVisible = ['visible' => [':input[name="transport_type"]' => ['value' => 'api']]];
    $form['api'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('API provider'),
      '#states' => $apiVisible,
    ];
    $form['api']['api_provider'] = [
      '#type' => 'select',
      '#title' => $this->t('Provider'),
      '#default_value' => $config->get('api_provider') ?: 'sendgrid',
      '#options' => [
        'sendgrid' => $this->t('SendGrid'),
        'postmark' => $this->t('Postmark'),
      ],
      '#description' => $this->t('Requires the matching Symfony bridge package (e.g. symfony/sendgrid-mailer) to be installed via Composer.'),
    ];
    $form['api']['api_key'] = [
      '#type' => 'password',
      '#title' => $this->t('API key'),
      '#description' => $this->keyStore->hasApiKey()
        ? $this->t('A key is stored. Leave blank to keep it.')
        : $this->t('Stored securely (in State, never in config or git).'),
    ];

    // --- Sender override (optional) ----------------------------------------
    $form['from_override'] = [
      '#type' => 'email',
      '#title' => $this->t('From address override'),
      '#default_value' => $config->get('from_override'),
      '#description' => $this->t('Optional. Leave blank to send as the Site information address above.'),
    ];

    // --- Test send ----------------------------------------------------------
    $form['test'] = [
      '#type' => 'details',
      '#title' => $this->t('Send a test email'),
      '#open' => FALSE,
    ];
    $form['test']['test_recipient'] = [
      '#type' => 'email',
      '#title' => $this->t('Recipient'),
      '#description' => $this->t('Sends immediately using the values above (even unsaved), like the onboarding “prove it works” check.'),
    ];
    $form['test']['send_test'] = [
      '#type' => 'submit',
      '#value' => $this->t('Send test email'),
      '#submit' => ['::sendTest'],
      '#limit_validation_errors' => [
        ['transport_type'],
        ['host'],
        ['port'],
        ['username'],
        ['smtp_password'],
        ['encryption'],
        ['api_provider'],
        ['api_key'],
        ['from_override'],
        ['test_recipient'],
      ],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('aincient_mail.settings')
      ->set('transport_type', (string) $form_state->getValue('transport_type'))
      ->set('api_provider', (string) $form_state->getValue('api_provider'))
      ->set('host', (string) $form_state->getValue('host'))
      ->set('port', (int) $form_state->getValue('port'))
      ->set('username', (string) $form_state->getValue('username'))
      ->set('encryption', (string) $form_state->getValue('encryption'))
      ->set('from_override', trim((string) $form_state->getValue('from_override')))
      ->save();

    // Secrets: only rewrite when a new value was typed (blank = keep existing).
    $apiKey = (string) $form_state->getValue('api_key');
    if ($apiKey !== '') {
      $this->keyStore->storeApiKey($apiKey);
    }
    $smtpPassword = (string) $form_state->getValue('smtp_password');
    if ($smtpPassword !== '') {
      $this->keyStore->storeSmtpPassword($smtpPassword);
    }

    parent::submitForm($form, $form_state);
  }

  /**
   * Submit handler for the "Send test email" button.
   *
   * Builds a transport from the current (possibly unsaved) form values —
   * resolving a blank secret field to the stored one — and sends a one-off
   * message, so the operator can prove a transport works before saving.
   */
  public function sendTest(array &$form, FormStateInterface $form_state): void {
    $to = trim((string) $form_state->getValue('test_recipient'));
    if ($to === '') {
      $this->messenger()->addError($this->t('Enter a recipient address for the test.'));
      return;
    }

    $type = (string) $form_state->getValue('transport_type');
    if ($type === 'local') {
      $this->messenger()->addWarning($this->t('Local delivery only logs mail — pick SMTP or an API provider (you can test before saving) to actually send.'));
      return;
    }

    $typedApiKey = (string) $form_state->getValue('api_key');
    $typedSmtp = (string) $form_state->getValue('smtp_password');
    $raw = [
      'transport_type' => $type,
      'api_provider' => (string) $form_state->getValue('api_provider'),
      'api_key' => $typedApiKey !== '' ? $typedApiKey : $this->mailer->storedApiKey(),
      'host' => (string) $form_state->getValue('host'),
      'port' => (int) $form_state->getValue('port'),
      'username' => (string) $form_state->getValue('username'),
      'smtp_password' => $typedSmtp !== '' ? $typedSmtp : $this->mailer->storedSmtpPassword(),
      'encryption' => (string) $form_state->getValue('encryption'),
    ];

    try {
      $transport = $this->mailer->buildTransport($raw);
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($this->t('Could not build the transport: @message', ['@message' => $e->getMessage()]));
      return;
    }

    $email = (new Email())
      ->to($to)
      ->subject('Atelier test email')
      ->text("This is a test message from Atelier's mail delivery settings.\n\nIf you received it, delivery is working.");

    if ($this->mailer->send($email, $transport)) {
      $this->messenger()->addStatus($this->t('Test email sent to @to.', ['@to' => $to]));
    }
    else {
      $this->messenger()->addError($this->t('The test email failed to send — see the log (aincient_mail channel) for details.'));
    }
  }

}
