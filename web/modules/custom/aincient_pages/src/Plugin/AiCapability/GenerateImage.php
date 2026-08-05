<?php

declare(strict_types=1);

namespace Drupal\aincient_pages\Plugin\AiCapability;

use Drupal\aincient_core\CapabilitySet;
use Drupal\aincient_core\ModelRoles;
use Drupal\aincient_core\Inference\AiGateway;
use Drupal\aincient_core\Inference\ImageRef;
use Drupal\aincient_pages\MediaRepository;
use Drupal\aincient_core\Attribute\Capability;
use Drupal\aincient_core\Capability\CapabilityBase;
use Drupal\aincient_core\Capability\ExecutableCapabilityInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * AIncient capability: generate (or edit) an image with the site's image model.
 *
 * The Media studio's ONE AI capability — the optional layer over the always-free
 * non-AI editor rail. Two modes, one tool:
 *
 *   - text→image: `prompt` only  → a brand-new image from a description.
 *   - image→image: `prompt` + `source` (a `media:<id>` token) → an edit of an
 *     existing image ("make it warmer") — the mode the whole Media studio started
 *     from.
 *
 * NON-DESTRUCTIVE by design: every call mints a NEW `image` media entity + its own
 * `media:<id>` token via {@see MediaRepository::createFromBytes} (Open Q #4 —
 * "new by default"). It never overwrites the source; the human replaces the
 * original in place, if they want, through the editor rail's Replace-file control.
 * The new item lands in the Library like any upload, and the tool returns a
 * `media_result` widget envelope so the chat renders it inline (id + token +
 * preview) for the human to open in the studio.
 *
 * Provider routing is DETERMINISTIC: it resolves the {@see \Drupal\aincient_core\ModelRoles::IMAGE}
 * role through {@see ModelRoleResolver::imageBinding()} — the explicit binding, never
 * the ambiguous `text_to_image` op-default (more than one installed provider
 * advertises it). That binding is also the product gate: the Media chat rail (and
 * thus this tool) only appears once the image role is bound, so a missing binding
 * here is a defensive error, not the normal path.
 */
#[Capability(
  id: 'aincient_pages:generate_image',
  function_name: 'aincient_generate_image',
  name: 'Generate image',
  description: 'Generate a NEW image with the site\'s image model, or EDIT an existing one. Pass "prompt" (a vivid description of the image you want) alone to create from scratch (text→image). To EDIT an existing image — "make this warmer", "remove the background", "add a hat" — ALSO pass "source" as its `media:<id>` token (image→image); the current Media studio item\'s token is given in the system prompt as CURRENT IMAGE, and find_reference returns tokens for any other image. This always creates a NEW image (it never overwrites the original) and returns it inline plus in the Library — tell the user it\'s ready and they can open it to keep editing or Replace the original from the editor rail. Do NOT claim you saved over or deleted anything.',
  context_definitions: [
    'prompt' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Prompt'),
      description: new TranslatableMarkup('A vivid description of the image to generate, or the edit to make to the source image (e.g. "a warm sunrise over terracotta rooftops, soft film grain" or "make the sky dramatic and stormy").'),
      required: TRUE,
    ),
    'source' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Source image'),
      description: new TranslatableMarkup('Optional `media:<id>` token of an EXISTING image to edit (image→image). Omit for a fresh text→image generation. Use the CURRENT IMAGE token from the system prompt, or a token from find_reference.'),
      required: FALSE,
    ),
  ],
  // Spends the Draw verb: a room that wires this tool is a room that can make
  // pictures, so it is the room whose chip row says so.
  verbs: [CapabilitySet::DRAW],
)]
final class GenerateImage extends CapabilityBase implements ExecutableCapabilityInterface {

  /**
   * The image-media library (bytes → media entity + token).
   */
  protected MediaRepository $media;

  /**
   * The one-shot AI boundary (image generation + the titling turn).
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
      $this->result = 'Error: you do not have permission to generate images.';
      return;
    }

    $prompt = trim((string) ($this->getContextValue('prompt') ?? ''));
    if ($prompt === '') {
      $this->result = 'Error: provide a "prompt" describing the image to generate.';
      return;
    }
    $source = trim((string) ($this->getContextValue('source') ?? ''));

    // Deterministic routing: the explicit image-role binding, never the op-default.
    // The two failure states have DIFFERENT remedies, so they stay separate.
    $editing = $source !== '';
    $status = $this->ai->imageStatus($editing);
    if ($status === AiGateway::STATUS_UNBOUND) {
      $this->result = 'Error: no image model is configured, so images can\'t be generated. Ask an administrator to bind the image role to an image provider.';
      return;
    }
    if ($status === AiGateway::STATUS_UNUSABLE) {
      $this->result = $editing
        ? 'Error: the configured image provider can\'t edit an existing image — describe the image and I\'ll generate a fresh one instead.'
        : 'Error: the configured image provider can\'t generate images from text.';
      return;
    }

    try {
      $binary = $editing
        ? $this->editImage($prompt, $source)
        : $this->ai->generateImage($prompt, NULL, 'aincient_media_studio');
    }
    catch (\Throwable $e) {
      $this->result = 'Error: image generation failed — ' . $e->getMessage();
      return;
    }
    if (!is_string($binary)) {
      // NULL means either a helper already explained itself (editImage's
      // unusable-source path) or the provider simply returned no bytes. The
      // latter used to surface as a thrown "no image data"; say so explicitly
      // rather than returning an empty result, which is how a finished turn
      // reaches the screen as silence (DECISIONS 0278/0279).
      if ($this->result === '') {
        $this->result = 'Error: the image provider returned no image data. Try again, or rephrase the prompt.';
      }
      return;
    }

    // Land the bytes as a NEW media item. Prefer a MADE title + alt (a cheap
    // text-only turn — Law 11: nothing wears the prompt); fall back to the
    // prompt-derived string if the title model is unavailable or its reply is
    // unusable, so generation never blocks on titling.
    $made = $this->madeNameAndAlt($prompt);
    $alt = $made['alt'] ?? $this->altFromPrompt($prompt);
    $row = $this->media->createFromBytes($binary, $this->filenameFromPrompt($prompt), $alt, $made['name']);
    $detail = $this->media->detail($row['id']) ?? $row;

    $mode = $source !== '' ? 'edit' : 'generate';
    $summary = $mode === 'edit'
      ? 'Here\'s the edited image — it\'s saved to your Library. Open it to keep going, or Replace the original from the editor.'
      : 'Here\'s the generated image — it\'s saved to your Library. Open it in the Media studio to refine it.';

    $this->result = (string) json_encode([
      '__widget__' => 'media_result',
      'payload' => [
        'mode' => $mode,
        'source' => $source !== '' ? $source : NULL,
      ] + $detail,
      'summary' => $summary,
    ]);
  }

  /**
   * Image→image: edit an existing media item.
   *
   * Capability was already gated by {@see AiGateway::imageStatus()}; what remains
   * here is resolving the source token, which is media logic rather than AI.
   *
   * @return string|null
   *   The edited bytes, or NULL with $this->result set when the source token is
   *   not a usable image.
   */
  private function editImage(string $prompt, string $source): ?string {
    $bytes = $this->media->sourceBytes($source);
    if ($bytes === NULL) {
      $this->result = sprintf('Error: "%s" isn\'t a usable image to edit. Pass the CURRENT IMAGE token, or use find_reference to get one.', $source);
      return NULL;
    }
    return $this->ai->generateImage(
      $prompt,
      ImageRef::create($bytes['binary'], $bytes['mime'], $bytes['filename'] !== '' ? $bytes['filename'] : 'source.png'),
      'aincient_media_studio',
    );
  }

  /**
   * A short alt/name derived from the prompt (first ~100 chars, one line).
   *
   * The FALLBACK title/alt: used only when {@see self::madeNameAndAlt} can't reach
   * a chat model or its reply is unusable — generation must never fail on titling.
   */
  private function altFromPrompt(string $prompt): string {
    $text = trim(preg_replace('/\s+/', ' ', $prompt));
    return mb_substr($text, 0, 100);
  }

  /**
   * A MADE title + alt for a generated image, or nulls to fall back (Law 11).
   *
   * A cheap TEXT-ONLY turn on the FAST role: given the generation prompt, ask for
   * a short human title (names the subject, not the prompt verbatim) plus a proper
   * alt description, as strict JSON. Text-only — it reasons about the prompt, not
   * the pixels (that's {@see ProposeMediaName}'s vision job). This must NEVER fail
   * or block generation: any unresolvable role / non-chat provider / exception /
   * unparseable reply returns nulls and the caller keeps the prompt-derived string.
   *
   * @return array{name:?string, alt:?string}
   */
  private function madeNameAndAlt(string $prompt): array {
    $null = ['name' => NULL, 'alt' => NULL];
    try {
      $instruction = 'A user generated an image from this prompt: "' . $prompt . '". '
        . 'Write a short human title and alt text for that image. '
        . 'Return ONLY strict JSON, no commentary: {"title": "...", "alt": "..."}. '
        . 'The title is at most 6 words, sentence case, naming the SUBJECT of the image — never the raw prompt text. '
        . 'The alt is one descriptive sentence of about 125 characters for an HTML alt attribute; do not begin with "image of".';
      // Fail-soft by construction: an unbound or unusable FAST role yields '',
      // which decodeTitleAlt() degrades to nulls — so titling never blocks
      // generation (Law 11: nothing wears the prompt).
      return $this->decodeTitleAlt(
        $this->ai->text($instruction, ModelRoles::FAST, 'aincient_media_studio')
      );
    }
    catch (\Throwable) {
      return $null;
    }
  }

  /**
   * Parse the title model's JSON reply into a title + alt, tolerating a code fence.
   *
   * @return array{name:?string, alt:?string}
   */
  private function decodeTitleAlt(string $text): array {
    $text = trim($text);
    // Strip a ```json … ``` (or bare ```) fence some models wrap JSON in.
    $text = trim((string) preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text));
    $data = json_decode($text, TRUE);
    if (!is_array($data)) {
      return ['name' => NULL, 'alt' => NULL];
    }
    $title = isset($data['title']) ? $this->cleanLine((string) $data['title'], 60) : '';
    $alt = isset($data['alt']) ? $this->cleanLine((string) $data['alt'], 250) : '';
    return ['name' => $title !== '' ? $title : NULL, 'alt' => $alt !== '' ? $alt : NULL];
  }

  /**
   * Collapse whitespace, strip wrapping quotes, and cap (the cleanAlt idiom).
   */
  private function cleanLine(string $text, int $max): string {
    $text = trim(preg_replace('/\s+/', ' ', $text));
    $text = trim($text, "\"' \t\n\r");
    return mb_substr($text, 0, $max);
  }

  /**
   * A safe base filename derived from the prompt (slug + .png).
   */
  private function filenameFromPrompt(string $prompt): string {
    $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $prompt), '-'));
    $slug = $slug !== '' ? mb_substr($slug, 0, 40) : 'generated';
    return $slug . '.png';
  }

  /**
   * {@inheritdoc}
   */
  public function getReadableOutput(): string {
    return $this->result;
  }

}
