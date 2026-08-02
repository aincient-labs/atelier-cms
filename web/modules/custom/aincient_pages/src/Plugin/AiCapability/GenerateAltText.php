<?php

declare(strict_types=1);

namespace Drupal\aincient_pages\Plugin\AiCapability;

use Drupal\aincient_core\Inference\AiGateway;
use Drupal\aincient_core\Inference\ImageRef;
use Drupal\aincient_core\ModelRoles;
use Drupal\aincient_pages\MediaRepository;
use Drupal\aincient_core\Attribute\Capability;
use Drupal\aincient_core\Capability\CapabilityBase;
use Drupal\aincient_core\Capability\ExecutableCapabilityInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * AIncient capability: write alt text for an image (image→text / vision).
 *
 * The Media studio's second AI capability, the read-side companion to
 * {@see GenerateImage}: instead of making pixels, it *reads* them. It resolves the
 * {@see ModelRoles::VISION} role to a vision-capable chat model, sends the image as
 * an attachment on a Chat call ("describe this for an alt attribute"), and returns
 * the sentence as a PROPOSAL — it does NOT write it. The client drops the suggestion
 * into the editor rail's alt field (dirty, unsaved), where the human reviews it and
 * clicks Save, exactly as the page agent stages a draft the human Publishes ("AI
 * proposes, you approve"). Nothing is persisted here.
 *
 * Why the VISION role binds a CHAT provider (Gemini, GPT-4o, Claude) rather than an
 * image provider like Nano Banana: "seeing" an image is a chat call with the image
 * attached, not a separate image→text operation. That shape now lives behind
 * {@see AiGateway::describeImage()}; this plugin no longer builds it.
 *
 * Unlike image generation, this is NOT gated behind an explicit binding: vision
 * resolves through {@see ModelRoleResolver::resolve()}, which falls back to the
 * site's default chat model — so alt-text works out of the box, and the models-page
 * "Vision model" pick is only an override. It targets an EXISTING open item, so it
 * returns a `media_result` widget in `alt_text` mode carrying the suggested text and
 * the item's `id` (which the client uses to populate that item's editor field).
 */
#[Capability(
  id: 'aincient_pages:generate_alt_text',
  function_name: 'aincient_generate_alt_text',
  name: 'Generate alt text',
  description: 'Look at an existing image and write concise, descriptive alt text for it. Pass "source" as the image\'s `media:<id>` token — use the CURRENT IMAGE token from the system prompt for the image the user is viewing, or find_reference to look up another. Optionally pass "context" to steer the description (e.g. "this is the hero banner", "focus on the product, ignore the background"). This does NOT save: it drops the suggested alt text into the editor rail for the image the user is viewing so they can review and Save it. Tell the user the suggestion is in the editor and they can tweak it and Save. Do NOT claim you saved the alt text.',
  context_definitions: [
    'source' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Source image'),
      description: new TranslatableMarkup('The `media:<id>` token of the image to describe. Use the CURRENT IMAGE token from the system prompt, or a token from find_reference.'),
      required: TRUE,
    ),
    'context' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Context'),
      description: new TranslatableMarkup('Optional hint about what matters in the image or how it is used (e.g. "hero banner", "focus on the product"). Steers the description; omit for a plain literal description.'),
      required: FALSE,
    ),
  ],
)]
final class GenerateAltText extends CapabilityBase implements ExecutableCapabilityInterface {

  /**
   * The image-media library (token → bytes, and the alt write).
   */
  protected MediaRepository $media;

  /**
   * The one-shot AI boundary (the vision call, provider details absorbed).
   */
  protected AiGateway $ai;

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
    $instance->media = $container->get('aincient_pages.media');
    $instance->ai = $container->get('aincient_core.inference.gateway');
    $instance->currentUser = $container->get('current_user');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function execute(): void {
    if (!$this->currentUser->hasPermission('administer aincient pages')) {
      $this->result = 'Error: you do not have permission to edit media.';
      return;
    }

    $source = trim((string) ($this->getContextValue('source') ?? ''));
    if ($source === '') {
      $this->result = 'Error: provide "source" — the `media:<id>` token of the image to describe.';
      return;
    }
    $row = $this->media->resolveToken($source);
    $bytes = $this->media->sourceBytes($source);
    if ($row === NULL || $bytes === NULL) {
      $this->result = sprintf('Error: "%s" isn\'t a usable image. Pass the CURRENT IMAGE token, or use find_reference to get one.', $source);
      return;
    }

    // Vision resolves WITH a fallback (default chat model) — never gated. The two
    // failure states carry DIFFERENT remedies, so they stay separate messages.
    $status = $this->ai->roleStatus(ModelRoles::VISION);
    if ($status === AiGateway::STATUS_UNBOUND) {
      $this->result = 'Error: no chat model is configured, so I can\'t describe images. Ask an administrator to connect an AI provider.';
      return;
    }
    if ($status === AiGateway::STATUS_UNUSABLE) {
      $this->result = 'Error: the configured vision model can\'t read images. Bind a vision-capable chat model under the model settings.';
      return;
    }

    try {
      $alt = $this->describe($bytes, (string) ($this->getContextValue('context') ?? ''));
    }
    catch (\Throwable $e) {
      $this->result = 'Error: describing the image failed — ' . $e->getMessage();
      return;
    }
    if ($alt === '') {
      $this->result = 'Error: the model returned no description. Try again, or add a "context" hint.';
      return;
    }

    // PROPOSE, don't persist: return the suggestion; the client drops it into the
    // editor rail's alt field (dirty, unsaved) for the human to review and Save —
    // the media analogue of the page agent staging a draft the human Publishes
    // (DECISIONS: "AI proposes, you approve"). The `id` in the payload tells the
    // Media studio WHICH open item to populate.
    $this->result = (string) json_encode([
      '__widget__' => 'media_result',
      'payload' => [
        'mode' => 'alt_text',
        'source' => $source,
        'alt_text' => $alt,
      ] + $row,
      'summary' => 'I\'ve suggested alt text and dropped it into the editor — review it and Save when you\'re happy.',
    ]);
  }

  /**
   * Build the instruction, run the vision call, return cleaned alt text.
   */
  private function describe(array $bytes, string $context): string {
    $instruction = 'Write concise, descriptive alt text for the attached image, for use as an HTML alt attribute. '
      . 'Capture the essential visual content in a single sentence of about 125 characters or fewer. '
      . 'Do not begin with "image of" or "picture of", do not add quotes, and return only the alt text with no extra commentary.';
    if (trim($context) !== '') {
      $instruction .= ' Context to consider: ' . trim($context) . '.';
    }
    return $this->cleanAlt($this->ai->describeImage(
      $instruction,
      ImageRef::create($bytes['binary'], $bytes['mime'], $bytes['filename']),
      'aincient_media_studio',
    ));
  }

  /**
   * Tidy the model's reply into a single-line alt string (strip quotes/whitespace).
   */
  private function cleanAlt(string $text): string {
    $text = trim(preg_replace('/\s+/', ' ', $text));
    // Drop wrapping quotes some models add around a single-sentence answer.
    $text = trim($text, "\"' \t\n\r");
    return mb_substr($text, 0, 250);
  }

  /**
   * {@inheritdoc}
   */
  public function getReadableOutput(): string {
    return $this->result;
  }

}
