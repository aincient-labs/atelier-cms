<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_core\Kernel;

use Drupal\aincient_core\Exception\AiProviderFailure;
use Drupal\aincient_core\Inference\ProviderCall;
use Drupal\aincient_core\ModelRoles;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\aincient_core\Traits\ScriptedInferenceTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\AI\Platform\FinishReason\FinishReasonCase;

/**
 * A turn cut off at the token cap must raise, not return.
 *
 * THE FAILURE THAT ARRIVED AS A SUCCESS. Every other provider fault throws
 * somewhere in the bridge and reaches {@see ProviderCall}'s classifier. A
 * response truncated at the output token cap does not: it converts cleanly, so
 * the half-written sentence — or the tool call the model was in the middle of
 * emitting and therefore never finished — came back as an ordinary answer, with
 * `status: success` on every node (atelier-cms#8). A wrong answer presented as a
 * right one is worse than any error card, and nothing downstream could tell.
 *
 * This drives the REAL services out of the container (scripted adapter → gateway
 * → ProviderCall) rather than a mock, because the thing under test is a decision
 * made on the way OUT of the call — the seam a unit test can assert but only the
 * wired path proves is actually reached.
 *
 * The mirror-image assertions matter just as much: an absent finish reason and a
 * clean stop must behave exactly as they did before this check existed. A false
 * positive here would turn working turns into error cards, which is a worse
 * regression than the bug.
 *
 * @group aincient_core
 */
#[RunTestsInSeparateProcesses]
final class TruncatedTurnTest extends KernelTestBase {

  use ScriptedInferenceTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'key',
    'aincient_core', 'aincient_inference_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installSchema('aincient_core', ['aincient_ai_usage']);
    $this->installConfig(['system']);
    $this->setUpCurrentUser();
    $this->connectScriptedProvider();
    $this->bindScriptedRole(ModelRoles::TASK);
  }

  /**
   * A response the provider cut off at the cap raises a `too_long` failure.
   */
  public function testTruncatedTurnRaises(): void {
    $this->scriptInferenceText('Here are the three sections you asked for. The first one');
    // Anthropic's spelling, mapped by its bridge onto the normalised case.
    $this->scriptInferenceFinishReason(FinishReasonCase::LENGTH, 'max_tokens');

    try {
      $this->container->get('aincient_core.inference.gateway')
        ->text('Write three sections.', ModelRoles::TASK, 'test');
      $this->fail('A truncated response came back as a normal answer.');
    }
    catch (AiProviderFailure $e) {
      // The kind is what the failure surface branches on, so it is the assertion
      // that matters — not the wording.
      $this->assertSame(ProviderCall::KIND_TOO_LONG, $e->getKind());
      // The reader is told what to do about it, and never in the provider's own
      // wire words.
      $this->assertStringContainsString('too long', $e->getMessage());
      $this->assertStringNotContainsString('max_tokens', $e->getMessage());
      // The provider's own wording survives where it helps: on the previous.
      $this->assertStringContainsString('max_tokens', (string) $e->getPrevious()?->getMessage());
    }
  }

  /**
   * A provider that reports no finish reason answers exactly as before.
   *
   * The false-positive guard, and the reason nothing is inferred from the shape
   * or the length of the answer: most of what we call reports nothing at all on
   * some paths, and every one of those turns is a good turn.
   */
  public function testUnreportedFinishReasonReturnsNormally(): void {
    $this->scriptInferenceText('A complete answer.');

    $this->assertSame(
      'A complete answer.',
      $this->container->get('aincient_core.inference.gateway')
        ->text('Say something.', ModelRoles::TASK, 'test'),
    );
  }

  /**
   * A clean stop answers normally, as does a reason we do not recognise.
   */
  public function testCleanAndUnknownStopsReturnNormally(): void {
    $gateway = $this->container->get('aincient_core.inference.gateway');

    $this->scriptInferenceText('A complete answer.');
    $this->scriptInferenceFinishReason(FinishReasonCase::STOP, 'end_turn');
    $this->assertSame('A complete answer.', $gateway->text('Say something.', ModelRoles::TASK, 'test'));

    // OTHER is what a bridge files for wording it has no equivalent for. It is
    // NOT evidence of a truncation, so it must not be treated as one.
    $this->scriptInferenceFinishReason(FinishReasonCase::OTHER, 'something_new');
    $this->assertSame('A complete answer.', $gateway->text('Say something.', ModelRoles::TASK, 'test'));
  }

  /**
   * The no-tools chat seam raises too — one check, all three inference paths.
   *
   * ChatCompleter, the gateway and the agent loop share exactly one provider
   * call, which is why the check lives there and not in three unpackers. This
   * pins that the sharing is real for the seam whose caller is a FlowDrop node.
   */
  public function testTheChatSeamRaisesToo(): void {
    $this->scriptInferenceText('A brand voice that stops mid');
    $this->scriptInferenceFinishReason(FinishReasonCase::LENGTH, 'length');

    $this->expectException(AiProviderFailure::class);
    $this->container->get('aincient_core.inference.chat_completer')->complete(
      message: 'Describe the brand voice.',
      operationType: 'aincient_role:' . ModelRoles::TASK,
    );
  }

}
