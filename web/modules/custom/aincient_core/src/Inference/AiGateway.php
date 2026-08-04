<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Inference;

use Drupal\aincient_core\Exception\AiProviderFailure;
use Drupal\aincient_core\Inference\Exception\ProviderConfigurationException;
use Drupal\aincient_core\ModelRoleResolver;
use Drupal\aincient_core\ModelRoles;
use Drupal\aincient_core\Usage\UsageRecorder;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Message\Content\Image;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;

/**
 * The ONE place in Atelier that talks to an AI backend for a one-shot call.
 *
 * WHY THIS EXISTS. Four production files used to build `drupal/ai` request
 * objects by hand — ThreadNamer, ProposeMediaName, GenerateAltText,
 * GenerateImage — each repeating the same six-step dance: resolve a role, create
 * a provider instance, unwrap a ProviderProxy, capability-check the plugin, build
 * ChatInput/ChatMessage(/ImageFile), then dig a string out of `getNormalized()`.
 * Every one of those steps names a vendor type, so a backend change meant
 * editing all four.
 *
 * This class absorbs that. Its signatures speak only Atelier's vocabulary —
 * model ROLES ({@see ModelRoles}), plain strings, {@see ImageRef}, and our own
 * exceptions. **No caller of this class names `Drupal\ai` or `Symfony\AI`.**
 * That is the contract, and it is the entire value: when the backend drifts,
 * the diff is confined to this file.
 *
 * THE BOUNDARY WAS PAID OUT. It was introduced on `drupal/ai` — deliberately, so
 * that introducing it changed no behaviour — with the claim that moving its
 * internals to `symfony/ai` would then be "a change to this file alone". That
 * move has now happened: everything below runs on {@see PlatformRegistry} and
 * `symfony/ai` platforms, and **not one call site changed** — same signatures,
 * same three-way status vocabulary, same '' / NULL contracts. The claim held,
 * which is the only evidence that matters for a containment boundary.
 *
 * NOT a general-purpose AI service. It does the three things the product
 * actually does — name something, describe a picture, make a picture — and it
 * should stay that small. A fourth method needs a reason, not a convenience.
 *
 * What a facade CANNOT do, stated plainly: it contains TYPE drift, not SEMANTIC
 * drift. If a provider changes what a tool call means, or starts returning
 * refusals where it returned text, that shows up as behaviour and no boundary
 * will absorb it. This buys us the mechanical half of the problem, which is the
 * half that was costing us four-file edits. The migration bore that out in one
 * specific place: the STATUS_UNUSABLE answers below got BETTER (see
 * {@see self::roleStatus()}), because the new backend can be asked whether a
 * credential is actually stored — a semantic gain the facade could not have
 * manufactured on its own.
 */
final class AiGateway {

  public function __construct(
    private readonly PlatformRegistryInterface $registry,
    private readonly ModelRoleResolver $roles,
    private readonly ResultUnpacker $unpacker,
    private readonly UsageRecorder $usage,
    private readonly LoggerInterface $logger,
    private readonly ProviderCall $providerCall,
  ) {}

  /**
   * One text completion for a role.
   *
   * @param string $prompt
   *   The full prompt. Callers own their own prompt construction — this class
   *   deliberately adds nothing to it.
   * @param string $role
   *   The model role to run on ({@see ModelRoles}); defaults to the everyday
   *   `task` tier.
   * @param string $tag
   *   A short call-site tag for logging/metering attribution (e.g.
   *   `aincient_chat_thread_namer`).
   *
   * @return string
   *   The model's text, trimmed. Empty string when the role is unbound or the
   *   provider cannot chat — a caller that needs to distinguish "no AI
   *   configured" from "AI said nothing" should check {@see self::canText()}.
   */
  public function text(string $prompt, string $role = ModelRoles::TASK, string $tag = ''): string {
    $target = $this->chatTargetFor($role);
    if ($target === NULL) {
      return '';
    }
    [$providerId, $modelId] = $target;

    $result = $this->invoke(
      $providerId,
      $modelId,
      new MessageBag(Message::ofUser($prompt)),
      $tag,
      UsageRecorder::OPERATION_CHAT,
    );

    return $this->unpacker->text($result);
  }

  /**
   * A description of an image, from the `vision` role.
   *
   * The vision role binds to a CHAT provider with an image attached, not to an
   * image-generation provider — the distinction that used to be explained in a
   * docblock at each call site.
   *
   * @return string
   *   The description, or '' when vision is unbound or the image is empty.
   */
  public function describeImage(string $instruction, ImageRef $image, string $tag = ''): string {
    if ($image->isEmpty()) {
      return '';
    }
    $target = $this->chatTargetFor(ModelRoles::VISION);
    if ($target === NULL) {
      return '';
    }
    [$providerId, $modelId] = $target;

    $result = $this->invoke(
      $providerId,
      $modelId,
      new MessageBag(Message::ofUser($instruction, $this->imagePart($image))),
      $tag,
      // A vision turn is a chat call with an image part, and it is recorded as
      // `chat` for that reason — see UsageRecorder::OPERATION_CHAT.
      UsageRecorder::OPERATION_CHAT,
    );

    return $this->unpacker->text($result);
  }

  /**
   * Generates an image from a prompt, optionally editing a source image.
   *
   * Routes to text→image, or image→image when a source is given — the branch
   * GenerateImage used to make itself, including the capability checks that
   * decide whether the bound provider can do either.
   *
   * On `symfony/ai` both modes are the SAME call: a message bag with a prompt,
   * plus an image part when there is a source. There is no separate
   * text-to-image transport to pick — the difference that `drupal/ai` expressed
   * as two operation types is one extra content part here, and the capability
   * question it really encoded is answered by {@see self::imageStatus()}.
   *
   * @return string|null
   *   The first returned image's raw bytes, or NULL when the `image` role is
   *   unbound, the provider lacks the needed capability, or nothing came back.
   *   NULL is the product gate: no image role means no AI image rail.
   */
  public function generateImage(string $prompt, ?ImageRef $source = NULL, string $tag = ''): ?string {
    $editing = $source !== NULL && !$source->isEmpty();
    // imageBinding() deliberately has NO fallback chain — the image role is
    // either explicitly bound or the feature is off. imageStatus() re-reads it
    // and applies the capability rule, so the two answers cannot drift apart.
    if ($this->imageStatus($editing) !== self::STATUS_READY) {
      return NULL;
    }
    $binding = $this->roles->imageBinding() ?? [];
    $providerId = (string) ($binding['provider_id'] ?? '');
    $modelId = (string) ($binding['model_id'] ?? '');

    $parts = [$prompt];
    if ($editing) {
      $parts[] = $this->imagePart($source);
    }

    $result = $this->invoke(
      $providerId,
      $modelId,
      new MessageBag(Message::ofUser(...$parts)),
      $tag,
      // ONE transport, two operations in the log: the wire call is identical
      // either way here, but "made a picture" and "edited a picture" are
      // different lines on a bill and drupal/ai-era rows named them apart.
      $editing
        ? UsageRecorder::OPERATION_IMAGE_TO_IMAGE
        : UsageRecorder::OPERATION_TEXT_TO_IMAGE,
    );

    return $this->unpacker->firstBinary($result);
  }

  /**
   * A role is not bound to any provider — nothing is configured.
   */
  public const STATUS_UNBOUND = 'unbound';

  /**
   * A role is bound, but this provider cannot serve the call.
   *
   * Either no adapter claims the bound provider id, or nothing is connected for
   * it (no credential stored), or — for image work — the adapter cannot draw, or
   * cannot edit an image it is given. A DIFFERENT remedy from
   * {@see self::STATUS_UNBOUND}: bind a different model, rather than connect a
   * provider.
   */
  public const STATUS_UNUSABLE = 'unusable';

  /**
   * A role resolves to a provider that can serve the call.
   */
  public const STATUS_READY = 'ready';

  /**
   * Why a role can or cannot serve a call — the three cases, kept distinct.
   *
   * Exists because collapsing them would be a real regression. The call sites
   * tell a user to *connect a provider* when nothing is bound, but to *bind a
   * different model* when something is bound that cannot serve the call —
   * different remedies, so a single boolean (or a bare '' return) would degrade
   * the product's diagnostics. Losing a distinguishable failure into an
   * indistinguishable one is the DECISIONS 0278/0279 mistake in miniature.
   *
   * WHAT THE NEW BACKEND MADE OF THIS DISTINCTION. `drupal/ai` could only answer
   * "does this plugin implement ChatInterface?", and several plugins reported
   * themselves usable with no key stored at all — so a keyless-but-bound provider
   * read as READY and failed at call time with a vendor exception. Here
   * UNUSABLE also covers "connected? no" ({@see PlatformRegistry::isConnected()}
   * reads the actual stored credential), which turns a runtime crash into an
   * up-front, actionable state. The three cases did not just survive the
   * migration; the middle one got more honest.
   *
   * @return self::STATUS_UNBOUND|self::STATUS_UNUSABLE|self::STATUS_READY
   *   Which of the three states this role is in.
   */
  public function roleStatus(string $role = ModelRoles::TASK): string {
    $binding = $this->roles->resolve($role);
    if ((string) ($binding['provider_id'] ?? '') === '') {
      return self::STATUS_UNBOUND;
    }
    return $this->chatTargetFor($role) === NULL
      ? self::STATUS_UNUSABLE
      : self::STATUS_READY;
  }

  /**
   * Whether a text completion for a role would actually reach a model.
   *
   * The boolean shorthand over {@see self::roleStatus()}, for callers that have
   * one fallback path rather than two remedies.
   */
  public function canText(string $role = ModelRoles::TASK): bool {
    return $this->chatTargetFor($role) !== NULL;
  }

  /**
   * Whether the AI image rail is available (the `image` role is bound).
   */
  public function canGenerateImage(): bool {
    $binding = $this->roles->imageBinding();
    return $binding !== NULL && ($binding['provider_id'] ?? '') !== '';
  }

  /**
   * Why the image role can or cannot serve a generate/edit call.
   *
   * Same reasoning as {@see self::roleStatus()}: the two failures have different
   * remedies. An unbound role means "ask an administrator to bind one"; a bound
   * provider that cannot EDIT means "describe the image and I'll generate a fresh
   * one instead" — advice worth keeping, and impossible to give from a NULL.
   *
   * The capability check is a TYPE check against
   * {@see ImageGenerationAdapterInterface}, not a value check on some model list:
   * a provider whose enumeration merely failed over the network must not look
   * like a provider that cannot draw. That is the same discrimination
   * `drupal/ai`'s TextToImageInterface / ImageToImageInterface pair got right,
   * now expressed against an interface we own.
   *
   * @param bool $editing
   *   TRUE to ask about image→image (editing a source), FALSE for text→image.
   *
   * @return self::STATUS_UNBOUND|self::STATUS_UNUSABLE|self::STATUS_READY
   *   Which of the three states the image role is in for that mode.
   */
  public function imageStatus(bool $editing = FALSE): string {
    $binding = $this->roles->imageBinding();
    $providerId = (string) ($binding['provider_id'] ?? '');
    if ($binding === NULL || $providerId === '') {
      return self::STATUS_UNBOUND;
    }

    $adapter = $this->registry->adapters()[$providerId] ?? NULL;
    if (!$adapter instanceof ImageGenerationAdapterInterface) {
      return self::STATUS_UNUSABLE;
    }
    if ($editing && !$adapter->supportsImageEditing()) {
      return self::STATUS_UNUSABLE;
    }
    // Bound and capable, but no key stored is still unusable — and saying so here
    // is better than letting the studio offer a rail that 401s on first use.
    return $this->registry->isConnected($providerId)
      ? self::STATUS_READY
      : self::STATUS_UNUSABLE;
  }

  /**
   * Resolves a role to a provider that can actually serve a chat call.
   *
   * Consolidates the resolve → adapter → connected? dance, and returns NULL
   * rather than throwing: an unbound role is a normal state on a keyless install,
   * not an error.
   *
   * There is no chat CAPABILITY check to make here, and that is not an omission.
   * On `symfony/ai` every adapter's platform speaks chat — including a vision
   * turn, which is a chat call with one image part — so the question `drupal/ai`
   * answered with `instanceof ChatInterface` has no counterpart. What remains
   * genuinely unknowable-until-called is whether the bound MODEL suits the role,
   * and that is a model-binding question the console's picker owns, not something
   * to guess at from a provider id.
   *
   * @return array{0: string, 1: string}|null
   *   The provider id and model id, or NULL when the role cannot serve a chat
   *   call.
   */
  private function chatTargetFor(string $role): ?array {
    $binding = $this->roles->resolve($role);
    $providerId = (string) ($binding['provider_id'] ?? '');
    if ($providerId === '') {
      return NULL;
    }
    // adapters() rather than adapter(): a provider id left over from another
    // module (or a since-removed adapter) is a status to report, not an exception
    // to raise on a status query.
    if (!isset($this->registry->adapters()[$providerId])) {
      return NULL;
    }
    if (!$this->registry->isConnected($providerId)) {
      return NULL;
    }

    return [$providerId, (string) ($binding['model_id'] ?? '')];
  }

  /**
   * One inference, with every upstream fault named rather than swallowed.
   *
   * Callers reach this only after a status check says READY, so a failure here is
   * a genuine upstream fault — and it stays a THROW, exactly as the `drupal/ai`
   * path did. Degrading it to '' would hide a broken provider behind "the AI had
   * nothing to say", which is the DECISIONS 0278/0279 silence all over again; the
   * '' and NULL returns documented above are for *unconfigured*, never for
   * *failed*.
   *
   * @param string $providerId
   *   The provider to invoke.
   * @param string $modelId
   *   The model id, as bound to the role.
   * @param \Symfony\AI\Platform\Message\MessageBag $bag
   *   The one-turn conversation to send.
   * @param string $tag
   *   The call-site tag, used for log attribution AND as the metering row's
   *   call-site name ({@see UsageRecorder::record()}) — which is why the exact
   *   strings callers already pass are worth keeping stable.
   * @param string $operation
   *   The operation to record this call as ({@see UsageRecorder}).
   *
   * @return object
   *   The result, for {@see ResultUnpacker} to read.
   *
   * @throws \Drupal\aincient_core\Exception\AiProviderFailure
   *   When the provider could not serve the call.
   * @throws \Drupal\aincient_core\Inference\Exception\ProviderConfigurationException
   *   When the provider turns out not to be configured after all.
   */
  private function invoke(string $providerId, string $modelId, MessageBag $bag, string $tag, string $operation): object {
    try {
      $options = $this->registry->adapter($providerId)->translateOptions($this->options());
      $platform = $this->registry->platform($providerId);
    }
    catch (ProviderConfigurationException $e) {
      // Local misconfiguration — not an upstream failure. Let it through as-is so
      // the caller can say "connect a provider" instead of blaming the model.
      throw $e;
    }

    // Retries and classification live in ProviderCall, shared with the reasoner
    // and the chat completer — a transient 429 here used to abort the run with
    // the provider's own wire text as the whole message (atelier-cms#8).
    $result = $this->providerCall->run(
      // Resolve inside the closure: DeferredResult is lazy, so an upstream fault
      // surfaces on conversion rather than on invoke(). Converting outside is
      // exactly how an error escapes unwrapped — and unretried.
      fn (): object => $platform->invoke($modelId, $bag, $options)->getResult(),
      $providerId,
      $modelId,
      'request',
      // The tag is the only thing that says WHICH feature made this call, so it
      // belongs in the log line. It is deliberately NOT sent to the provider:
      // `symfony/ai` has no metering-tag channel, and an unknown option key is
      // a hard 400 on Gemini (see
      // {@see ProviderAdapterInterface::translateOptions()}).
      ['@tag' => $tag !== '' ? $tag : 'untagged'],
    );

    // Metered here, in the one place all three product calls pass through, so
    // adding a fourth method cannot forget to. A failed call records nothing on
    // purpose: there is no usage to report, and the throw above is the record.
    $this->usage->record($providerId, $modelId, $result, $tag, $operation);

    return $result;
  }

  /**
   * The request options a one-shot call sends, in our neutral vocabulary.
   *
   * Empty on purpose. The `drupal/ai` path passed no generation parameters for
   * any of these three calls, and inventing a token cap here would be a silent
   * behaviour change — a truncated alt text is exactly the kind of "improvement"
   * nobody asked for. Naming this rather than inlining `[]` keeps the seam
   * visible: whatever a one-shot call ever needs to send goes through the
   * adapter's dialect ({@see ProviderAdapterInterface::translateOptions()}) with
   * the reasoner, not around it.
   *
   * @return array<string, mixed>
   *   The neutral options.
   */
  private function options(): array {
    return [];
  }

  /**
   * An {@see ImageRef} as the message content type a bridge can normalise.
   *
   * `Content\Image` (a `File` whose format is the MIME type) is the type both
   * bridges we ship convert: Anthropic emits `{type: image, source: {type:
   * base64, media_type, data}}` and Gemini emits `{inline_data: {mime_type,
   * data}}`. Neither wire shape carries a FILENAME, so `ImageRef::$filename` is
   * dropped here rather than smuggled into the payload — it stays on the value
   * object for the callers that name a file they saved.
   */
  private function imagePart(ImageRef $image): Image {
    return new Image($image->binary, $image->mimeType);
  }

}
