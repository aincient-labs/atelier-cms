<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_core\Functional;

use Drupal\aincient_inference_test\ScriptedAdapter;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Guards what the models page OFFERS, and what it says about what it cannot.
 *
 * The page is where an operator binds every role, so an option here is a promise
 * the product has to keep. Two failures are worth a browser test rather than a
 * unit one, because both are things the operator SEES:
 *
 * - offering a provider the site cannot serve (it used to list every installed
 *   `drupal/ai` provider, four of which had no adapter — binding one threw on the
 *   next turn);
 * - dropping a saved binding that has become unservable, silently. The value has
 *   to stay in the select AND be labelled, with a remedy — a binding that quietly
 *   disappears from a settings form is indistinguishable from one the operator
 *   never made.
 *
 * @group aincient
 */
#[RunTestsInSeparateProcesses]
final class ModelRolesFormTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['key', 'aincient_core', 'aincient_inference_test'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The models form path.
   */
  private const PATH = '/admin/config/aincient/models';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->drupalLogin($this->drupalCreateUser(['administer site configuration']));
    // One connected provider, so the pickers have something real to offer.
    $this->container->get('state')->set(ScriptedAdapter::CREDENTIAL_KEY, 'sk-stored');
  }

  /**
   * The pickers offer models from connected providers, and the form saves.
   */
  public function testBindsARoleFromAConnectedProvider(): void {
    $this->drupalGet(self::PATH);
    $this->assertSession()->statusCodeEquals(200);

    $value = ScriptedAdapter::PROVIDER_ID . ':scripted-chat';
    $this->assertSession()->optionExists('roles[task]', $value);
    // Image is a separate pool, fed only by providers that can draw.
    $this->assertSession()->optionExists('image_model', ScriptedAdapter::PROVIDER_ID . ':scripted-image');

    $this->submitForm([
      'roles[task]' => $value,
      'default_role' => 'task',
    ], 'Save configuration');
    $this->assertSession()->statusCodeEquals(200);

    $roles = $this->config('aincient_core.model_roles')->get('roles');
    $this->assertSame(ScriptedAdapter::PROVIDER_ID, $roles['task']['provider_id']);
    $this->assertSame('scripted-chat', $roles['task']['model_id']);
    // Saving no longer projects onto drupal/ai's operation-type defaults — that
    // write lost its last reader when the ai_provider_* modules went, and the
    // binding above is what the site resolves through.
    $this->assertNull($this->config('ai.settings')->get('default_providers'));
  }

  /**
   * No provider the site cannot serve is on offer. THE COVERAGE GAP.
   */
  public function testUnservableProvidersAreNotOffered(): void {
    $this->drupalGet(self::PATH);

    // Ids with no adapter on this site. `mistral`, `openai` and `ollama` used to
    // be the examples here and are now servable — the list is "cannot be served",
    // and an id acquiring an adapter is supposed to leave it.
    foreach (['openrouter:anthropic/claude', 'litellm:gpt-5', 'huggingface:x'] as $value) {
      $this->assertSession()->optionNotExists('roles[task]', $value);
    }
  }

  /**
   * A saved binding to an unservable provider degrades VISIBLY, with a remedy.
   *
   * The operator who bound a vendor this site cannot serve. Their choice must still
   * be in the select (so nothing is lost, and a save does not silently rebind them),
   * it must be labelled as unavailable rather than sitting among working options,
   * and the page must name the role, the provider, and what to do instead.
   *
   * The example is `openrouter` because the original one, `mistral`, acquired an
   * adapter — which is the happier end of the same story, and why this case has to
   * be pinned on an id that is still genuinely orphaned.
   */
  public function testAnOrphanedBindingIsKeptAndExplained(): void {
    $this->config('aincient_core.model_roles')
      ->set('roles.task', ['provider_id' => 'openrouter', 'model_id' => 'anthropic/claude-opus-5'])
      ->save();

    $this->drupalGet(self::PATH);
    $this->assertSession()->statusCodeEquals(200);

    // Kept, selected, and NOT quietly dropped.
    $this->assertSession()->optionExists('roles[task]', 'openrouter:anthropic/claude-opus-5');
    $this->assertSession()->fieldValueEquals('roles[task]', 'openrouter:anthropic/claude-opus-5');
    // Labelled as what it is.
    $this->assertSession()->pageTextContains('Bound previously');
    // Named, so the warning is actionable rather than mysterious.
    $this->assertSession()->pageTextContains('task → openrouter');
    // And pointed at the path that still works for this vendor.
    $this->assertSession()->pageTextContains('OpenAI-compatible endpoint');
  }

  /**
   * With NOTHING connected, an orphaned binding is still shown and explained.
   *
   * The worst version of the case — the operator whose AI stopped working entirely,
   * because the only provider they ever configured is one this site can no longer
   * serve. There is no working option to offer them, which is exactly why the page
   * must still name what is bound and what to do; a bare "no providers connected"
   * would leave them with no idea that their saved configuration is the problem.
   */
  public function testAnOrphanedBindingIsExplainedWithNothingConnected(): void {
    $this->container->get('state')->delete(ScriptedAdapter::CREDENTIAL_KEY);
    $this->config('aincient_core.model_roles')
      ->set('roles.reasoning', ['provider_id' => 'openrouter', 'model_id' => 'anthropic/claude'])
      ->save();

    $this->drupalGet(self::PATH);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('reasoning → openrouter');
    $this->assertSession()->pageTextContains('Bound previously');
    $this->assertSession()->fieldValueEquals('roles[reasoning]', 'openrouter:anthropic/claude');
    // No working provider is invented to fill the gap.
    $this->assertSession()->optionNotExists('roles[reasoning]', ScriptedAdapter::PROVIDER_ID . ':scripted-chat');
  }

}
