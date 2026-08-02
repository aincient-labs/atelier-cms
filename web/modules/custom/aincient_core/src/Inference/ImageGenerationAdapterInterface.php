<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Inference;

/**
 * The extra questions an image-generating provider can answer.
 *
 * WHY THIS IS SEPARATE. Today "can this provider make a picture?" is answered by
 * `instanceof` against `drupal/ai`'s `TextToImageInterface` /
 * `ImageToImageInterface`, and the capability split those two interfaces express
 * is the right one — most providers generate no images at all. Folding
 * `listImageModels()` into {@see ProviderAdapterInterface} would force every text
 * provider to carry a method whose only honest implementation is `return []`, and
 * a contract whose methods are mostly stubs teaches a reader nothing about what a
 * provider can do. Worse, it would make the gateway's capability check a
 * *value* check (empty array?) rather than a *type* check, so a provider whose
 * enumeration merely failed over the network would look like a provider that
 * cannot draw.
 *
 * So the base contract stays the one thing every provider must satisfy, and image
 * capability stays a type an adapter opts into — the same discrimination
 * `drupal/ai` got right, expressed against an interface we own.
 *
 * DELIBERATELY NARROW. Only the two questions a caller actually asks: which
 * models can draw, and can this provider edit an image it is given (as opposed to
 * only generating from a prompt). Generation itself needs nothing new — an image
 * turn is `PlatformInterface::invoke()` with a message bag exactly like a chat
 * turn, and the Gemini bridge converts an `inlineData` response part into a
 * `BinaryResult`. There is no image-specific transport to declare here, and
 * inventing one would put payload shaping back into an adapter.
 */
interface ImageGenerationAdapterInterface extends ProviderAdapterInterface {

  /**
   * Whether this provider can edit a source image, not just draw from a prompt.
   *
   * The `ImageToImageInterface` half of the old `instanceof` pair. Answered by
   * the adapter rather than looked up in a map on our side, for the same reason
   * {@see ProviderAdapterInterface::authShape()} is.
   */
  public function supportsImageEditing(): bool;

  /**
   * Lists the image-output models this credential can actually reach.
   *
   * Same conformance rule as {@see ProviderAdapterInterface::listChatModels()},
   * and for the same reason: a real round-trip, [] on a credential that does not
   * work, never a bundled list dressed up as a catalogue.
   *
   * @return array<string, string>
   *   Model id => human label. Empty when the credential does not work.
   */
  public function listImageModels(string $credential, string $endpoint = ''): array;

}
