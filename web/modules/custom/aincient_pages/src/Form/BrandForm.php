<?php

declare(strict_types=1);

namespace Drupal\aincient_pages\Form;

use Drupal\aincient_pages\BrandRepository;
use Drupal\aincient_pages\DesignTokens;
use Drupal\aincient_pages\SiteIdentity;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The no-AI design-system editor — a GLASS-BREAK fallback, deliberately NOT
 * promoted (no admin menu link).
 *
 * Brand direction is normally set through the Brand studio's live preview +
 * Publish; the agent never persists brand (see the studio-only convention). This
 * hand-editable form exists so an operator can still edit every design token +
 * guideline directly when the console/agent isn't available — reachable by URL
 * at /admin/config/content/aincient-brand. Fields are GENERATED from the token
 * registry (DesignTokens), grouped by tier; a blank field means "inherit the
 * default". Each value is validated per type (the same registry gate the studio
 * uses). Saved overrides are injected at render time, reskinning every page with
 * no rebuild.
 */
final class BrandForm extends ConfigFormBase {

  protected DesignTokens $designTokens;

  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->designTokens = $container->get('aincient_pages.design_tokens');
    return $instance;
  }

  public function getFormId(): string {
    return 'aincient_pages_brand_form';
  }

  protected function getEditableConfigNames(): array {
    return [BrandRepository::CONFIG, SiteIdentity::CONFIG];
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config(BrandRepository::CONFIG);
    $identity = $this->config(SiteIdentity::CONFIG);
    $tokens = $config->get('tokens') ?: [];
    $guidelines = $identity->get('guidelines') ?: [];

    $form['guidelines'] = [
      '#type' => 'details',
      '#title' => $this->t('Brand guidelines'),
      '#open' => TRUE,
      '#tree' => TRUE,
      '#description' => $this->t('The AI reads these when writing pages, so generated copy stays on-brand.'),
    ];
    $form['guidelines']['name'] = ['#type' => 'textfield', '#title' => $this->t('Brand name'), '#default_value' => $guidelines['name'] ?? ''];
    $form['guidelines']['tagline'] = ['#type' => 'textfield', '#title' => $this->t('Tagline'), '#default_value' => $guidelines['tagline'] ?? ''];
    $form['guidelines']['description'] = ['#type' => 'textarea', '#title' => $this->t('Description'), '#rows' => 3, '#default_value' => $guidelines['description'] ?? ''];
    $form['guidelines']['tone'] = ['#type' => 'textarea', '#title' => $this->t('Tone of voice'), '#rows' => 2, '#default_value' => $guidelines['tone'] ?? ''];

    $form['webfonts'] = [
      '#type' => 'details',
      '#title' => $this->t('Web fonts'),
      '#open' => TRUE,
      '#description' => $this->t('Google Font families to load (one per line). After loading a font, reference it in the <em>font_display</em> / <em>font_sans</em> token below.'),
    ];
    $form['webfonts']['font_families'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Families'),
      '#rows' => 3,
      '#default_value' => implode("\n", $config->get('font_families') ?: ['Inter', 'Inter Tight']),
      '#placeholder' => "Inter\nPlayfair Display",
    ];
    $form['webfonts']['font_delivery'] = [
      '#type' => 'radios',
      '#title' => $this->t('Font delivery'),
      '#options' => [
        BrandRepository::DELIVERY_SELFHOST => $this->t('Self-host (private) — vendor the fonts to this site, so visitors load them from your own origin. No third-party request, no cookie banner needed.'),
        BrandRepository::DELIVERY_GOOGLE => $this->t('Load from Google Fonts — visitors are asked for consent first (a banner appears); until they accept, the system font is shown and nothing is sent to Google.'),
      ],
      '#default_value' => $config->get('font_delivery') ?: BrandRepository::DELIVERY_GOOGLE,
      '#description' => $this->t('How the families above reach your public pages. Self-host is the strongest privacy posture (GDPR); it fetches the fonts once when you save.'),
    ];

    // Design tokens — one details per tier; fields generated from the registry.
    foreach (DesignTokens::TIERS as $tier) {
      $group = $this->designTokens->byTier($tier);
      if (!$group) {
        continue;
      }
      $form[$tier] = [
        '#type' => 'details',
        '#title' => $this->t('@tier tokens', ['@tier' => ucfirst($tier)]),
        '#open' => $tier === 'semantic',
        '#tree' => TRUE,
        '#description' => $this->t('Leave blank to inherit the default. Values are CSS of the token type; reference another token with var(--css-name).'),
      ];
      foreach ($group as $name => $def) {
        $tag = $def['component'] ?? $def['category'] ?? '';
        $form[$tier][$name] = [
          '#type' => 'textfield',
          '#title' => $def['label'] ?? $name,
          '#field_prefix' => $tag ? $tag . ' · ' : '',
          '#default_value' => (string) ($tokens[$name] ?? ''),
          '#placeholder' => (string) ($def['default'] ?? ''),
          '#description' => $def['description'] ?? '',
          '#size' => 32,
          '#aincient_token' => $name,
          '#element_validate' => [[$this, 'validateToken']],
        ];
      }
    }

    $form['assets'] = [
      '#type' => 'details',
      '#title' => $this->t('Logo'),
      '#open' => FALSE,
      '#tree' => TRUE,
    ];
    $logoFid = (int) $identity->get('logo');
    $form['assets']['logo'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Logo'),
      '#upload_location' => 'public://aincient/brand',
      '#default_value' => $logoFid ? [$logoFid] : [],
      '#upload_validators' => ['FileExtension' => ['extensions' => 'png jpg jpeg svg webp']],
      '#description' => $this->t('Shown in the header and footer of every page.'),
    ];

    $form['footer'] = [
      '#type' => 'details',
      '#title' => $this->t('Footer'),
      '#open' => FALSE,
      '#tree' => TRUE,
      '#description' => $this->t('The header and footer appear on every page. Their navigation is the core <a href=":main">Main</a> and <a href=":footer">Footer</a> menus.', [
        ':main' => '/admin/structure/menu/manage/main',
        ':footer' => '/admin/structure/menu/manage/footer',
      ]),
    ];
    $form['footer']['footer_note'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Footer note'),
      '#description' => $this->t('Leave blank for an automatic © line.'),
      '#default_value' => $identity->get('footer_note') ?? '',
    ];

    return parent::buildForm($form, $form_state);
  }

  /** Per-field validation: a non-empty value must be valid for its token type. */
  public function validateToken(array &$element, FormStateInterface $form_state): void {
    $value = trim((string) ($element['#value'] ?? ''));
    if ($value === '') {
      return;
    }
    $name = $element['#aincient_token'];
    if (!$this->designTokens->validate($name, $value)) {
      $form_state->setError($element, $this->t('“@v” is not a valid value for the %n token.', ['@v' => $value, '%n' => $name]));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Collect non-empty token overrides (blank = inherit default).
    $tokens = [];
    foreach ($this->designTokens->all() as $name => $def) {
      $value = trim((string) $form_state->getValue([$def['tier'], $name], ''));
      if ($value !== '') {
        $tokens[$name] = $value;
      }
    }

    $guidelines = [];
    foreach (SiteIdentity::GUIDELINE_KEYS as $key) {
      $guidelines[$key] = trim((string) $form_state->getValue(['guidelines', $key]));
    }

    // Logo: persist the uploaded file (permanent + usage so it isn't GC'd).
    $logo = $form_state->getValue(['assets', 'logo']);
    $fid = !empty($logo[0]) ? (int) $logo[0] : 0;
    if ($fid) {
      $file = \Drupal::entityTypeManager()->getStorage('file')->load($fid);
      if ($file && !$file->isPermanent()) {
        $file->setPermanent();
        $file->save();
        \Drupal::service('file.usage')->add($file, 'aincient_pages', 'config', 'brand');
      }
    }

    // Web fonts: one family per line, kept only if it's a safe family name.
    $fonts = preg_split('/\r\n|\r|\n/', (string) $form_state->getValue('font_families')) ?: [];
    $fonts = array_values(array_filter(array_map('trim', $fonts), [BrandRepository::class, 'isFontName']));

    $delivery = $form_state->getValue('font_delivery') === BrandRepository::DELIVERY_SELFHOST
      ? BrandRepository::DELIVERY_SELFHOST
      : BrandRepository::DELIVERY_GOOGLE;

    // Foundations (tokens + fonts) and brand identity live in separate configs.
    // Saving aincient_pages.brand fires BrandConfigSubscriber, which vendors the
    // fonts locally when delivery is self-host.
    $this->config(BrandRepository::CONFIG)
      ->set('tokens', $tokens)
      ->set('font_families', $fonts)
      ->set('font_delivery', $delivery)
      ->save();

    $this->config(SiteIdentity::CONFIG)
      ->set('guidelines', $guidelines)
      ->set('logo', $fid)
      ->set('footer_note', trim((string) $form_state->getValue(['footer', 'footer_note'])))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
