<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_core\Unit;

use Drupal\aincient_core\Hook\VersionRequirements;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Tests\UnitTestCase;

/**
 * Tests that the status report can name which Atelier this is.
 *
 * WHAT IS WORTH TESTING HERE is not the happy path — it is that an UNSTAMPED
 * build never claims a version. The whole point of the row is that a bug report
 * can be trusted, and a build that inherits a plausible-looking number is worse
 * than one that admits it has none: it sends us hunting a regression in a release
 * the reporter was never running. The ARG default (`dev`) and an empty string are
 * the two ways that can happen by accident, so both are asserted.
 *
 * @coversDefaultClass \Drupal\aincient_core\Hook\VersionRequirements
 * @group aincient_core
 */
final class VersionRequirementsTest extends UnitTestCase {

  /**
   * The env var the appliance image stamps.
   */
  private const ENV_VAR = 'ATELIER_VERSION';

  /**
   * Whatever the environment held before this test touched it.
   */
  private string|false $original;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->original = getenv(self::ENV_VAR);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    // A leaked putenv() would silently change what every later test in this
    // process sees.
    if ($this->original === FALSE) {
      putenv(self::ENV_VAR);
    }
    else {
      putenv(self::ENV_VAR . '=' . $this->original);
    }
    parent::tearDown();
  }

  /**
   * @covers ::runtimeRequirements
   */
  public function testReportsATaggedRelease(): void {
    $row = $this->rowFor('v0.1.0');

    $this->assertSame('v0.1.0', (string) $row['value']);
    $this->assertSame(RequirementSeverity::Info, $row['severity']);
    // A release is not described as a rolling build.
    $this->assertStringNotContainsStringIgnoringCase('rolling', (string) $row['description']);
  }

  /**
   * @covers ::runtimeRequirements
   *
   * The sha in the stamp is the whole value of an `:edge` bug report — it is what
   * makes the report locatable to an exact commit — so it must survive verbatim.
   */
  public function testReportsARollingBuildAsSuch(): void {
    $row = $this->rowFor('edge+a2f34d9');

    $this->assertSame('edge+a2f34d9', (string) $row['value']);
    $this->assertStringContainsStringIgnoringCase('rolling', (string) $row['description']);
  }

  /**
   * @covers ::runtimeRequirements
   *
   * @dataProvider unstampedProvider
   */
  public function testAnUnstampedBuildClaimsNoVersion(string|false $env, string $case): void {
    $row = $this->rowFor($env);

    $this->assertStringContainsStringIgnoringCase('development', (string) $row['value'], $case);
    // Never an error or a warning: on a developer's machine this is the correct
    // and permanent state, and a red line nobody can clear is one everybody
    // learns to scroll past — taking the pricing rows next to it along with it.
    $this->assertSame(RequirementSeverity::Info, $row['severity'], $case);
  }

  /**
   * The three ways a build can arrive with nothing to report.
   *
   * @return array<string, array{0: string|false, 1: string}>
   *   Env value, and the case it stands for.
   */
  public static function unstampedProvider(): array {
    return [
      'absent (DDEV, phpunit)' => [FALSE, 'no env var at all'],
      'the ARG default' => ['dev', 'built with no --build-arg'],
      'empty' => ['', 'stamped with an empty string'],
    ];
  }

  /**
   * Build the requirement row with the environment set to $env.
   *
   * @param string|false $env
   *   The stamp to expose, or FALSE to unset the variable entirely.
   *
   * @return array<string, mixed>
   *   The single requirement the hook returns.
   */
  private function rowFor(string|false $env): array {
    if ($env === FALSE) {
      putenv(self::ENV_VAR);
    }
    else {
      putenv(self::ENV_VAR . '=' . $env);
    }

    $hook = new VersionRequirements();
    $hook->setStringTranslation($this->getStringTranslationStub());
    $requirements = $hook->runtimeRequirements();

    $this->assertArrayHasKey('aincient_core_version', $requirements);

    return $requirements['aincient_core_version'];
  }

}
