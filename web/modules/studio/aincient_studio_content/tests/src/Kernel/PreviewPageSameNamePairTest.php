<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_studio_content\Kernel;

use Drupal\aincient_studio_content\Plugin\AiCapability\PreviewPage;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The page agent's own tool on a page that needs switching (DECISIONS 0539).
 *
 * The draft still holds the built-in `testimonials`; the landing kind offers
 * the pair pack's. The tool's dry run must hand the agent the refusal with the
 * switch step (short names only), and apply a switch followed by the edit.
 *
 * @group aincient
 */
#[RunTestsInSeparateProcesses]
final class PreviewPageSameNamePairTest extends KernelTestBase {

  use UserCreationTrait;

  protected static $modules = [
    'system', 'user', 'node', 'field', 'text', 'key', 'aincient_core',
    'workflows', 'content_moderation', 'atelier', 'aincient_pages',
    'filter', 'aincient_chat', 'aincient_studio_content',
    'atelier_test_pair_pack',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->setCurrentUser($this->createUser(['administer aincient pages']));
    PreviewPage::resetChain();
  }

  private function dryRun(array $ops): array {
    /** @var \Drupal\aincient_core\Capability\ExecutableCapabilityInterface $tool */
    $tool = $this->container->get('plugin.manager.aincient.capabilities')->createInstance('aincient_studio_content:preview_page');
    $tool->setContextValue('ops', $ops);
    $tool->setContextValue('page_state', [
      'live_page_state' => (string) json_encode([
        'type' => 'landing',
        'title' => 'Proof',
        'sections' => [
          ['id' => 'hero0001', 'component' => 'hero', 'props' => ['heading' => 'Hi']],
          ['id' => 'quot0001', 'component' => 'atelier:testimonials', 'props' => ['heading' => 'Kind words', 'quotes' => [['quote' => 'Solid.', 'author' => 'Grace']]]],
        ],
      ]),
      'working_node_id' => 7,
      'context_policy' => 'irrelevant',
    ]);
    $tool->execute();
    $envelope = json_decode($tool->getReadableOutput(), TRUE);
    $this->assertIsArray($envelope, $tool->getReadableOutput());
    return $envelope;
  }

  /**
   * An edit comes back refused with the switch step, in short names.
   */
  public function testAnEditIsRefusedWithTheSwitchStep(): void {
    $envelope = $this->dryRun([['op' => 'update_section', 'id' => 'hero0001', 'props' => ['heading' => 'Changed']]]);
    $this->assertSame(0, $envelope['applied']);
    $this->assertSame(1, $envelope['refused_count']);
    $reason = $envelope['refused'][0]['reason'];
    $this->assertStringContainsString('older version of 1 "testimonials" section', $reason);
    $this->assertStringContainsString('Ask the owner', $reason);
    $this->assertStringContainsString('{"op":"switch_components"}', $reason);
    $this->assertStringNotContainsString('atelier:', $reason);
  }

  /**
   * After the owner agreed: switch, then the edit, both land.
   */
  public function testTheSwitchThenTheEditBothLand(): void {
    $envelope = $this->dryRun([
      ['op' => 'switch_components'],
      ['op' => 'update_section', 'id' => 'quot0001', 'props' => ['quote' => 'It just works.', 'author' => 'Ada']],
    ]);
    $this->assertSame(2, $envelope['applied'], json_encode($envelope['refused']));
    $this->assertSame(0, $envelope['refused_count']);
    $this->assertSame(['hero', 'testimonials'], array_column($envelope['sections_now'], 'component'));
  }

}
