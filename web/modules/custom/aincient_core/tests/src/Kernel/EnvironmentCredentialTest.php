<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_core\Kernel;

use Drupal\aincient_core\Inference\PlatformRegistryInterface;
use Drupal\aincient_inference_test\ScriptedAdapter;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Pins the environment as a first-class credential store.
 *
 * A deployment must be able to supply a provider's key without writing it to the
 * database: State reaches `sql:dump`, and a dump reaches any image built from it.
 * Before this existed, expressing that took an `env`-provider Key entity PLUS an
 * `aincient.provider.<id>` config pointer — three moving parts to say "read this
 * variable", and a chain no test covered, which is how it came within one
 * condition of being deleted as dead code.
 *
 * The tests are written against the RESOLVER, not the storage: what matters is
 * that `isConnected()` and the credential handed to the adapter come out right,
 * because those are what decide whether the product can serve a turn.
 *
 * @group aincient
 * @coversDefaultClass \Drupal\aincient_core\Inference\PlatformRegistry
 */
#[RunTestsInSeparateProcesses]
final class EnvironmentCredentialTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'key',
    'aincient_core',
    'aincient_inference_test',
  ];

  /**
   * The variable the scripted adapter's credential is read from.
   */
  private const ENV_KEY = 'ATELIER_SCRIPTED_TEST_API_KEY';

  /**
   * The variable its base URL is read from.
   */
  private const ENV_ENDPOINT = 'ATELIER_SCRIPTED_TEST_ENDPOINT';

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    // Leaking a variable into a sibling test would make this suite order-
    // dependent in the one place where a false positive means "the product
    // resolves a credential it should not".
    putenv(self::ENV_KEY);
    putenv(self::ENV_ENDPOINT);
    parent::tearDown();
  }

  /**
   * The registry under test, from the real container.
   */
  private function registry(): PlatformRegistryInterface {
    return $this->container->get('aincient_core.inference.registry');
  }

  /**
   * A provider with only an environment variable is connected.
   *
   * The whole feature in one assertion: nothing in State, nothing in config, no
   * Key entity — and the site can serve the provider.
   */
  public function testEnvironmentAloneConnectsAProvider(): void {
    $this->assertFalse($this->registry()->isConnected(ScriptedAdapter::PROVIDER_ID));

    putenv(self::ENV_KEY . '=sk-from-environment');

    $this->assertTrue($this->registry()->isConnected(ScriptedAdapter::PROVIDER_ID));
    $this->assertNull($this->container->get('state')->get(ScriptedAdapter::CREDENTIAL_KEY));
  }

  /**
   * The environment out-ranks a value stored in State.
   *
   * The precedence that makes rotation work: an operator who changes the
   * variable and restarts must get the new key, not a copy something wrote to
   * State once. Proved through the adapter, which only enumerates models for the
   * credential it is actually handed.
   */
  public function testEnvironmentWinsOverState(): void {
    $state = $this->container->get('state');
    $state->set(ScriptedAdapter::CREDENTIAL_KEY, 'sk-stale-in-state');
    $state->set(ScriptedAdapter::VALID_CREDENTIAL_KEY, 'sk-rotated');

    // With State alone the stale value is what reaches the adapter, and it is
    // not the credential the provider accepts.
    $this->assertSame([], $this->registry()->chatModels(ScriptedAdapter::PROVIDER_ID));

    putenv(self::ENV_KEY . '=sk-rotated');

    $this->assertNotSame(
      [],
      $this->registry()->chatModels(ScriptedAdapter::PROVIDER_ID),
      'The rotated environment value should reach the adapter, not the stale State copy.',
    );
  }

  /**
   * An empty variable means "not supplied", never "supplied as empty".
   *
   * The ordinary shape of a compose file is a declared variable with a blank
   * value. Treating that as a credential would make a provider claim to be
   * connected with no key, which is the exact over-reporting ProviderInventory
   * exists to prevent.
   */
  public function testEmptyVariableIsAbsence(): void {
    $this->container->get('state')->set(ScriptedAdapter::CREDENTIAL_KEY, 'sk-stored');
    putenv(self::ENV_KEY . '=   ');

    $this->assertTrue($this->registry()->isConnected(ScriptedAdapter::PROVIDER_ID));
    $this->assertFalse($this->registry()->isEnvironmentManaged(ScriptedAdapter::PROVIDER_ID));
  }

  /**
   * Either half being environment-supplied marks the provider as managed.
   *
   * Half-managing is the dangerous state: a disconnect that removes the stored
   * half would report success and leave the provider working.
   */
  public function testEitherHalfMarksTheProviderManaged(): void {
    $this->assertFalse($this->registry()->isEnvironmentManaged(ScriptedAdapter::PROVIDER_ID));

    putenv(self::ENV_ENDPOINT . '=https://proxy.example/v1');
    $this->assertTrue($this->registry()->isEnvironmentManaged(ScriptedAdapter::PROVIDER_ID));

    putenv(self::ENV_ENDPOINT);
    putenv(self::ENV_KEY . '=sk-from-environment');
    $this->assertTrue($this->registry()->isEnvironmentManaged(ScriptedAdapter::PROVIDER_ID));
  }

  /**
   * The inventory rows carry the fact, so the console never recomputes it.
   *
   * Every picker reads these rows; a second implementation of "is this managed?"
   * is how the two answers drift.
   */
  public function testInventoryRowsReportManagedState(): void {
    putenv(self::ENV_KEY . '=sk-from-environment');

    /** @var \Drupal\aincient_core\Inference\ProviderInventory $inventory */
    $inventory = $this->container->get('aincient_core.inference.provider_inventory');
    $row = $inventory->providers()[ScriptedAdapter::PROVIDER_ID] ?? NULL;

    $this->assertIsArray($row);
    $this->assertTrue($row['connected']);
    $this->assertTrue($row['managed']);
    $this->assertTrue($inventory->isEnvironmentManaged(ScriptedAdapter::PROVIDER_ID));
  }

  /**
   * An unrelated provider is unaffected by another's variable.
   *
   * The convention is per-provider; a single variable lighting up the whole
   * adapter set would be the `AINCIENT_AI_KEY` mistake again (DECISIONS 0149).
   */
  public function testTheConventionIsPerProvider(): void {
    putenv(self::ENV_KEY . '=sk-from-environment');

    foreach (array_keys($this->registry()->adapters()) as $id) {
      if ($id === ScriptedAdapter::PROVIDER_ID) {
        continue;
      }
      $this->assertFalse(
        $this->registry()->isEnvironmentManaged($id),
        sprintf('Provider "%s" should not be managed by another provider\'s variable.', $id),
      );
    }
    // Without this the loop above would pass vacuously on a one-adapter set.
    $this->assertGreaterThan(1, count($this->registry()->adapters()));
  }

}
