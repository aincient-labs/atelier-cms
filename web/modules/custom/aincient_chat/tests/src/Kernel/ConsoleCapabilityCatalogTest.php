<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_chat\Kernel;

use Drupal\aincient_chat\Chat\WorkflowCatalog;
use Drupal\aincient_chat\Controller\ConsoleController;
use Drupal\aincient_core\ModelRoles;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\aincient_core\Traits\ScriptedInferenceTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The console catalog does NOT gate a room on what the install can do.
 *
 * THE REGRESSION THIS PINS. The Media studio's agent used to be dropped from
 * {@see ConsoleController::studioCatalog()} whenever the `image` model role was
 * unbound — a capability question answered as an access question, one level too
 * coarse: a chat rail whose only capability is words is still worth having
 * (filling a name and alt text from human input is a legitimate use), and the
 * gate removed a working room to protect one tool inside it. So the Media agent
 * must now be present in EVERY capability state, and capability must reach the
 * console only as the `capabilities` chips on the same payload.
 *
 * @group aincient
 * @covers \Drupal\aincient_chat\Controller\ConsoleController
 * @covers \Drupal\aincient_core\InstallCapabilities
 */
#[RunTestsInSeparateProcesses]
final class ConsoleCapabilityCatalogTest extends KernelTestBase {

  use ScriptedInferenceTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'node',
    'key',
    'aincient_core',
    'aincient_inference_test',
    'workflows',
    'content_moderation',
    'aincient_pages',
    'aincient_chat',
  ];

  /**
   * The studio config the console reads — media among the agent-bearing rooms.
   */
  protected function setUp(): void {
    parent::setUp();
    $this->config('aincient_chat.settings')
      ->set('studios', [
        'general' => ['agents' => ['op'], 'default' => 'op'],
        'media' => ['agents' => ['image_agent'], 'default' => 'image_agent'],
      ])
      ->save();
  }

  /**
   * A controller whose only stub is the workflow-entity storage.
   *
   * Everything that decides the outcome — the role bindings, the adapter
   * registry, the capability service — is the real thing from the container. The
   * workflow ENTITIES are mocked because flowdrop is not installable in a kernel
   * test, and which flows exist is not what is under test here.
   */
  private function controller(): ConsoleController {
    // The tools each mocked flow places on its canvas, as the config
    // dependencies a real flowdrop_workflow carries. This is what the per-room
    // verbs are derived from: General's agent lists pages, the Media agent makes
    // and reads pictures.
    $wired = [
      'op' => ['list_pages'],
      'image_agent' => ['generate_image', 'generate_alt_text', 'find_reference'],
    ];
    $entities = [];
    foreach ($wired as $id => $slugs) {
      $entity = $this->createMock(ConfigEntityInterface::class);
      $entity->method('id')->willReturn($id);
      $entity->method('label')->willReturn(ucfirst($id));
      $entity->method('getDependencies')->willReturn([
        'config' => array_map(
          static fn(string $slug): string => 'flowdrop_node_type.flowdrop_node_type.aincient_flows_aincient_capability_' . $slug,
          $slugs,
        ),
      ]);
      $entities[$id] = $entity;
    }
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('loadMultiple')->willReturn($entities);
    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('hasDefinition')->with('flowdrop_workflow')->willReturn(TRUE);
    $etm->method('getStorage')->with('flowdrop_workflow')->willReturn($storage);

    // An operator who holds every studio permission: access is decided here, and
    // only here — the point being that capability no longer joins in.
    $operator = $this->createMock(AccountInterface::class);
    $operator->method('hasPermission')->willReturn(TRUE);
    $operator->method('getDisplayName')->willReturn('Operator');
    $operator->method('id')->willReturn(1);

    return new ConsoleController(
      $this->container->get('renderer'),
      $operator,
      $this->container->get('module_handler'),
      new WorkflowCatalog(
        $this->container->get('config.factory'),
        $etm,
        $this->container->get('aincient_core.capability_verbs'),
      ),
      $this->container->get('menu.link_tree'),
      $this->container->get('config.factory'),
      $this->container->get('aincient_core.install_capabilities'),
      $this->container->get('aincient_chat.viewer_card'),
      $this->container->get('csrf_token'),
      $this->container->get('aincient_core.model_role_resolver'),
      $this->container->get('aincient_core.inference.registry'),
    );
  }

  /**
   * The private studio catalog of a hand-built controller.
   *
   * @return array<string, mixed>
   */
  private function studioCatalog(): array {
    $method = new \ReflectionMethod(ConsoleController::class, 'studioCatalog');
    $method->setAccessible(TRUE);
    return (array) $method->invoke($this->controller());
  }

  /**
   * Put the site into one of the four capability states.
   *
   * @param bool $chat
   *   Bind (and connect) a chat model — the Write verb.
   * @param bool $vision
   *   Pin the vision role — the Describe verb.
   * @param bool $image
   *   Bind an image model — the Draw verb.
   */
  private function configure(bool $chat, bool $vision, bool $image): void {
    if ($chat || $vision || $image) {
      $this->connectScriptedProvider();
    }
    if ($chat) {
      $this->bindScriptedRole(ModelRoles::TASK);
    }
    if ($vision) {
      $this->bindScriptedRole(ModelRoles::VISION);
    }
    if ($image) {
      $this->bindScriptedRole(ModelRoles::IMAGE, 'scripted-image');
    }
  }

  /**
   * The four capability states a real install passes through.
   *
   * @return iterable<string, array{bool, bool, bool}>
   */
  public static function capabilityStates(): iterable {
    yield 'fresh install — nothing connected' => [FALSE, FALSE, FALSE];
    yield 'words only — chat, no pictures' => [TRUE, FALSE, FALSE];
    yield 'chat + vision, no image provider' => [TRUE, TRUE, FALSE];
    yield 'fully capable' => [TRUE, TRUE, TRUE];
  }

  /**
   * THE REGRESSION: the Media agent is in the catalog in all four states.
   */
  #[DataProvider('capabilityStates')]
  public function testMediaAgentIsAlwaysInTheCatalog(bool $chat, bool $vision, bool $image): void {
    $this->configure($chat, $vision, $image);

    $catalog = $this->studioCatalog();
    $this->assertArrayHasKey('media', $catalog, 'The Media room is never removed for what it cannot do.');
    $this->assertSame('image_agent', $catalog['media']['default']);
    $this->assertSame(['image_agent'], array_column($catalog['media']['agents'], 'id'));
  }

  /**
   * Capability reaches the console as chips instead — three, always, in order.
   */
  #[DataProvider('capabilityStates')]
  public function testChipsReportTheStateTheCatalogNoLongerHides(bool $chat, bool $vision, bool $image): void {
    $this->configure($chat, $vision, $image);

    $chips = $this->container->get('aincient_core.install_capabilities')->chips();
    $available = array_column($chips, 'available', 'id');

    $this->assertSame(['write', 'describe', 'draw'], array_keys($available));
    $this->assertSame($chat, $available['write']);
    // Describe needs the vision role EXPLICITLY pinned: it resolves with a
    // fallback to the chat tier, and nobody ever said the chat tier can see.
    $this->assertSame($vision, $available['describe']);
    $this->assertSame($image, $available['draw']);
  }

  /**
   * WHICH chips a room shows is the room's own business, in every state.
   *
   * The second half of the same design: the chips are computed install-wide
   * (above), and each room then shows the ones it could actually use. General
   * wires no image tool, so it never raises pictures — it used to say
   * "Draw — needs an image provider" in a room where connecting one would have
   * changed nothing. Media, which wires both, raises all three. Derived from the
   * agents' own placed capability nodes, so this holds whatever the install can
   * or cannot do.
   */
  #[DataProvider('capabilityStates')]
  public function testEachRoomOnlyRaisesTheVerbsItsToolsSpend(bool $chat, bool $vision, bool $image): void {
    $this->configure($chat, $vision, $image);

    $catalog = $this->studioCatalog();

    $this->assertSame(['write'], $catalog['general']['verbs']);
    $this->assertSame(['write'], $catalog['general']['agents'][0]['verbs']);
    $this->assertSame(['write', 'describe', 'draw'], $catalog['media']['verbs']);
    $this->assertSame(['write', 'describe', 'draw'], $catalog['media']['agents'][0]['verbs']);
  }

  /**
   * The other dim reason ships with every chip, whatever the install state.
   *
   * A chip can be dim because the install cannot (`hint`, fixable, links to the
   * wizard) or because the room's agent has no tool for it (`unusedHint`, a fact
   * about the room, links nowhere). The console picks between them, so it needs
   * both — including on a fully capable install, which is exactly when an unused
   * verb is the ONLY thing that can dim a chip.
   */
  public function testEveryChipCarriesTheRoomReasonToo(): void {
    $this->configure(TRUE, TRUE, TRUE);

    $byId = array_column(
      $this->container->get('aincient_core.install_capabilities')->chips(),
      NULL,
      'id',
    );

    $this->assertSame('', $byId['draw']['hint'], 'Nothing to fix on this install…');
    $this->assertSame('this chat doesn’t make images', $byId['draw']['unusedHint'], '…but a room without the tool still has something to say.');
    $this->assertSame('this chat doesn’t read images', $byId['describe']['unusedHint']);
  }

  /**
   * A needs-setup chip re-enters the onboarding wizard, from the route table.
   *
   * Not the models form: a missing capability is a provider nobody connected,
   * and that form only binds roles to providers you already have.
   */
  public function testNeedsSetupChipsLinkToTheOnboardingWizard(): void {
    $this->asUser(TRUE);
    $this->configure(TRUE, FALSE, FALSE);

    $chips = $this->container->get('aincient_core.install_capabilities')->chips();
    $urls = array_column($chips, 'setupUrl', 'id');

    $this->assertSame('', $urls['write'], 'A kept promise links nowhere.');
    $this->assertStringEndsWith('/atelier?onboarding=1', $urls['draw']);
    $this->assertStringEndsWith('/atelier?onboarding=1', $urls['describe']);
  }

  /**
   * To everyone else the chip is plain text: the hint, with nowhere to go.
   *
   * `?onboarding=1` is honoured for `administer site configuration` only, so a
   * link offered to anyone else would land them on an ordinary console with
   * nothing to do. The explanation is the part a non-admin can use.
   */
  public function testNeedsSetupChipsDoNotLinkForNonAdmins(): void {
    $this->asUser(FALSE);
    $this->configure(TRUE, FALSE, FALSE);

    $chips = $this->container->get('aincient_core.install_capabilities')->chips();
    $byId = array_column($chips, NULL, 'id');

    $this->assertSame('', $byId['draw']['setupUrl']);
    $this->assertFalse($byId['draw']['available'], 'Still dimmed…');
    $this->assertSame('needs an image provider', $byId['draw']['hint'], '…and still explained.');
  }

  /**
   * Swap the current user for one that does — or does not — administer the site.
   */
  private function asUser(bool $admin): void {
    $account = $this->createMock(AccountProxyInterface::class);
    $account->method('hasPermission')->willReturn($admin);
    $this->container->set('current_user', $account);
  }

  /**
   * The agent is told the same thing the chips show — one source, two renderings.
   */
  public function testTheAgentPromptAgreesWithTheChips(): void {
    $this->configure(TRUE, FALSE, FALSE);
    $line = $this->container->get('aincient_core.install_capabilities')->promptLine();

    $this->assertStringContainsString('You can write', $line);
    $this->assertStringContainsString('cannot generate images', $line);

    $this->configure(TRUE, TRUE, TRUE);
    $full = $this->container->get('aincient_core.install_capabilities')->promptLine();
    $this->assertStringNotContainsString('You cannot', $full);
  }

  /**
   * The Twig seam an agent's Prompt Template calls returns that same block.
   */
  public function testTwigFunctionRendersTheCapabilityBlock(): void {
    $this->configure(TRUE, FALSE, FALSE);

    $rendered = (string) $this->container->get('twig')
      ->createTemplate('{{ install_capabilities() }}')
      ->render();

    $this->assertStringContainsString('CAPABILITIES OF THIS INSTALL', $rendered);
    $this->assertStringContainsString('cannot generate images', $rendered);
  }

}
