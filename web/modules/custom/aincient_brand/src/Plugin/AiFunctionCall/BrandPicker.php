<?php

declare(strict_types=1);

namespace Drupal\aincient_brand\Plugin\AiFunctionCall;

use Drupal\aincient_brand\BrandPresets;
use Drupal\aincient_pages\BrandRepository;
use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * AIncient Command: open the in-chat quick brand picker.
 *
 * Emits a generative-UI widget envelope (`{"__widget__": "brand_picker",
 * "payload": …}`) instead of prose. The dispatcher harvests the envelope out of
 * the agent's tool results and renders the `brand_picker` widget inline — a
 * compact card exposing only the primary + accent colour and a small set of
 * starting presets (NOT the full token studio). Picking a colour or preset
 * stages it as a preview-only draft and opens the brand studio, where the user
 * reviews the live preview and clicks Publish to apply it site-wide — nothing
 * persists on the pick itself, so a stray click can't reskin the live site.
 *
 * @see \Drupal\aincient_pages\Controller\BrandController::save()
 *
 * Use this when the user wants to tweak the headline brand colours or try a
 * different look quickly. For deeper / multi-token changes, use `preview_brand`.
 */
#[FunctionCall(
  id: 'aincient_brand:brand_picker',
  function_name: 'aincient_brand_picker',
  name: 'Quick brand picker',
  description: 'Show the user an interactive quick brand picker in the chat: pick a primary and accent colour from a swatch grid, or choose a starting preset (SaaS / Playful / Editorial). A pick stages a preview in the brand studio for the user to review and Publish — it does NOT change the live site by itself. Call this when the user wants to choose or tweak the main brand colours, or asks to "show me brand options" / "let me pick a colour". Takes no arguments — it renders the picker.',
)]
final class BrandPicker extends FunctionCallBase implements ExecutableFunctionCallInterface {

  /**
   * The brand store.
   */
  protected BrandRepository $brand;

  /**
   * The quick-brand presets.
   */
  protected BrandPresets $presets;

  /**
   * The current user.
   */
  protected AccountInterface $currentUser;

  /**
   * The readable output.
   */
  protected string $result = '';

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->brand = $container->get('aincient_pages.brand');
    $instance->presets = $container->get('aincient_brand.presets');
    $instance->currentUser = $container->get('current_user');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function execute(): void {
    if (!$this->currentUser->hasPermission('administer aincient pages')) {
      $this->result = 'Error: you do not have permission to change the site brand.';
      return;
    }

    $tokens = $this->brand->tokens();
    $payload = [
      'current' => [
        'primary' => $tokens['brand_primary'] ?? NULL,
        'accent' => $tokens['brand_accent'] ?? NULL,
      ],
      'presets' => $this->presets->summaries(),
      // The swatch palette + live token values. A pick stages a draft and opens
      // the studio; the studio's Publish (not this widget) is the write path, so
      // no apply URL is handed to the widget.
      'manifestUrl' => Url::fromRoute('aincient_pages.brand_manifest')->toString(),
    ];

    $this->result = (string) json_encode([
      '__widget__' => 'brand_picker',
      'payload' => $payload,
      'summary' => 'Here are some quick brand options — pick a colour or a preset to preview it in the brand studio, then Publish to apply it across the site.',
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getReadableOutput(): string {
    return $this->result;
  }

}
