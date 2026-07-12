<?php

declare(strict_types=1);

namespace Drupal\aincient_flows\Plugin\Deriver;

use Drupal\ai\Service\FunctionCalling\FunctionCallPluginManager;
use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\Core\Plugin\Discovery\ContainerDeriverInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Generates one CapabilityTool derivative per AIncient capability.
 *
 * Every AIncient FunctionCall (the providers below) becomes a placeable tool
 * node (`aincient_capability:<slug>`). EXPOSURE = TOPOLOGY: making a capability a
 * placeable node type does NOT grant the agent anything — only *wiring* a node
 * into a Reason/Invoke node via a `tool_availability` edge does, and that edge
 * IS the allow-list (enforced by FlowDrop's ScopedToolInvoker). So there is no
 * PHP `const ALLOW_LIST` here: the canvas is the allow-list.
 *
 * The derivative carries the full FunctionCall id in `function_call_id`; the
 * processor reads it back to build its schema and to execute. Plugin IDs follow
 * the Drupal derivative convention: `aincient_capability:<derivative>`.
 *
 * @see \Drupal\aincient_flows\Plugin\FlowDropNodeProcessor\CapabilityTool
 */
final class CapabilityToolDeriver extends DeriverBase implements ContainerDeriverInterface {

  /**
   * The provider prefixes of capabilities we expose (FunctionCall plugin ids).
   *
   * AIncient's own capability modules — NOT every contrib FunctionCall. The
   * slug after the prefix is the derivative id, so two providers must not
   * declare the same short id (none do today).
   */
  private const PROVIDERS = ['aincient_pages:', 'aincient_brand:', 'aincient_onboarding:', 'aincient_audit:'];

  public function __construct(
    private readonly FunctionCallPluginManager $functionCallManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, $base_plugin_id): static {
    return new static(
      $container->get('plugin.manager.ai.function_calls'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinitions($base_plugin_definition): array {
    foreach ($this->functionCallManager->getDefinitions() as $id => $definition) {
      $id = (string) $id;
      $prefix = NULL;
      foreach (self::PROVIDERS as $candidate) {
        if (str_starts_with($id, $candidate)) {
          $prefix = $candidate;
          break;
        }
      }
      if ($prefix === NULL) {
        continue;
      }
      $slug = substr($id, strlen($prefix));
      $name = (string) ($definition['name'] ?? $slug);
      $this->derivatives[$slug] = [
        'label' => new TranslatableMarkup('Capability: @name', ['@name' => $name]),
        'description' => (string) ($definition['description'] ?? $id),
        'function_call_id' => $id,
      ] + $base_plugin_definition;
    }
    return $this->derivatives;
  }

}
