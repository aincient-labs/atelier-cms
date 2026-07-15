<?php

declare(strict_types=1);

namespace Drupal\aincient_pages\Plugin\AiFunctionCall;

use Drupal\aincient_pages\ChromePreviewApplier;
use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * AIncient capability: preview site CHROME in the user's LIVE Globals studio.
 *
 * The chrome agent's ONLY edit tool — and, like its brand and page siblings
 * ({@see PreviewBrand}, {@see PreviewPage}), it writes NOTHING to the live site.
 * Chrome can only be persisted through the Globals studio's Publish button
 * (there is no "set chrome" tool); the agent's job is to drive that studio's
 * live preview by emitting brand IDENTITY (name/tagline/description/tone/footer
 * note) and header/footer LAYOUT changes against the chrome the user is watching.
 *
 * It emits a generative-UI widget envelope (`{"__widget__": "chrome_preview",
 * "payload": …}`). The dispatcher harvests it out of the agent's tool results;
 * the `chrome_preview` widget merges the partial into the SAME unsaved-draft the
 * Globals rail edits, so the header/footer preview re-renders instantly and the
 * change shows as an unsaved edit. The deliberate write stays the studio's
 * Publish (/atelier/chrome/save) — so the agent can iterate freely ("center the
 * logo" → "now make the footer stacked") without touching the running site.
 *
 * MENUS are deliberately out of scope: the inline menu editor owns add/rename/
 * reorder/remove. Values are validated by {@see ChromePreviewApplier} against
 * {@see \Drupal\aincient_pages\SiteIdentity::GUIDELINE_KEYS} and
 * {@see \Drupal\aincient_pages\ChromeRepository::REGISTRY} (the same gates the
 * studio's Publish endpoint uses), so only legal chrome reaches the client.
 *
 * @see \Drupal\aincient_pages\ChromePreviewApplier
 * @see \Drupal\aincient_pages\Controller\ChromeController::save()
 */
#[FunctionCall(
  id: 'aincient_pages:preview_chrome',
  function_name: 'aincient_preview_chrome',
  name: 'Preview chrome',
  description: 'Update the user\'s LIVE chrome preview by setting brand IDENTITY (name, tagline, description, tone, footer note) and/or header/footer LAYOUT. This applies INSTANTLY to the Globals preview the user is watching and stages it as an unsaved draft — it does NOT publish to the live site (the user does that with the Publish button). Use this for every header/footer/identity change so the user sees it happen. The accepted layout settings + their allowed values, and the current chrome, are listed in the system prompt as LIVE CHROME STATE. Menus are edited by the user, not this tool. Set reset=true to revert the preview to the saved chrome.',
  context_definitions: [
    'identity_json' => new ContextDefinition(data_type: 'string', label: new TranslatableMarkup('Identity'), description: new TranslatableMarkup('A JSON object of brand-identity fields to set, e.g. {"name":"Lumen","tagline":"Light, organised","tone":"warm and precise","imagery_style":"soft natural light, muted tones","imagery_avoid":"generic stock photos","footer_note":"© 2026 Lumen"}. Accepted keys: name, tagline, description, tone, imagery_style (art direction for generated/selected images), imagery_avoid (imagery clichés to steer clear of), footer_note. Omit keys you are not changing.'), required: FALSE),
    'layout_json' => new ContextDefinition(data_type: 'string', label: new TranslatableMarkup('Layout'), description: new TranslatableMarkup('A JSON object of header/footer layout settings to set, e.g. {"header":{"logo_position":"center","sticky":false,"nav_alignment":"center"},"footer":{"layout":"stacked","show_tagline":true}}. The setting keys + allowed values are listed in the system prompt. Omit settings you are not changing.'), required: FALSE),
    'reset' => new ContextDefinition(data_type: 'boolean', label: new TranslatableMarkup('Reset'), description: new TranslatableMarkup('Clear the whole preview draft back to the saved chrome.'), required: FALSE),
  ],
)]
final class PreviewChrome extends FunctionCallBase implements ExecutableFunctionCallInterface {

  /**
   * The shared chrome-preview applier (all the validate/envelope work).
   */
  protected ChromePreviewApplier $applier;

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
    $instance->applier = $container->get('aincient_pages.chrome_preview_applier');
    $instance->currentUser = $container->get('current_user');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function execute(): void {
    if (!$this->currentUser->hasPermission('administer aincient pages')) {
      $this->result = 'Error: you do not have permission to change the site chrome.';
      return;
    }

    $envelope = $this->applier->apply([
      'identity_json' => $this->getContextValue('identity_json'),
      'layout_json' => $this->getContextValue('layout_json'),
      'reset' => $this->getContextValue('reset'),
    ]);

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
