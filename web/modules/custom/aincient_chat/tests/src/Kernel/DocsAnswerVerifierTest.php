<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_chat\Kernel;

use Aincient\Inference\ModelRoles;
use Drupal\aincient_chat\Chat\DocsAnswerVerifier;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\aincient_core\Traits\ScriptedInferenceTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Log\NullLogger;

/**
 * The docs answer check (0525): verdicts, revisions and the guards around them.
 *
 * The model is scripted, so this pins the CONTRACT around it — when it runs,
 * what a verdict does to the reply, and that a bad revision never reaches the
 * user. Whether the real model judges well is the live eval's job
 * (`drush aincient:docs-eval`).
 */
#[Group('aincient_chat')]
#[RunTestsInSeparateProcesses]
final class DocsAnswerVerifierTest extends KernelTestBase {

  use ScriptedInferenceTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'key', 'aincient_core', 'aincient_inference_test'];

  private const EVIDENCE = "Docs sections matching \"update\", best first.\n\n1. Quickstart — Upgrade\nhttps://aincient-labs.com/docs/quickstart#upgrade\nDesktop app. When a new version is ready, the app shows a banner with an Update button.";

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installSchema('aincient_core', ['aincient_ai_usage']);
    $this->installConfig(['system']);
    $this->connectScriptedProvider();
    $this->bindScriptedRole(ModelRoles::REASONING);
  }

  public function testNoEvidenceMeansNoCheck(): void {
    $this->scriptInferenceText('{"unsupported": ["x"], "revised": "should never be used"}');
    $result = $this->verifier()->verify('Hi! Here is the map.', []);
    $this->assertSame('skipped', $result['status']);
    $this->assertSame('Hi! Here is the map.', $result['text']);
  }

  public function testSupportedReplyIsUnchanged(): void {
    $this->scriptInferenceText("```json\n{\"unsupported\": [], \"revised\": \"\"}\n```");
    $answer = 'Click **Update** on the banner. [Upgrade](https://aincient-labs.com/docs/quickstart#upgrade)';
    $result = $this->verifier()->verify($answer, [self::EVIDENCE]);
    $this->assertSame('supported', $result['status']);
    $this->assertSame($answer, $result['text']);
  }

  public function testUnsupportedClaimIsRemoved(): void {
    $revised = "Click **Update** on the banner. [Upgrade](https://aincient-labs.com/docs/quickstart#upgrade)";
    $this->scriptInferenceTexts([
      json_encode(['unsupported' => ['You can also paste a Typeform embed into a markdown section.'], 'revised' => $revised]),
      // The re-check of the rewrite finds nothing left.
      '{"unsupported": [], "revised": ""}',
    ]);
    $answer = $revised . ' You can also paste a Typeform embed into a markdown section.';
    $result = $this->verifier()->verify($answer, [self::EVIDENCE]);
    $this->assertSame('revised', $result['status']);
    $this->assertSame($revised, $result['text']);
    $this->assertSame(['You can also paste a Typeform embed into a markdown section.'], $result['unsupported']);
  }

  public function testRewriteThatStillCarriesAClaimFallsBack(): void {
    // The rewrite keeps "Stripe" — the re-check catches it.
    $this->scriptInferenceTexts([
      json_encode(['unsupported' => ['Atelier takes payments'], 'revised' => 'Link to Stripe or Etsy to sell.']),
      json_encode(['unsupported' => ['Link to Stripe or Etsy'], 'revised' => 'x']),
    ]);
    $result = $this->verifier()->verify('Atelier takes payments. Link to Stripe or Etsy to sell.', [self::EVIDENCE]);
    $this->assertSame('revised', $result['status']);
    $this->assertStringContainsString("couldn't confirm that in the Atelier docs", $result['text']);
    $this->assertStringNotContainsString('Stripe', $result['text']);
  }

  public function testRevisionWithANewLinkFallsBackToTheHonestMinimum(): void {
    $this->scriptInferenceText(json_encode(['unsupported' => ['Shopify embed'], 'revised' => 'Try https://shopify.example/embed instead.']));
    $result = $this->verifier()->verify('Use a Shopify embed.', [self::EVIDENCE]);
    $this->assertSame('revised', $result['status']);
    $this->assertStringContainsString("couldn't confirm that in the Atelier docs", $result['text']);
    $this->assertStringContainsString('https://aincient-labs.com/docs/quickstart#upgrade', $result['text']);
    $this->assertStringNotContainsString('shopify.example', $result['text']);
  }

  public function testAFailedCheckFailsClosed(): void {
    // An unchecked reply is what the check exists to stop: unreadable verdict
    // or provider failure → the honest minimum, never the unchecked text.
    $this->scriptInferenceText('I think it is fine.');
    $result = $this->verifier()->verify('Paste a Typeform embed.', [self::EVIDENCE]);
    $this->assertSame('error', $result['status']);
    $this->assertStringContainsString("couldn't confirm that", $result['text']);
    $this->assertStringNotContainsString('Typeform', $result['text']);

    \Drupal::state()->set(\Drupal\aincient_inference_test\ScriptedAdapter::FAIL_KEY, TRUE);
    $result = $this->verifier()->verify('Paste a Typeform embed.', [self::EVIDENCE]);
    $this->assertSame('error', $result['status']);
    $this->assertStringNotContainsString('Typeform', $result['text']);
  }

  public function testFirstSourceReadsBothEvidenceShapes(): void {
    $this->assertSame('[Quickstart — Upgrade](https://aincient-labs.com/docs/quickstart#upgrade)', DocsAnswerVerifier::firstSource([self::EVIDENCE]));
    $this->assertSame('[Quickstart](https://aincient-labs.com/docs/quickstart)', DocsAnswerVerifier::firstSource(["Docs page: Quickstart\nhttps://aincient-labs.com/docs/quickstart\nInstall it."]));
    $this->assertSame('', DocsAnswerVerifier::firstSource(['No docs section matches "x".']));
  }

  public function testNamedSourcePicksTheHitTheReplyNames(): void {
    $hits = "Docs sections matching \"colours\", best first.\n"
      . "\n1. Building pages › Component library\nhttps://aincient-labs.com/docs/components\nChange a colour in the Identity room.\n"
      . "\n2. Identity › Editing your brand by hand — Preview, revert, publish\nhttps://aincient-labs.com/docs/brand-editor#preview-revert-publish\nPublish it.";
    $this->assertSame(
      '[Identity › Editing your brand by hand — Preview, revert, publish](https://aincient-labs.com/docs/brand-editor#preview-revert-publish)',
      DocsAnswerVerifier::namedSource('Colours and fonts live in **Identity**, in the **Site** menu.', [$hits]),
    );
    $this->assertStringContainsString('brand-editor', DocsAnswerVerifier::namedSource('Colours live in Identity, under Site.', [$hits]));
    // Small talk names nothing; a lower-case word inside a title is not a name.
    $this->assertSame('', DocsAnswerVerifier::namedSource('Hi! What can I help with?', [$hits]));
    $this->assertSame('', DocsAnswerVerifier::namedSource('Handing this to **Pages**.', [$hits]));
  }

  public function testEvidenceMarkersAndParsing(): void {
    $this->assertTrue(DocsAnswerVerifier::isEvidence(self::EVIDENCE));
    $this->assertTrue(DocsAnswerVerifier::isEvidence("Docs page: Quickstart\nhttps://aincient-labs.com/docs/quickstart"));
    $this->assertTrue(DocsAnswerVerifier::isEvidence('No docs section matches "shopify". Try other keywords.'));
    $this->assertFalse(DocsAnswerVerifier::isEvidence('{"__widget__":"data_table"}'));
    $this->assertNull(DocsAnswerVerifier::parse('no json here'));
    $this->assertNull(DocsAnswerVerifier::parse('{"revised": "x"}'));
    $this->assertSame(['unsupported' => ['a'], 'revised' => ''], DocsAnswerVerifier::parse('Sure: {"unsupported": ["a", ""], "revised": null}'));
    $this->assertTrue(DocsAnswerVerifier::linksAllowed('See https://aincient-labs.com/docs/x.', 'https://aincient-labs.com/docs/x'));
    $this->assertFalse(DocsAnswerVerifier::linksAllowed('See https://evil.example/', 'https://aincient-labs.com/docs/x'));
  }

  private function verifier(): DocsAnswerVerifier {
    return new DocsAnswerVerifier($this->container->get('aincient_core.inference.gateway'), new NullLogger());
  }

}
