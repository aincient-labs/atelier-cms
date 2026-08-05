<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;

/**
 * Declares one Atelier capability — a thing the product can DO for a user.
 *
 * WHY THIS EXISTS. Our 15 capabilities used to be declared with the AI module's
 * own function-call attribute. That attribute is the entry point to a much
 * larger vendor surface: its plugin manager hands instances to a
 * `Tools*`/`ContextDefinitionNormalizer` stack whose only job is to render a
 * capability as a provider-shaped JSON Schema. We never used that — the schema
 * a model actually sees is built by
 * {@see \Drupal\aincient_flows\Plugin\FlowDropNodeProcessor\CapabilityTool}
 * from the plugin's own context definitions. So the only thing the vendor
 * attribute bought us was a discovery key, at the cost of a dependency on
 * ~1,670 lines of DTOs we don't read.
 *
 * This attribute is that discovery key and nothing more. It carries exactly the
 * five properties our capabilities declare — no `group`, no
 * `module_dependencies`, no `deriver`. Those are unused by all 15, and an
 * unused knob on a boundary class is an invitation to grow the boundary.
 *
 * PLUGIN IDS ARE A PUBLIC CONTRACT. `$id` is `<module>:<slug>`, and the slug is
 * baked into shipped FlowDrop workflow config as
 * `aincient_flows_aincient_capability_<slug>` (see {@see \Drupal\aincient_flows\Plugin\Deriver\CapabilityToolDeriver}).
 * Renaming an id silently breaks every workflow that placed the node. Ids are
 * append-only in practice.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Capability extends Plugin {

  /**
   * Constructs a Capability attribute.
   *
   * @param string $id
   *   The plugin id, `<module>:<slug>`. The slug half is what the FlowDrop
   *   deriver turns into a node type, so it is load-bearing config — see the
   *   class docblock before touching one.
   * @param string $function_name
   *   The wire name the capability used to be exposed under when `drupal/ai`
   *   projected it as an LLM function. Carried because all 15 plugins declare
   *   it and dropping it would mean editing every one; to be plain about it,
   *   NOTHING in Atelier reads this today — the model is shown the FlowDrop
   *   node type's name, not this. Treat it as documentation until it is either
   *   used or removed in a deliberate sweep.
   * @param string $name
   *   The human-readable capability name. Read by the deriver for the node
   *   type's label ("Capability: @name").
   * @param string|null $description
   *   What the capability does, written FOR THE MODEL — this becomes the tool
   *   description on the derived node type, so it is prompt text, not a code
   *   comment.
   * @param array $context_definitions
   *   The capability's parameters, as core
   *   \Drupal\Core\Plugin\Context\ContextDefinition objects keyed by name.
   *   These are the single source of the tool's input schema: CapabilityTool
   *   reads them directly, so the signature the model sees cannot drift from
   *   the signature the code reads.
   * @param array $verbs
   *   Which install VERBS this capability spends — {@see \Drupal\aincient_core\CapabilitySet}
   *   ids (`describe`, `draw`). DISPLAY ONLY, and only for the few capabilities
   *   that need more than words: a room shows the Describe/Draw chip when one of
   *   its wired tools declares that verb here, so General stops advertising
   *   pictures it has no tool to make ({@see \Drupal\aincient_core\CapabilityVerbs}).
   *   Empty for the overwhelming majority — a tool that reads and writes content
   *   spends `write`, which every chat room has by definition. This does NOT
   *   gate execution: each tool still reports its own failure at call time.
   */
  public function __construct(
    public readonly string $id,
    public readonly string $function_name,
    public readonly string $name,
    public readonly ?string $description,
    public readonly array $context_definitions = [],
    public readonly array $verbs = [],
  ) {}

}
