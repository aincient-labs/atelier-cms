<?php

declare(strict_types=1);

namespace Drupal\aincient_flows\Plugin\Deriver;

use Drupal\aincient_core\Capability\CapabilityManager;
use Drupal\aincient_core\StudioTier;
use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\Core\Plugin\Discovery\ContainerDeriverInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Generates one CapabilityTool derivative per AIncient capability.
 *
 * Every Atelier capability plugin (the providers below) becomes a placeable tool
 * node (`aincient_capability:<slug>`). EXPOSURE = TOPOLOGY: making a capability a
 * placeable node type does NOT grant the agent anything — only *wiring* a node
 * into a Reason/Invoke node via a `tool_availability` edge does, and that edge
 * IS the allow-list (enforced by FlowDrop's ScopedToolInvoker). So there is no
 * PHP `const ALLOW_LIST` here: the canvas is the allow-list.
 *
 * The derivative carries the full capability plugin id in `function_call_id`; the
 * processor reads it back to build its schema and to execute. Plugin IDs follow
 * the Drupal derivative convention: `aincient_capability:<derivative>`.
 *
 * @see \Drupal\aincient_flows\Plugin\FlowDropNodeProcessor\CapabilityTool
 */
final class CapabilityToolDeriver extends DeriverBase implements ContainerDeriverInterface {

  /**
   * The provider prefixes of capabilities we expose (capability plugin ids).
   *
   * AIncient's own core capability modules — capability ids are namespaced by
   * their declaring module, so this is a prefix filter, not a list. The
   * slug after the prefix is the derivative id, so two providers must not
   * declare the same short id (none do today).
   *
   * Not the whole story: a capability whose PROVIDER module lives in the studio
   * tier (web/modules/studio, DECISIONS 0430) is exposed too, with no entry
   * here — a studio's verbs are ours and reach the agent like core's. That is
   * how the brand verbs (`aincient_brand:*`) arrive since the module moved
   * tiers (Phase E.5, 0436).
   */
  private const PROVIDERS = ['aincient_pages:', 'aincient_onboarding:'];

  public function __construct(
    private readonly CapabilityManager $capabilityManager,
    private readonly StudioTier $studioTier,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, $base_plugin_id): static {
    return new static(
      $container->get('plugin.manager.aincient.capabilities'),
      $container->get('aincient_core.studio_tier'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinitions($base_plugin_definition): array {
    foreach ($this->capabilityManager->getDefinitions() as $id => $definition) {
      $id = (string) $id;
      $prefix = NULL;
      foreach (self::PROVIDERS as $candidate) {
        if (str_starts_with($id, $candidate)) {
          $prefix = $candidate;
          break;
        }
      }
      if ($prefix === NULL) {
        // A studio-tier module's capability: the slug is the half of the id
        // after `<module>:`, same as the core providers.
        $colon = strpos($id, ':');
        if ($colon === FALSE || !$this->studioTier->isStudioModule((string) ($definition['provider'] ?? ''))) {
          continue;
        }
        $prefix = substr($id, 0, $colon + 1);
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
