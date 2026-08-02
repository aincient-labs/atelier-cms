<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Capability;

use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\Core\Plugin\ContextAwarePluginInterface;

/**
 * What an Atelier capability is: an inspectable plugin with typed parameters.
 *
 * WHY THIS IS SO SMALL — and why that is the whole point. The interface this
 * replaces (`drupal/ai`'s `FunctionCallInterface`) declared two extra methods,
 * and those two methods were the entire vendor lock-in:
 *
 *  - `normalize(): ToolsFunctionInput` — return a vendor DTO describing yourself
 *    as a provider function.
 *  - `populateValues(ToolsFunctionOutput $output)` — accept a vendor DTO of
 *    model-supplied arguments.
 *
 * Every implementor therefore had to name `Drupal\ai\OperationType\Chat\Tools\*`
 * in its type graph, which drags in the `Tools*` DTO family (~1,670 lines) and
 * the `ContextDefinitionNormalizer` that feeds it. OMITTING those two methods is
 * precisely what severs that dependency — not the rename, not the new manager.
 * Nothing in Atelier ever called either one: the schema a model sees is built
 * from `getContextDefinitions()` by
 * {@see \Drupal\aincient_flows\Plugin\FlowDropNodeProcessor\CapabilityTool},
 * and model arguments arrive as FlowDrop node inputs and are applied with
 * `setContextValue()`.
 *
 * So do not add them back, and do not add a "describe yourself to a provider"
 * method in any other shape. If a capability ever needs a provider-specific
 * projection, that belongs in the layer that talks to the provider (the
 * FlowDrop processor, or {@see \Drupal\aincient_core\Inference\ToolSchema}),
 * where exactly one file has to change when the provider does.
 *
 * A capability that can be RUN implements
 * {@see ExecutableCapabilityInterface}; this interface deliberately says nothing
 * about execution, so a future read-only/introspection-only capability is
 * expressible.
 */
interface CapabilityInterface extends PluginInspectionInterface, ContextAwarePluginInterface {

}
