<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_audit\Unit;

use Drupal\Tests\UnitTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The Checks agent is told which page is open.
 *
 * The Checks room sends `working_node` with every turn and ChatController
 * passes its nid on as the `working_node_id` template variable. The page draft
 * JSON carries no node id, so without this line in the prompt the agent called
 * run_page_audit(node_id: 0) and then asked the user which page they meant —
 * while that page was open beside the chat. The placeholder is config, edited
 * on the canvas, so only a config test catches it being dropped.
 *
 * @group aincient
 */
final class ChecksKnowsTheOpenPageTest extends UnitTestCase {

  public function testAuditPromptRendersTheOpenPageId(): void {
    $module = dirname(__DIR__, 3);
    $file = $module . '/config/install/flowdrop_workflow.flowdrop_workflow.aincient_audit_agent.yml';
    $config = Yaml::parseFile($file);

    $templates = [];
    foreach ($config['nodes'] ?? [] as $node) {
      $template = (string) ($node['data']['config']['template'] ?? '');
      if ($template !== '') {
        $templates[] = $template;
      }
    }

    $rendered = array_filter($templates, static fn (string $t): bool => str_contains($t, '{{ working_node_id }}'));
    $this->assertNotEmpty($rendered, 'No audit-agent template renders {{ working_node_id }}, so the agent cannot tell which page is open.');
  }

}
