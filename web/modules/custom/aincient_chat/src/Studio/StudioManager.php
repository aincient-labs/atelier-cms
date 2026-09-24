<?php

declare(strict_types=1);

namespace Drupal\aincient_chat\Studio;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\aincient_chat\Attribute\Studio;
use Psr\Log\LoggerInterface;

/**
 * Discovers the console's studios.
 *
 * Replaces the `Studio` PHP enum (DECISIONS 0424, plans/console-extension-point.md
 * Phase 3). The enum was the right shape while the set was ours and closed; it
 * stopped being either when a component pack gained the right to ship a studio.
 * Everything the enum answered is answered here instead, from discovery rather
 * than from a `match`, so the set is a property of the INSTALL.
 *
 * Built exactly like {@see \Drupal\aincient_core\Capability\CapabilityManager}:
 * a plain core plugin manager plus the four convenience reads our call sites
 * actually make (ordered set, one studio, the keys, the default). Those reads
 * are here and not in each caller because the ORDER and the fallback rule are
 * part of the contract — nine call sites each re-sorting would be nine chances
 * to sort differently.
 *
 * Not `final`, unlike its capability twin, for one reason: its consumers read
 * more than `getDefinitions()`, so they type against this class and their unit
 * tests double it.
 */
class StudioManager extends DefaultPluginManager {

  /**
   * The studio a fresh console session opens in when nothing says otherwise.
   *
   * General is the open catch-all landing workspace. Kept as a constant rather
   * than an attribute flag: "which studio is the fallback" is a product fact
   * with exactly one right answer, and a flag invites two plugins to claim it.
   */
  public const DEFAULT_ID = 'general';

  /**
   * Instantiated studios keyed by id, in display order. Built once per request.
   *
   * @var array<string, \Drupal\aincient_chat\Studio\StudioInterface>|null
   */
  private ?array $studios = NULL;

  public function __construct(
    \Traversable $namespaces,
    CacheBackendInterface $cache_backend,
    ModuleHandlerInterface $module_handler,
    private readonly LoggerInterface $logger,
  ) {
    parent::__construct(
      'Plugin/Studio',
      $namespaces,
      $module_handler,
      StudioInterface::class,
      Studio::class,
    );
    $this->alterInfo('aincient_studio_info');
    $this->setCacheBackend($cache_backend, 'aincient_studio_plugins');
  }

  /**
   * {@inheritdoc}
   *
   * A studio id becomes a permission name and a URL value, so a malformed one
   * is a hazard rather than a cosmetic problem. It is dropped with a logged
   * warning instead of thrown: a pack that ships a bad studio must not be able
   * to take the whole console down on a client's site. `atelier:pack-validate`
   * is where an author is supposed to find this, before the image is built.
   */
  protected function findDefinitions(): array {
    $definitions = [];
    foreach (parent::findDefinitions() as $id => $definition) {
      if (preg_match('/^[a-z0-9_]+$/', (string) $id) !== 1) {
        $this->logger->warning('Ignoring studio plugin %id from %provider: a studio id must be a plain machine name (lowercase letters, digits and underscores) because it becomes a permission name and a URL value.', [
          '%id' => (string) $id,
          '%provider' => (string) ($definition['provider'] ?? 'unknown'),
        ]);
        continue;
      }
      $definitions[$id] = $definition;
    }
    return $definitions;
  }

  /**
   * Every studio, in display order, keyed by id.
   *
   * @return array<string, \Drupal\aincient_chat\Studio\StudioInterface>
   */
  public function studios(): array {
    if ($this->studios !== NULL) {
      return $this->studios;
    }
    $definitions = $this->getDefinitions();
    // Weight first, then id — a stable order even when two studios (ours and a
    // pack's) land on the same weight, so the switcher never reshuffles itself
    // between requests.
    uasort($definitions, static function (array $a, array $b): int {
      return [(int) ($a['weight'] ?? 0), (string) $a['id']] <=> [(int) ($b['weight'] ?? 0), (string) $b['id']];
    });
    $studios = [];
    foreach (array_keys($definitions) as $id) {
      // Dropped-not-thrown, for the same reason a malformed id is: this is the
      // single read behind the console shell, the settings form AND the
      // permissions page, so an exception here is a 500 on every one of them.
      // Discovery only proves the class carries the attribute — the factory is
      // where a class that does not implement StudioInterface is caught, and a
      // pack's own constructor can throw anything at all, so the net is
      // \Throwable rather than PluginException.
      try {
        $studio = $this->createInstance((string) $id);
      }
      catch (\Throwable $e) {
        $this->logger->warning('Ignoring studio plugin %id from %provider: it could not be instantiated (%message). A studio plugin must extend StudioBase.', [
          '%id' => (string) $id,
          '%provider' => (string) ($definitions[$id]['provider'] ?? 'unknown'),
          '%message' => $e->getMessage(),
        ]);
        continue;
      }
      if (!$studio instanceof StudioInterface) {
        continue;
      }
      $studios[(string) $id] = $studio;
    }
    return $this->studios = $studios;
  }

  /**
   * Resolve a (possibly stale) studio key, or NULL when nothing answers to it.
   *
   * The replacement for `Studio::tryFromKey()`: a key read off stored config or
   * a bookmarked URL may name a studio this install no longer has, and every
   * caller is expected to fall back rather than fail.
   */
  public function get(?string $id): ?StudioInterface {
    return $id === NULL ? NULL : ($this->studios()[$id] ?? NULL);
  }

  /**
   * All studio keys, in display order.
   *
   * @return list<string>
   */
  public function keys(): array {
    return array_keys($this->studios());
  }

  /**
   * The studio key a session falls back to: General, or the first studio.
   *
   * The second half matters on an install whose General studio was removed by
   * an alter hook — the console is always in exactly one studio, so there has
   * to be an answer even then.
   */
  public function defaultId(): string {
    $studios = $this->studios();
    if (isset($studios[self::DEFAULT_ID])) {
      return self::DEFAULT_ID;
    }
    return $studios === [] ? self::DEFAULT_ID : (string) array_key_first($studios);
  }

  /**
   * {@inheritdoc}
   */
  public function clearCachedDefinitions(): void {
    parent::clearCachedDefinitions();
    $this->studios = NULL;
  }

}
