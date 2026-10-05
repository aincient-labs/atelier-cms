<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_studio_components\Kernel;

use Drupal\aincient_studio_components\ComponentProposalApplier;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The Components agent's one verb (DECISIONS 0455, P2).
 *
 * The applier is the single gate between the model's raw tool args and the
 * studio draft: only names the DISCOVERED vocabulary knows reach the client,
 * kind-only ops are refused in the site scope, a recipe kind and an unknown
 * scope refuse outright, and nothing-valid is an error naming what was not
 * recognised. The capability wrapper adds the studio permission gate.
 *
 * @group aincient
 */
#[RunTestsInSeparateProcesses]
final class ComponentProposalApplierTest extends KernelTestBase {

  use UserCreationTrait;

  protected static $modules = [
    'system', 'user', 'field', 'filter', 'text', 'node', 'key',
    'workflows', 'content_moderation', 'aincient_core', 'aincient_pages',
    'aincient_chat', 'aincient_studio_components',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['workflows', 'content_moderation', 'aincient_pages']);
  }

  private function applier(): ComponentProposalApplier {
    return $this->container->get('aincient_studio_components.proposal_applier');
  }

  private function apply(string $scope, mixed $changes, string $reason = ''): array {
    return $this->applier()->apply([
      'scope' => $scope,
      'changes_json' => is_string($changes) ? $changes : json_encode($changes, JSON_THROW_ON_ERROR),
      'reason' => $reason,
    ]);
  }

  /**
   * Site scope on/off: known names kept, unknown names rejected (not fatal).
   */
  public function testSiteTurnOffAndOn(): void {
    $out = $this->apply('site', ['turn_off' => ['newsletter', 'nope'], 'turn_on' => ['pricing', 'block']]);
    $this->assertArrayNotHasKey('error', $out);
    $this->assertSame('components_proposal', $out['__widget__']);
    $this->assertSame('site', $out['payload']['scope']);
    $this->assertSame(['newsletter'], $out['payload']['turn_off']);
    $this->assertSame(['pricing', 'block'], $out['payload']['turn_on'], 'The virtual `block` placeable is a valid name.');
    $this->assertSame(['turn_off.nope'], $out['rejected']);
    $this->assertStringContainsString('everywhere', $out['summary']);
    $this->assertStringContainsString('turn off newsletter', $out['summary']);
  }

  /**
   * Variants are checked against the component's own discovered variants.
   */
  public function testVariantsCheckedPerComponent(): void {
    $out = $this->apply('site', ['variants_off' => ['hero' => ['split', 'diagonal'], 'ghost' => ['x']]]);
    $this->assertSame(['hero' => ['split']], $out['payload']['variants_off']);
    $this->assertContains('variants_off.hero.diagonal', $out['rejected']);
    $this->assertContains('variants_off.ghost', $out['rejected']);
  }

  /**
   * `*` (every component) is a site-scope tone wildcard only.
   */
  public function testToneWildcardIsSiteOnly(): void {
    $site = $this->apply('site', ['tones_off' => ['*' => ['inverted', 'neon']]]);
    $this->assertSame(['*' => ['inverted']], $site['payload']['tones_off']);
    $this->assertSame(['tones_off.*.neon'], $site['rejected']);

    $kind = $this->apply('landing', ['tones_off' => ['*' => ['inverted'], 'stats' => ['inverted']]]);
    $this->assertSame(['stats' => ['inverted']], $kind['payload']['tones_off']);
    $this->assertSame(['tones_off.*'], $kind['rejected']);
  }

  /**
   * Kind-only keys are refused in the site scope and accepted for a kind.
   */
  public function testKindOnlyKeys(): void {
    $kindOps = ['include_new' => FALSE, 'opener' => 'hero', 'limits' => ['cta' => 1, 'stats' => 0]];

    $site = $this->apply('site', $kindOps + ['turn_off' => ['pricing']]);
    $this->assertSame(['scope' => 'site', 'turn_off' => ['pricing']], $site['payload']);
    $this->assertEqualsCanonicalizing(
      ['include_new (page types only)', 'opener (page types only)', 'limits (page types only)'],
      $site['rejected'],
    );

    $kind = $this->apply('landing', $kindOps);
    $this->assertArrayNotHasKey('rejected', $kind);
    $this->assertSame('landing', $kind['payload']['scope']);
    $this->assertFalse($kind['payload']['include_new']);
    $this->assertSame('hero', $kind['payload']['opener']);
    $this->assertSame(['cta' => 1, 'stats' => 0], $kind['payload']['limits'], 'A 0 limit is kept (it clears the limit).');
    $this->assertStringContainsString('Landing page', $kind['summary']);

    // "" clears the opener; an unknown opener / negative limit is rejected.
    $clear = $this->apply('landing', ['opener' => '', 'limits' => ['cta' => -1, 'ghost' => 2]]);
    $this->assertSame('', $clear['payload']['opener']);
    $this->assertArrayNotHasKey('limits', $clear['payload']);
    $this->assertEqualsCanonicalizing(['limits.cta', 'limits.ghost'], $clear['rejected']);
  }

  /**
   * The block fragment kind is a valid scope.
   */
  public function testBlockKindIsAScope(): void {
    $out = $this->apply('block', ['turn_off' => ['hero']]);
    $this->assertSame('block', $out['payload']['scope'] ?? NULL, json_encode($out));
  }

  /**
   * Unknown scope and recipe kind refuse outright.
   */
  public function testScopeErrors(): void {
    $unknown = $this->apply('nope', ['turn_off' => ['hero']]);
    $this->assertSame(['error'], array_keys($unknown));
    $this->assertStringContainsString('unknown scope "nope"', $unknown['error']);

    $recipe = $this->apply('blog', ['turn_off' => ['hero']]);
    $this->assertSame(['error'], array_keys($recipe));
    $this->assertStringContainsString('fixed layout', $recipe['error']);
  }

  /**
   * Malformed, empty and list JSON are errors.
   */
  public function testMalformedChangesAreErrors(): void {
    foreach (['{not json', '', '{}', '[]', '["hero"]', '"hero"'] as $raw) {
      $out = $this->apply('site', $raw);
      $this->assertSame(['error'], array_keys($out), "Input: $raw");
      $this->assertStringContainsString('JSON object', $out['error'], "Input: $raw");
    }
  }

  /**
   * Nothing valid → an error naming what was not recognised.
   */
  public function testNothingValidNamesTheRejects(): void {
    $out = $this->apply('site', ['turn_off' => ['ghost'], 'opener' => 'hero', 'bogus' => 1]);
    $this->assertSame(['error'], array_keys($out));
    $this->assertStringContainsString('nothing valid to propose', $out['error']);
    $this->assertStringContainsString('turn_off.ghost', $out['error']);
    $this->assertStringContainsString('opener (page types only)', $out['error']);
    $this->assertStringContainsString('bogus', $out['error']);
  }

  /**
   * The reason is trimmed and capped at 280 characters.
   */
  public function testReasonTruncated(): void {
    $out = $this->apply('site', ['turn_off' => ['pricing']], '  ' . str_repeat('é', 400) . '  ');
    $this->assertSame(280, mb_strlen($out['payload']['reason']));

    $none = $this->apply('site', ['turn_off' => ['pricing']]);
    $this->assertArrayNotHasKey('reason', $none['payload']);
  }

  /**
   * The capability refuses without the studio permission.
   */
  public function testCapabilityRefusesWithoutPermission(): void {
    $this->setUpCurrentUser();
    $out = $this->invoke(['scope' => 'site', 'changes_json' => '{"turn_off":["pricing"]}']);
    $this->assertStringStartsWith('Error: you do not have permission', $out);
  }

  /**
   * With the permission it emits the widget envelope as JSON.
   */
  public function testCapabilityEmitsTheEnvelope(): void {
    $this->setUpCurrentUser([], ['use aincient studio components']);
    $out = $this->invoke(['scope' => 'site', 'changes_json' => '{"turn_off":["pricing"]}', 'reason' => 'Too salesy.']);
    $envelope = json_decode($out, TRUE);
    $this->assertSame('components_proposal', $envelope['__widget__'] ?? NULL, $out);
    $this->assertSame(['pricing'], $envelope['payload']['turn_off']);
    $this->assertSame('Too salesy.', $envelope['payload']['reason']);

    // An applier error comes through as plain text, not JSON.
    $err = $this->invoke(['scope' => 'blog', 'changes_json' => '{"turn_off":["pricing"]}']);
    $this->assertStringStartsWith('Error:', $err);
  }

  /** Run the capability with the given context, returning its output. */
  private function invoke(array $context): string {
    $tool = $this->container->get('plugin.manager.aincient.capabilities')
      ->createInstance('aincient_studio_components:propose_component_constraint');
    foreach ($context as $name => $value) {
      $tool->setContextValue($name, $value);
    }
    $tool->execute();
    return $tool->getReadableOutput();
  }

}
