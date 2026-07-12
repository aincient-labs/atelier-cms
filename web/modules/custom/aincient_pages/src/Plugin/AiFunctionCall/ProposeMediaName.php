<?php

declare(strict_types=1);

namespace Drupal\aincient_pages\Plugin\AiFunctionCall;

use Drupal\aincient_core\ModelRoles;
use Drupal\aincient_core\ModelRoleResolver;
use Drupal\aincient_pages\MediaRepository;
use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatInterface;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\GenericType\ImageFile;
use Drupal\ai\Plugin\ProviderProxy;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * AIncient capability: propose a short human name for an image (vision).
 *
 * The naming twin of {@see GenerateAltText}: where that reads an image and writes
 * alt text, this reads an image and writes a NAME — a short, human title for the
 * Library ledger (Law 11: nothing wears the prompt). It resolves the
 * {@see ModelRoles::VISION} role to a vision-capable chat model, sends the image
 * as an attachment on a Chat call ("give this a short title"), and returns the
 * title as a PROPOSAL — it does NOT write it. The client drops the suggestion into
 * the editor rail's Name field (dirty, unsaved), where the human reviews it and
 * clicks Save, exactly as {@see GenerateAltText} stages an alt suggestion and the
 * page agent stages a draft the human Publishes ("AI proposes, you approve").
 * Nothing is persisted here.
 *
 * Grounded in the PIXELS, not the prompt: naming reads the actual image (via the
 * `media:<id>` token's bytes), so it works for uploads and older items that have
 * no prompt provenance at all. Like alt text, it is NOT gated behind an explicit
 * binding — vision resolves through {@see ModelRoleResolver::resolve()}, which
 * falls back to the site's default chat model, so naming works out of the box. It
 * targets an EXISTING open item, so it returns a `media_result` widget in
 * `propose_name` mode carrying the suggested title and the item's `id` (which the
 * client uses to populate that item's editor field).
 */
#[FunctionCall(
  id: 'aincient_pages:propose_media_name',
  function_name: 'aincient_propose_media_name',
  name: 'Propose a name',
  description: 'Look at an existing image and propose a short, human name (title) for it — for the Library ledger, in place of a raw prompt. Pass "source" as the image\'s `media:<id>` token — use the CURRENT IMAGE token from the system prompt for the image the user is viewing, or find_reference to look up another. Optionally pass "direction" to steer the name (e.g. "keep it playful", "name the product"). This does NOT save: it drops the suggested name into the editor rail for the image the user is viewing so they can review and Save it. Tell the user the suggestion is in the editor and they can tweak it and Save. Do NOT claim you renamed or saved anything.',
  context_definitions: [
    'source' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Source image'),
      description: new TranslatableMarkup('The `media:<id>` token of the image to name. Use the CURRENT IMAGE token from the system prompt, or a token from find_reference.'),
      required: TRUE,
    ),
    'direction' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Direction'),
      description: new TranslatableMarkup('Optional hint about the tone or focus of the name (e.g. "keep it playful", "name the product, not the scene"). Steers the title; omit for a plain descriptive name.'),
      required: FALSE,
    ),
  ],
)]
final class ProposeMediaName extends FunctionCallBase implements ExecutableFunctionCallInterface {

  /**
   * The image-media library (token → bytes).
   */
  protected MediaRepository $media;

  /**
   * The model-role resolver (the vision role → concrete provider + model).
   */
  protected ModelRoleResolver $roles;

  /**
   * The AI provider plugin manager.
   */
  protected AiProviderPluginManager $providers;

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
    $instance->roles = $container->get('aincient_core.model_role_resolver');
    $instance->providers = $container->get('ai.provider');
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
      $this->result = 'Error: provide "source" — the `media:<id>` token of the image to name.';
      return;
    }
    $row = $this->media->resolveToken($source);
    $bytes = $this->media->sourceBytes($source);
    if ($row === NULL || $bytes === NULL) {
      $this->result = sprintf('Error: "%s" isn\'t a usable image. Pass the CURRENT IMAGE token, or use find_reference to get one.', $source);
      return;
    }

    // Vision resolves WITH a fallback (default chat model) — never gated.
    $binding = $this->roles->resolve(ModelRoles::VISION);
    if ($binding['provider_id'] === '') {
      $this->result = 'Error: no chat model is configured, so I can\'t name images. Ask an administrator to connect an AI provider.';
      return;
    }

    try {
      $provider = $this->providers->createInstance($binding['provider_id']);
    }
    catch (\Throwable $e) {
      $this->result = 'Error: the configured vision provider could not be loaded (' . $e->getMessage() . ').';
      return;
    }
    if (!$this->unwrap($provider) instanceof ChatInterface) {
      $this->result = 'Error: the configured vision model can\'t read images. Bind a vision-capable chat model under the model settings.';
      return;
    }

    try {
      $name = $this->nameFor($provider, $binding['model_id'], $bytes, (string) ($this->getContextValue('direction') ?? ''));
    }
    catch (\Throwable $e) {
      $this->result = 'Error: naming the image failed — ' . $e->getMessage();
      return;
    }
    if ($name === '') {
      $this->result = 'Error: the model returned no name. Try again, or add a "direction" hint.';
      return;
    }

    // PROPOSE, don't persist: return the suggestion; the client drops it into the
    // editor rail's Name field (dirty, unsaved) for the human to review and Save —
    // the media analogue of the page agent staging a draft the human Publishes
    // (DECISIONS: "AI proposes, you approve"). The `id` in the payload tells the
    // Media studio WHICH open item to populate.
    $this->result = (string) json_encode([
      '__widget__' => 'media_result',
      'payload' => [
        'mode' => 'propose_name',
        'source' => $source,
        'proposed_name' => $name,
      ] + $row,
      'summary' => 'I\'ve suggested a name and dropped it into the editor — review it and Save when you\'re happy.',
    ]);
  }

  /**
   * Run the vision Chat call and return the cleaned title.
   *
   * Sends ONE user message carrying the instruction plus the image as an
   * {@see ImageFile} attachment — the shape a vision-capable chat model reads.
   */
  private function nameFor(object $provider, string $model, array $bytes, string $direction): string {
    $instruction = 'Give the attached image a short, human name for a media library — a title, not a description. '
      . 'At most 6 words, sentence case, naming the subject. '
      . 'Do not add quotes, do not end with a period, and return only the name with no extra commentary.';
    if (trim($direction) !== '') {
      $instruction .= ' Direction to follow: ' . trim($direction) . '.';
    }
    $file = new ImageFile(
      $bytes['binary'],
      $bytes['mime'] !== '' ? $bytes['mime'] : 'image/png',
      $bytes['filename'] !== '' ? $bytes['filename'] : 'image.png',
    );
    $message = new ChatMessage('user', $instruction, [$file]);
    $output = $provider->chat(new ChatInput([$message]), $model, ['aincient_media_studio']);
    $normalized = $output->getNormalized();
    $text = $normalized instanceof ChatMessage ? $normalized->getText() : '';
    return $this->cleanName($text);
  }

  /**
   * Tidy the model's reply into a single-line title (strip quotes/whitespace, cap).
   */
  private function cleanName(string $text): string {
    $text = trim(preg_replace('/\s+/', ' ', $text));
    // Drop wrapping quotes and a trailing period some models add.
    $text = trim($text, "\"' \t\n\r.");
    return mb_substr($text, 0, 60);
  }

  /**
   * The concrete provider plugin behind the manager's ProviderProxy wrapper.
   *
   * @see GenerateImage::unwrap()
   */
  private function unwrap(object $provider): object {
    return $provider instanceof ProviderProxy ? $provider->getPlugin() : $provider;
  }

  /**
   * {@inheritdoc}
   */
  public function getReadableOutput(): string {
    return $this->result;
  }

}
