<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_onboarding\Kernel;

use Drupal\aincient_core\ModelPresetResolver;
use Drupal\aincient_core\RecommendationSource;
use Drupal\aincient_onboarding\WizardPayload;
use Drupal\Core\State\StateInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;

/**
 * The setup screen degrades instead of 500ing when a stored part is unreadable.
 *
 * atelier-cms #28: on a site upgraded from 0.8.0 with OpenAI already connected,
 * `/atelier?onboarding=1` answered "The website encountered an unexpected
 * error". The payload was one array literal built inside the console's
 * settings-alter hook, so ANY throw from the stored state it reads — a
 * catalogue shape an older release wrote, a recommendations document, a profile
 * config — took the whole console page down with it.
 *
 * What is pinned here is the contract that replaced it: a failing part is
 * logged, falls back to the empty shape the wizard already tolerates, and is
 * named in `unavailable` so the screen can say so; every OTHER part is still
 * built. The wizard opening with one empty picker and a notice is a bad day;
 * the console refusing to render at all is an unrecoverable one.
 *
 * @group aincient
 */
#[RunTestsInSeparateProcesses]
final class WizardPayloadDegradesTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'key',
    'aincient_core',
    'aincient_inference_test',
    'aincient_onboarding',
  ];

  /**
   * The recording logger swapped in for the onboarding channel.
   */
  private LoggerInterface $logger;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->setCurrentUser($this->createUser(['administer site configuration']));
    $this->logger = $this->recordingLogger();
    $this->container->set('logger.channel.aincient_onboarding', $this->logger);
  }

  /**
   * A healthy site reports no degradation at all.
   *
   * The control: without this, "degraded" could be permanently TRUE and the
   * failure test would still pass.
   */
  public function testHealthySiteIsNotDegraded(): void {
    $payload = $this->payload()->build(TRUE, TRUE);

    $this->assertTrue($payload['needed']);
    $this->assertFalse($payload['degraded']);
    $this->assertSame([], $payload['unavailable']);
    $this->assertNotEmpty($payload['profiles']);
    $this->assertSame([], $this->records());
  }

  /**
   * A part that throws degrades to its empty shape; the rest still builds.
   */
  public function testUnreadablePresetsDegradeRatherThanThrow(): void {
    $this->container->set('aincient_core.model_preset_resolver', $this->throwingPresetResolver());

    $payload = $this->payload()->build(TRUE, TRUE);

    // It answered at all — the whole point.
    $this->assertIsArray($payload);
    $this->assertTrue($payload['needed']);

    // It says what it could not read, by the keys the wizard knows.
    $this->assertTrue($payload['degraded']);
    foreach (['profiles', 'defaultProfile', 'presets'] as $key) {
      $this->assertContains($key, $payload['unavailable']);
    }

    // The fallbacks are the empty shapes the wizard already tolerates.
    $this->assertSame([], $payload['profiles']);
    $this->assertSame('', $payload['defaultProfile']);
    $this->assertSame([], $payload['presets']);

    // Everything independent of the broken part is untouched.
    $this->assertNotEmpty($payload['roles']);
    $this->assertIsString($payload['connectProviderUrl']);
    $this->assertStringContainsString('/onboarding/connect-provider', $payload['connectProviderUrl']);
    $this->assertArrayHasKey('chat', $payload['catalog']);
    $this->assertSame('bundled', $payload['recommendationsMeta']['source']);
    $this->assertTrue($payload['canConfigure']);

    // A degraded screen is not a silent one: the operator sees a notice, the
    // site owner sees the exception.
    $records = $this->records();
    $this->assertNotEmpty($records);
    $this->assertStringContainsString('could not be built', $records[0]['message']);
    $this->assertSame('profiles', $records[0]['context']['@part']);
    $this->assertStringContainsString('no recommendations here', $records[0]['context']['@message']);
  }

  /**
   * The service under test, resolved AFTER any stub is in place.
   */
  private function payload(): WizardPayload {
    return $this->container->get('aincient_onboarding.wizard_payload');
  }

  /**
   * A preset resolver whose every read throws.
   *
   * ModelPresetResolver is final, so the break is injected one level down: a
   * real resolver over a real RecommendationSource whose state store throws.
   * That is also the honest shape of the bug — the document behind the profiles
   * is exactly the stored thing an upgrade can leave unreadable.
   */
  private function throwingPresetResolver(): ModelPresetResolver {
    $state = $this->createMock(StateInterface::class);
    $state->method('get')->willThrowException(new \RuntimeException('no recommendations here'));

    $source = new RecommendationSource(
      $this->container->get('extension.list.module'),
      $state,
      $this->container->get('http_client'),
      $this->container->get('logger.factory'),
      $this->container->get('datetime.time'),
    );

    return new ModelPresetResolver($source, $this->container->get('config.factory'));
  }

  /**
   * What the onboarding channel was told during the build.
   *
   * @return list<array{level: mixed, message: string, context: array}>
   */
  private function records(): array {
    // @phpstan-ignore-next-line The anonymous logger below declares it.
    return $this->logger->records;
  }

  /**
   * A logger that keeps what it was told, so the test can read it back.
   */
  private function recordingLogger(): LoggerInterface {
    return new class() implements LoggerInterface {

      use LoggerTrait;

      /**
       * Every record logged, in order.
       *
       * @var list<array{level: mixed, message: string, context: array}>
       */
      public array $records = [];

      /**
       * {@inheritdoc}
       */
      public function log($level, string|\Stringable $message, array $context = []): void {
        $this->records[] = [
          'level' => $level,
          'message' => (string) $message,
          'context' => $context,
        ];
      }

    };
  }

}
