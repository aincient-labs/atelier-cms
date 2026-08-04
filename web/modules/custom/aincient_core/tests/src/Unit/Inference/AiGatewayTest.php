<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_core\Unit\Inference;

use Drupal\aincient_core\Exception\AiProviderFailure;
use Drupal\aincient_core\Inference\AiGateway;
use Drupal\aincient_core\Inference\ImageGenerationAdapterInterface;
use Drupal\aincient_core\Inference\ImageRef;
use Drupal\aincient_core\Inference\MessageMapper;
use Drupal\aincient_core\Inference\PlatformRegistryInterface;
use Drupal\aincient_core\Inference\ProviderAdapterInterface;
use Drupal\aincient_core\Inference\ProviderCall;
use Drupal\aincient_core\Inference\ResultUnpacker;
use Drupal\aincient_core\ModelRoleResolver;
use Drupal\aincient_core\ModelRoles;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Tests\aincient_core\Traits\UnmeteredInferenceTrait;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Message\Content\Image;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\PlainConverter;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\BinaryResult;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\InMemoryRawResult;
use Symfony\AI\Platform\Result\MultiPartResult;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\TextResult;

/**
 * Pins the gateway's contract, which four production files depend on.
 *
 * Two things are load-bearing here and neither is visible from a call site. The
 * three-way status vocabulary is the product's diagnostics — collapse UNBOUND and
 * UNUSABLE and the console starts giving the wrong remedy. And the result union is
 * where a successful call turns into a silent nothing: an image arrives wrapped
 * alongside the model's chatter, so reading one arm of that union drops the
 * picture on the ORDINARY success path (DECISIONS 0278/0279 in miniature).
 *
 * The gateway is exercised against a recording platform rather than a doubled
 * gateway, because what these tests are for is the internals no caller can see.
 */
#[CoversClass(AiGateway::class)]
final class AiGatewayTest extends UnitTestCase {

  use UnmeteredInferenceTrait;

  /**
   * The platform stand-in, recording what invoke() was called with.
   */
  private object $platform;

  protected function setUp(): void {
    parent::setUp();

    $this->platform = new class() implements PlatformInterface {

      /**
       * The options of the last invoke() call, or NULL if never called.
       *
       * @var array<string, mixed>|null
       */
      public ?array $options = NULL;

      /**
       * The model id of the last invoke() call.
       */
      public string|Model|null $model = NULL;

      /**
       * The message bag of the last invoke() call.
       */
      public ?MessageBag $bag = NULL;

      /**
       * The result to yield — the SHAPE under test.
       */
      public ?ResultInterface $result = NULL;

      /**
       * When set, thrown on invoke to stand in for an upstream fault.
       */
      public ?\Throwable $failure = NULL;

      /**
       * How many times invoke() was called.
       */
      public int $calls = 0;

      public function invoke(string|Model $model, array|string|object $input, array $options = []): DeferredResult {
        $this->calls++;
        $this->model = $model;
        $this->options = $options;
        $this->bag = $input instanceof MessageBag ? $input : NULL;

        if ($this->failure !== NULL) {
          throw $this->failure;
        }

        return new DeferredResult(
          new PlainConverter($this->result ?? new TextResult('  spaced answer  ')),
          new InMemoryRawResult(),
        );
      }

      public function getModelCatalog(): ModelCatalogInterface {
        throw new \LogicException('The gateway must never consult the catalog.');
      }

    };
  }

  /**
   * An unbound role is UNBOUND — "connect a provider".
   */
  public function testUnboundRoleIsUnbound(): void {
    $gateway = $this->gateway([]);

    self::assertSame(AiGateway::STATUS_UNBOUND, $gateway->roleStatus(ModelRoles::TASK));
    self::assertFalse($gateway->canText(ModelRoles::TASK));
  }

  /**
   * A role bound to a provider we have no adapter for is UNUSABLE, not UNBOUND.
   *
   * The remedy differs: something IS configured, it just cannot be served — e.g. a
   * `drupal/ai` provider id left in config by an install that predates the
   * adapters. Reporting UNBOUND here would tell the operator to connect a provider
   * they already connected.
   */
  public function testRoleBoundToAnUnknownProviderIsUnusable(): void {
    $gateway = $this->gateway(
      ['task' => ['provider_id' => 'no_such_provider', 'model_id' => 'x']],
      adapters: [],
    );

    self::assertSame(AiGateway::STATUS_UNUSABLE, $gateway->roleStatus(ModelRoles::TASK));
    self::assertFalse($gateway->canText(ModelRoles::TASK));
  }

  /**
   * A bound provider with no credential stored is UNUSABLE. THE UPGRADE.
   *
   * `drupal/ai` could not answer this — several plugins reported themselves usable
   * with nothing stored, so this state read as READY and then failed at call time
   * with a vendor exception. Answering it up front is the one place the migration
   * made the status vocabulary more honest rather than merely preserving it.
   */
  public function testBoundButUnconnectedProviderIsUnusable(): void {
    $gateway = $this->gateway(
      ['task' => ['provider_id' => 'anthropic', 'model_id' => 'claude-sonnet-5']],
      connected: FALSE,
    );

    self::assertSame(AiGateway::STATUS_UNUSABLE, $gateway->roleStatus(ModelRoles::TASK));
    self::assertFalse($gateway->canText(ModelRoles::TASK));
    self::assertSame('', $gateway->text('hello'));
    self::assertSame(0, $this->platform->calls, 'An unusable role must not reach a provider.');
  }

  /**
   * A bound, connected, known provider is READY and answers.
   */
  public function testReadyRoleAnswersWithTrimmedText(): void {
    $gateway = $this->gateway($this->taskBinding());

    self::assertSame(AiGateway::STATUS_READY, $gateway->roleStatus(ModelRoles::TASK));
    self::assertTrue($gateway->canText(ModelRoles::TASK));
    // Trimmed, as the contract says: callers store this straight into a title.
    self::assertSame('spaced answer', $gateway->text('name this thread'));
    self::assertSame('claude-sonnet-5', $this->platform->model);
  }

  /**
   * An unconfigured role returns '' rather than throwing. The caller contract.
   *
   * ThreadNamer and the media tools treat '' as "no AI here, keep the fallback".
   * Turning this into an exception would make a keyless install look broken.
   */
  public function testTextReturnsEmptyStringWhenNothingIsBound(): void {
    self::assertSame('', $this->gateway([])->text('anything'));
    self::assertSame(0, $this->platform->calls);
  }

  /**
   * Text arriving as a MultiPartResult is still read. THE UNION, text side.
   *
   * A one-shot call declares no tools, so it cannot get a tool call back — but it
   * can absolutely get a bridge's multi-part wrapper, and reading only
   * `getContent()` as a string there returns '' from a perfectly good answer.
   */
  public function testTextIsReadOutOfAMultiPartResult(): void {
    $gateway = $this->gateway($this->taskBinding());
    $this->platform->result = new MultiPartResult([
      new TextResult('First thought.'),
      new TextResult('Second thought.'),
    ]);

    self::assertSame("First thought.\n\nSecond thought.", $gateway->text('think'));
  }

  /**
   * A described image travels as an image part, next to the instruction.
   *
   * A vision call that forgets the image still returns plausible-looking text, so
   * the part itself is asserted — and it must be `Content\Image`, the type both
   * bridges normalise (Anthropic → base64 source, Gemini → inline_data).
   */
  public function testDescribeImageSendsAnImagePartWithTheInstruction(): void {
    $gateway = $this->gateway([
      'vision' => ['provider_id' => 'anthropic', 'model_id' => 'claude-sonnet-5'],
    ]);
    $this->platform->result = new TextResult('A clay rooftop tile.');

    $description = $gateway->describeImage('describe this', new ImageRef('PNGBYTES', 'image/png', 'tile.png'));

    self::assertSame('A clay rooftop tile.', $description);
    $parts = $this->userMessageParts();
    self::assertCount(2, $parts);
    $image = $parts[1];
    self::assertInstanceOf(Image::class, $image);
    self::assertSame('image/png', $image->getFormat());
    self::assertSame('PNGBYTES', $image->asBinary());
  }

  /**
   * An empty image is not sent at all — and neither is an unbound vision role.
   */
  public function testDescribeImageReturnsEmptyStringWithoutAnImageOrARole(): void {
    $bound = $this->gateway(['vision' => ['provider_id' => 'anthropic', 'model_id' => 'claude-sonnet-5']]);
    self::assertSame('', $bound->describeImage('describe this', new ImageRef('')));

    $unbound = $this->gateway([]);
    self::assertSame('', $unbound->describeImage('describe this', new ImageRef('PNGBYTES')));

    self::assertSame(0, $this->platform->calls);
  }

  /**
   * A bare BinaryResult yields the bytes.
   */
  public function testGenerateImageReadsABareBinaryResult(): void {
    $gateway = $this->gateway($this->imageBinding());
    $this->platform->result = new BinaryResult('PNGBYTES', 'image/png');

    self::assertSame('PNGBYTES', $gateway->generateImage('a warm sunrise'));
    self::assertSame('gemini-2.5-flash-image', $this->platform->model);
  }

  /**
   * Bytes wrapped in a MultiPartResult yield too. THE UNION, image side.
   *
   * This is the ORDINARY Gemini success shape: the model says something about the
   * picture and attaches it, so the bridge emits a MultiPartResult of TextResult +
   * BinaryResult. Handling only the bare BinaryResult means the studio reports
   * "the image provider returned no image data" on a call that worked — a finished
   * turn reaching the screen as a failure, with a green pipeline behind it.
   */
  public function testGenerateImageReadsBytesOutOfAMultiPartResult(): void {
    $gateway = $this->gateway($this->imageBinding());
    $this->platform->result = new MultiPartResult([
      new TextResult('Here is the picture you asked for.'),
      new BinaryResult('PNGBYTES', 'image/png'),
    ]);

    self::assertSame('PNGBYTES', $gateway->generateImage('a warm sunrise'));
  }

  /**
   * A result with no image at all is NULL — the caller's "say so" branch.
   */
  public function testGenerateImageReturnsNullWhenNoBytesCameBack(): void {
    $gateway = $this->gateway($this->imageBinding());
    $this->platform->result = new TextResult('I would rather not.');

    self::assertNull($gateway->generateImage('a warm sunrise'));
  }

  /**
   * Editing sends the source image; generating sends only the prompt.
   */
  public function testEditingSendsTheSourceImageAndGeneratingDoesNot(): void {
    $gateway = $this->gateway($this->imageBinding());
    $this->platform->result = new BinaryResult('PNGBYTES', 'image/png');

    $gateway->generateImage('make it warmer', new ImageRef('SOURCEBYTES', 'image/jpeg'));
    $parts = $this->userMessageParts();
    self::assertCount(2, $parts);
    self::assertInstanceOf(Image::class, $parts[1]);
    self::assertSame('SOURCEBYTES', $parts[1]->asBinary());

    $gateway->generateImage('a fresh sunrise');
    self::assertCount(1, $this->userMessageParts());
  }

  /**
   * The image role's three states, and what each one refuses.
   */
  public function testImageStatusDistinguishesTheThreeStates(): void {
    self::assertSame(AiGateway::STATUS_UNBOUND, $this->gateway([])->imageStatus());

    // Bound to a provider that cannot draw at all: an adapter, but not an image
    // one. A TYPE check, not an empty-model-list check — a provider whose
    // enumeration merely failed must not look like one that cannot draw.
    $textOnly = $this->gateway(
      ['image' => ['provider_id' => 'anthropic', 'model_id' => 'claude-sonnet-5']],
    );
    self::assertSame(AiGateway::STATUS_UNUSABLE, $textOnly->imageStatus());
    self::assertNull($textOnly->generateImage('a warm sunrise'));

    // Bound to an image provider that cannot EDIT: ready to generate, unusable to
    // edit. The distinction that lets the studio offer "describe it and I'll draw
    // a fresh one" instead of a dead end.
    $noEditing = $this->gateway($this->imageBinding(), supportsEditing: FALSE);
    self::assertSame(AiGateway::STATUS_READY, $noEditing->imageStatus());
    self::assertSame(AiGateway::STATUS_UNUSABLE, $noEditing->imageStatus(TRUE));
    self::assertNull($noEditing->generateImage('make it warmer', new ImageRef('SOURCEBYTES')));

    // Bound and capable, but keyless.
    $keyless = $this->gateway($this->imageBinding(), connected: FALSE);
    self::assertSame(AiGateway::STATUS_UNUSABLE, $keyless->imageStatus());

    $ready = $this->gateway($this->imageBinding());
    self::assertSame(AiGateway::STATUS_READY, $ready->imageStatus());
    self::assertSame(AiGateway::STATUS_READY, $ready->imageStatus(TRUE));
    self::assertTrue($ready->canGenerateImage());

    self::assertSame(0, $this->platform->calls, 'No status query may cost a request.');
  }

  /**
   * A provider fault is a NAMED failure, never a quiet '' or NULL.
   *
   * The '' / NULL returns mean "not configured". Using them for "the provider
   * broke" is precisely how a failed turn reaches the screen as silence, so an
   * upstream fault throws — and the exception names the provider and model so the
   * console can render something a person can act on.
   */
  public function testUpstreamFailureThrowsANamedProviderFailure(): void {
    $gateway = $this->gateway($this->taskBinding());
    $this->platform->failure = new \RuntimeException('502 Bad Gateway');

    $this->expectException(AiProviderFailure::class);
    $this->expectExceptionMessage('anthropic');
    $gateway->text('anything');
  }

  /**
   * Options travel in the PROVIDER's dialect: the adapter is always asked.
   *
   * The gateway sends no generation options today, so the assertion is about the
   * SEAM rather than about a value: whatever the adapter says is what reaches the
   * platform. Bypass it and a Gemini one-shot call would 400 the day the first
   * option is added — the exact defect the reasoner already hit.
   */
  public function testOptionsPassThroughTheAdaptersDialect(): void {
    $gateway = $this->gateway($this->taskBinding(), translated: ['maxOutputTokens' => 64]);

    $gateway->text('hello');

    self::assertSame(['maxOutputTokens' => 64], $this->platform->options);
  }

  /**
   * The content parts of the last user message the platform received.
   *
   * @return array<int, mixed>
   *   The parts.
   */
  private function userMessageParts(): array {
    self::assertInstanceOf(MessageBag::class, $this->platform->bag);
    foreach ($this->platform->bag->getMessages() as $message) {
      if ($message instanceof UserMessage) {
        return $message->getContent();
      }
    }
    self::fail('The platform received no user message.');
  }

  /**
   * The `task` role bound to a provider we have an adapter for.
   *
   * @return array<string, array<string, string>>
   *   A role bindings map.
   */
  private function taskBinding(): array {
    return ['task' => ['provider_id' => 'anthropic', 'model_id' => 'claude-sonnet-5']];
  }

  /**
   * The `image` role bound to the image provider.
   *
   * @return array<string, array<string, string>>
   *   A role bindings map.
   */
  private function imageBinding(): array {
    return ['image' => ['provider_id' => 'nanobanana', 'model_id' => 'gemini-2.5-flash-image']];
  }

  /**
   * A gateway over the recording platform and a stubbed role config.
   *
   * @param array<string, array<string, string>> $roles
   *   The `aincient_core.model_roles` bindings to pretend are stored.
   * @param array<int, string>|null $adapters
   *   Provider ids to register adapters for, or NULL for the default pair
   *   (`anthropic` text-only, `nanobanana` image-capable).
   * @param bool $connected
   *   What the registry answers for isConnected().
   * @param bool $supportsEditing
   *   What the image adapter answers for supportsImageEditing().
   * @param array<string, mixed>|null $translated
   *   The options the adapter should claim its provider wants, or NULL for
   *   passthrough.
   */
  private function gateway(
    array $roles,
    ?array $adapters = NULL,
    bool $connected = TRUE,
    bool $supportsEditing = TRUE,
    ?array $translated = NULL,
  ): AiGateway {
    $textAdapter = $this->createMock(ProviderAdapterInterface::class);
    $textAdapter->method('id')->willReturn('anthropic');
    $textAdapter->method('translateOptions')
      ->willReturnCallback(static fn (array $options): array => $translated ?? $options);

    $imageAdapter = $this->createMock(ImageGenerationAdapterInterface::class);
    $imageAdapter->method('id')->willReturn('nanobanana');
    $imageAdapter->method('supportsImageEditing')->willReturn($supportsEditing);
    $imageAdapter->method('translateOptions')
      ->willReturnCallback(static fn (array $options): array => $translated ?? $options);

    $available = ['anthropic' => $textAdapter, 'nanobanana' => $imageAdapter];
    if ($adapters !== NULL) {
      $available = array_intersect_key($available, array_flip($adapters));
    }

    $registry = $this->createMock(PlatformRegistryInterface::class);
    $registry->method('adapters')->willReturn($available);
    $registry->method('adapter')->willReturnCallback(
      static fn (string $id): ProviderAdapterInterface => $available[$id]
        ?? throw new \LogicException('The gateway asked for an adapter it had already ruled out: ' . $id),
    );
    $registry->method('isConnected')->willReturn($connected);
    $registry->method('platform')->willReturn($this->platform);

    return new AiGateway(
      $registry,
      $this->roleResolver($roles),
      new ResultUnpacker(new MessageMapper()),
      $this->unmeteredRecorder(),
      new NullLogger(),
      // Sleepless: the retry policy itself is pinned in ProviderCallTest, and a
      // real backoff would put seconds of nothing into every failure case here.
      new ProviderCall(new NullLogger(), sleepBetweenAttempts: FALSE),
    );
  }

  /**
   * A REAL role resolver over stubbed config.
   *
   * ModelRoleResolver is final and cannot be doubled — which is just as well: role
   * resolution, including its fallback chain, is part of what these tests are
   * pinning, so the real one is the honest choice.
   *
   * It used to take a `drupal/ai` plugin manager built without its constructor,
   * because the chain's last two links read that module's operation-type defaults
   * and an unbound role fell all the way through to them. Those links are gone
   * (see {@see ModelRoleResolver::resolve()} for why they could only ever resolve
   * to something unservable), so the resolver is now pure config — which is the
   * clearest evidence available that they were carrying nothing.
   *
   * @param array<string, array<string, string>> $roles
   *   The stored bindings.
   */
  private function roleResolver(array $roles): ModelRoleResolver {
    return new ModelRoleResolver(
      $this->getConfigFactoryStub([
        'aincient_core.model_roles' => ['roles' => $roles, 'default_role' => ModelRoles::TASK],
      ]),
      $this->createMock(ModuleHandlerInterface::class),
    );
  }

}
