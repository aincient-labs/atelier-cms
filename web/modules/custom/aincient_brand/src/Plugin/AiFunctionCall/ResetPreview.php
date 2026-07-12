<?php

declare(strict_types=1);

namespace Drupal\aincient_brand\Plugin\AiFunctionCall;

use Drupal\aincient_pages\BrandPreviewApplier;
use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * AIncient Command: revert the brand preview draft to the saved brand.
 *
 * The Brand orchestrator no longer has an apply tool (the deterministic merge
 * node applies specialists' work end-of-turn), so it also lost `preview_brand`'s
 * `reset=true` path. This restores that one capability as a tiny dedicated tool:
 * "start over / revert / undo my changes" → the agent calls this, which emits a
 * `brand_preview` envelope with `reset: true`. It rides the EXISTING Invoke
 * widget-harvest path (it's a normal tool result, not a specialist slice), so
 * the merge node ignores it and the dispatcher surfaces it like any other
 * tool-produced widget. Draft-only, like every brand tool — it clears the
 * unsaved preview, never the published brand.
 *
 * @see \Drupal\aincient_pages\BrandPreviewApplier
 * @see \Drupal\aincient_brand\Plugin\AiFunctionCall\PreviewBrand
 */
#[FunctionCall(
  id: 'aincient_brand:reset_preview',
  function_name: 'aincient_reset_preview',
  name: 'Reset brand preview',
  description: 'Revert the brand preview draft back to the currently saved brand, clearing all unsaved preview edits. Call this when the user wants to start over, undo their preview changes, or go back to how the brand was. Draft-only — it does NOT change the live site. Takes no arguments.',
)]
final class ResetPreview extends FunctionCallBase implements ExecutableFunctionCallInterface {

  /**
   * The shared brand-preview applier.
   */
  protected BrandPreviewApplier $applier;

  /**
   * The current user.
   */
  protected AccountInterface $currentUser;

  /**
   * The readable output (the widget envelope, or an error).
   */
  protected string $result = '';

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->applier = $container->get('aincient_pages.preview_applier');
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
    $envelope = $this->applier->apply(['reset' => TRUE]);
    $this->result = isset($envelope['error'])
      ? (string) $envelope['error']
      : (string) json_encode($envelope);
  }

  /**
   * {@inheritdoc}
   */
  public function getReadableOutput(): string {
    return $this->result;
  }

}
