<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Capability;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\aincient_core\Attribute\Capability;

/**
 * Discovers Atelier's capabilities.
 *
 * A plain core plugin manager, and that is the news: the vendor manager it
 * replaces carried nine extra methods for converting provider tool responses
 * into plugin instances (`convertToolsResponseToObjects()`,
 * `getFunctionCallFromFunctionName()`, `getStructuredExecutableDefinitions()`,
 * …). Every one of them speaks the `Tools*` DTO vocabulary, and not one was
 * called from Atelier: our callers ask for `getDefinitions()` (the FlowDrop
 * deriver, enumerating what can be placed on a canvas) and `createInstance()`
 * (the FlowDrop processor, running one). Those two come free from
 * DefaultPluginManager, so this class adds nothing but the discovery
 * coordinates — which is the correct size for it.
 *
 * The scan directory is `Plugin/AiCapability`, deliberately NOT the old
 * `Plugin/AiFunctionCall`: with a distinct directory, `drupal/ai`'s own manager
 * cannot discover our plugins at all. Core's attribute discovery would already
 * have skipped them (their attribute no longer matches), but silently — and a
 * capability appearing in two registries with two different base classes is the
 * kind of ambiguity that costs an afternoon later.
 */
final class CapabilityManager extends DefaultPluginManager {

  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct(
      'Plugin/AiCapability',
      $namespaces,
      $module_handler,
      CapabilityInterface::class,
      Capability::class,
    );
    $this->alterInfo('aincient_capability_info');
    $this->setCacheBackend($cache_backend, 'aincient_capability_plugins');
  }

}
