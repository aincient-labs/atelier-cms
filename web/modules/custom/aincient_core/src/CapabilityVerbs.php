<?php

declare(strict_types=1);

namespace Drupal\aincient_core;

use Drupal\Component\Plugin\PluginManagerInterface;

/**
 * Which verbs a ROOM uses — the per-agent half of the capability answer.
 *
 * {@see InstallCapabilities} answers "what can this INSTALL do" (Write /
 * Describe / Draw, connected or not). That is one of two questions the chip row
 * needs, and on its own it produced the defect this class removes: every room
 * showed all three chips, so the General studio advertised Draw — dimmed, with
 * "needs an image provider" — for a picture it has no tool to make even on a
 * fully connected site. A chip is a claim about the room you are standing in;
 * an install-wide claim in a room that cannot spend it is noise at best and, on
 * a healthy install, a lie in the other direction.
 *
 * DERIVED FROM THE CANVAS, NEVER TYPED. A room's verbs come from the tools its
 * agent actually wires: a capability declares what it spends
 * ({@see Attribute\Capability::$verbs}), and a workflow that places that
 * capability's node type depends on it in config. So the answer is read off the
 * workflow's own dependency list. There is deliberately no studio → verbs table:
 * a table is a second place to update when an agent gains a tool, and the drift
 * it invites is exactly the drift the chips exist to prevent.
 *
 * WRITE IS UNIVERSAL. Every chat room takes words and gives words back; that is
 * what makes it a room. So Write is always in the set, and a capability never
 * declares it.
 *
 * APPROXIMATE ON PURPOSE. A placed-but-unwired tool node counts (the allow-list
 * is the `tool_availability` edge, not placement), so this can over-report by
 * one node an author left disconnected. That is the safe direction and the
 * cheap read: the chips decide DISPLAY only — access is permissions, and every
 * tool still reports its own failure at call time.
 */
final class CapabilityVerbs {

  /**
   * The config-dependency prefix a placed Atelier capability node carries.
   *
   * `flowdrop_node_type.flowdrop_node_type.aincient_flows_aincient_capability_<slug>`
   * — the node types the FlowDrop deriver mints, one per capability, where the
   * slug is the half of the plugin id after `<module>:`. That naming is a public
   * contract already (it is baked into shipped workflow config); see
   * {@see Attribute\Capability} and
   * \Drupal\aincient_flows\Plugin\Deriver\CapabilityToolDeriver.
   */
  private const NODE_TYPE_DEPENDENCY_PREFIX = 'flowdrop_node_type.flowdrop_node_type.aincient_flows_aincient_capability_';

  /**
   * Capability slug => the verbs it spends, built once per request.
   *
   * @var array<string, list<string>>|null
   */
  private ?array $slugVerbs = NULL;

  /**
   * Constructs a CapabilityVerbs.
   *
   * @param \Drupal\Component\Plugin\PluginManagerInterface $capabilities
   *   The capability plugin manager
   *   ({@see \Drupal\aincient_core\Capability\CapabilityManager}) — taken as the
   *   interface, since all this reads is getDefinitions() and the concrete
   *   manager is final.
   */
  public function __construct(
    private readonly PluginManagerInterface $capabilities,
  ) {}

  /**
   * The verbs a room whose agent has these config dependencies can spend.
   *
   * @param list<string> $configDependencies
   *   A flowdrop_workflow's `config` dependencies (its placed node types among
   *   them). Anything that is not an Atelier capability node type is ignored.
   *
   * @return list<string>
   *   Verb ids in {@see CapabilitySet} display order, always starting with
   *   `write`.
   */
  public function forDependencies(array $configDependencies): array {
    $slugs = $this->slugVerbs();
    $found = [CapabilitySet::WRITE => TRUE];
    foreach ($configDependencies as $dependency) {
      $dependency = (string) $dependency;
      if (!str_starts_with($dependency, self::NODE_TYPE_DEPENDENCY_PREFIX)) {
        continue;
      }
      $slug = substr($dependency, strlen(self::NODE_TYPE_DEPENDENCY_PREFIX));
      foreach ($slugs[$slug] ?? [] as $verb) {
        $found[$verb] = TRUE;
      }
    }
    // Display order comes from CapabilitySet, so the chip row of a room that
    // has everything reads the same left-to-right as the install-wide row.
    return array_values(array_filter(
      CapabilitySet::verbs(),
      static fn(string $verb): bool => isset($found[$verb]),
    ));
  }

  /**
   * Capability slug => verbs, from the plugin definitions themselves.
   *
   * @return array<string, list<string>>
   *   Verb ids keyed by capability plugin id.
   */
  private function slugVerbs(): array {
    if ($this->slugVerbs !== NULL) {
      return $this->slugVerbs;
    }
    $map = [];
    foreach ($this->capabilities->getDefinitions() as $id => $definition) {
      $verbs = array_values(array_map('strval', (array) (($definition['verbs'] ?? []) ?: [])));
      if ($verbs === []) {
        continue;
      }
      $id = (string) $id;
      $colon = strpos($id, ':');
      $map[$colon === FALSE ? $id : substr($id, $colon + 1)] = $verbs;
    }
    return $this->slugVerbs = $map;
  }

}
