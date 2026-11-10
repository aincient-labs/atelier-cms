<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_flows\Kernel;

use Drupal\aincient_flows\Hook\ChatBindingOnSave;
use Drupal\Tests\flowdrop\Kernel\WorkflowExecutionTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * A chat-shaped workflow saved without a chat binding gets one (FlowDrop 2.7).
 *
 * @see \Drupal\aincient_flows\Hook\ChatBindingOnSave
 */
#[RunTestsInSeparateProcesses]
final class ChatBindingOnSaveTest extends WorkflowExecutionTestBase {

  use AgentEngineFixtureTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'filter',
    'node',
    'key',
    'file',
    'workflows',
    'content_moderation',
    'language',
    'content_translation',
    'flowdrop_memory',
    'flowdrop_playground',
    'aincient_core',
    'aincient_pages',
    'aincient_onboarding',
    'aincient_flows',
  ];

  protected function setUp(): void {
    parent::setUp();
    foreach (['chat_input', 'chat_output'] as $id) {
      $this->importNodeType($id);
    }
  }

  public function testOwnerBuiltChatFlowIsBoundOnSave(): void {
    $workflow = $this->echoFlow('owner_echo');
    $chat = $workflow->getThirdPartySetting('flowdrop_playground', 'chat');
    $this->assertIsArray($chat, 'A Chat Input → Chat Output flow saved without settings must get a chat binding.');
    $this->assertNotEmpty($chat['message']);
    $this->assertSame([['node_id' => 'chat_output.1', 'port' => 'message']], $chat['replies']);
  }

  public function testConfigImportIsLeftVerbatim(): void {
    $workflow = $this->container->get('entity_type.manager')->getStorage('flowdrop_workflow')->create([
      'id' => 'imported_echo',
      'label' => 'Imported echo',
      'nodes' => $this->echoNodes(),
      'edges' => [$this->edge('chat_input.1', 'message', 'chat_output.1', 'message')],
    ]);
    // Called directly: an import writes the YAML as-is, past FlowDrop's own
    // save-time normalisation, so a real syncing save needs real YAML.
    $workflow->setSyncing(TRUE);
    (new ChatBindingOnSave())->presave($workflow);
    $this->assertNull($workflow->getThirdPartySetting('flowdrop_playground', 'chat'));
    $workflow->setSyncing(FALSE);
    (new ChatBindingOnSave())->presave($workflow);
    $this->assertIsArray($workflow->getThirdPartySetting('flowdrop_playground', 'chat'), 'The same flow outside an import is bound.');
  }

  public function testExistingBindingIsKept(): void {
    $workflow = $this->echoFlow('bound_echo');
    $chat = $workflow->getThirdPartySetting('flowdrop_playground', 'chat');
    $chat['replies'] = [];
    $workflow->setThirdPartySetting('flowdrop_playground', 'chat', $chat)->save();
    $this->assertSame([], $workflow->getThirdPartySetting('flowdrop_playground', 'chat')['replies']);
  }

  private function echoFlow(string $id) {
    return $this->createWorkflow($id, $this->echoNodes(), [
      $this->edge('chat_input.1', 'message', 'chat_output.1', 'message'),
    ]);
  }

  private function echoNodes(): array {
    return [
      $this->node('chat_input.1', 'chat_input', [], ['name' => 'Chat Input']),
      $this->node('chat_output.1', 'chat_output', ['format' => 'text'], ['name' => 'Chat Output']),
    ];
  }

}
