<?php

declare(strict_types=1);

namespace Drupal\aincient_chat\Studio;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\Core\Plugin\Discovery\YamlDiscovery;
use Drupal\Core\Serialization\Yaml;
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
 * ONE DISCOVERY, PLUS GENERAL. The declaration is a YAML manifest,
 * `<module>.studios.yml` (DECISIONS 0430, plans/studio-modules.md "The
 * manifest"): it needs no PHP class and doubles as the studio's index (UI
 * entry, flows, owned capabilities, demo source). Every studio module under
 * `web/modules/studio/` ships one; a pack may too. The `#[Studio]` attribute
 * discovery that carried the built-ins through the migration is gone with the
 * last of them (Phase E.5, DECISIONS 0436). General is the one studio with no
 * module to put a manifest in — it is the console's own fallback, declared by
 * {@see self::GENERAL} right here, and a manifest cannot redeclare it. Every
 * definition is graded by {@see StudioManifest} and a bad one is dropped, not
 * thrown.
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
   * The General studio's definition — the console's own, not a module's.
   *
   * The open catch-all workspace: full-width chat, no editor pane, the fallback
   * every read ends in ({@see self::defaultId}) and the one studio that mints
   * no permission. Core (`aincient_chat`) may not carry a manifest — the tier
   * guard's rule (a) reserves manifests for studio modules, because core must
   * work with every studio off — so General is declared here, in the manager
   * that defines the fallback rule, rather than in YAML nobody could switch off.
   */
  private const GENERAL = [
    'id' => self::DEFAULT_ID,
    'label' => 'General',
    'description' => 'The landing workspace: a full-width chat that shows you around and points you to the right room.',
    'help' => 'Say what you want in plain words; the operator will take you to the studio that does it.',
    'weight' => 0,
    'open' => TRUE,
    'provider' => 'aincient_chat',
    'class' => Studio::class,
  ];

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
    // No class subdirectory: discovery is the manifests (getDiscovery()), so
    // the attribute half of the parent is never built. FALSE is the parent's
    // own "no attribute discovery" value.
    parent::__construct(FALSE, $namespaces, $module_handler, StudioInterface::class);
    $this->alterInfo('aincient_studio_info');
    $this->setCacheBackend($cache_backend, 'aincient_studio_plugins');
  }

  /**
   * {@inheritdoc}
   *
   * The `<module>.studios.yml` manifests, and nothing else. Entries arrive with
   * `id` and `provider` set by YamlDiscovery; their labels are left as plain
   * strings rather than registered as translatable — the admin label is what
   * the permissions page already wraps in t().
   */
  protected function getDiscovery() {
    if (!$this->discovery) {
      $this->discovery = new YamlDiscovery('studios', $this->moduleHandler->getModuleDirectories());
    }
    return $this->discovery;
  }

  /**
   * {@inheritdoc}
   *
   * A manifest studio usually names no class — everything it is lives in the
   * YAML — so it gets the empty {@see Studio}. Defaulted here, after the
   * parent's leading-backslash normalisation, so the manifest check below sees
   * the class that will actually be instantiated.
   */
  public function processDefinition(&$definition, $plugin_id): void {
    parent::processDefinition($definition, $plugin_id);
    if (is_array($definition) && empty($definition['class'])) {
      $definition['class'] = Studio::class;
    }
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
    $definitions = [self::DEFAULT_ID => self::GENERAL];
    $moduleDirectories = $this->moduleHandler->getModuleDirectories();
    foreach (parent::findDefinitions() as $id => $definition) {
      if ((string) $id === self::DEFAULT_ID) {
        $this->logger->warning('Ignoring studio %id from %provider: General is the console\'s own fallback studio and cannot be redeclared by a manifest.', [
          '%id' => (string) $id,
          '%provider' => (string) ($definition['provider'] ?? 'unknown'),
        ]);
        continue;
      }
      if (preg_match('/^[a-z0-9_]+$/', (string) $id) !== 1) {
        $this->logger->warning('Ignoring studio plugin %id from %provider: a studio id must be a plain machine name (lowercase letters, digits and underscores) because it becomes a permission name and a URL value.', [
          '%id' => (string) $id,
          '%provider' => (string) ($definition['provider'] ?? 'unknown'),
        ]);
        continue;
      }
      // The full manifest schema, for the same reason and with the same
      // outcome: a manifest is hand-written YAML with no compiler, so a
      // missing label or a misspelt key is caught here (and, for ours, by the
      // tree test first) rather than silently doing nothing.
      $provider = (string) ($definition['provider'] ?? '');
      $errors = StudioManifest::validate($definition, $moduleDirectories[$provider] ?? NULL);
      if ($errors !== []) {
        $this->logger->warning('Ignoring studio %id from %provider: its manifest is invalid (%errors).', [
          '%id' => (string) $id,
          '%provider' => $provider !== '' ? $provider : 'unknown',
          '%errors' => implode('; ', array_slice($errors, 0, 3)) . (count($errors) > 3 ? sprintf('; and %d more', count($errors) - 3) : ''),
        ]);
        continue;
      }
      $definitions[$id] = $definition;
    }
    return $definitions;
  }

  /**
   * Every studio a module's manifest declares, with its schema errors.
   *
   * For `atelier:pack-validate` (aincient_pages' PackValidator, which calls it
   * by name because it may not type against this class): discovery DROPS an
   * invalid studio with a log line, so without this a pack author whose
   * `ui.entry` should have been a `ui.script` would only be told the pack
   * "ships no studio". Reads the module's own `<module>.studios.yml`, graded
   * with its directory, exactly as {@see self::findDefinitions()} grades it.
   *
   * @return array<string, list<string>>
   *   Studio id => errors (empty list = valid). Empty when the module ships no
   *   manifest or is not installed.
   */
  public function manifestErrors(string $module): array {
    $directories = $this->moduleHandler->getModuleDirectories();
    $dir = $directories[$module] ?? NULL;
    $file = $dir === NULL ? NULL : $dir . '/' . $module . '.studios.yml';
    if ($file === NULL || !is_file($file)) {
      return [];
    }
    $parsed = Yaml::decode((string) file_get_contents($file));
    $out = [];
    foreach (is_array($parsed) ? $parsed : [] as $id => $definition) {
      $out[(string) $id] = is_array($definition)
        ? StudioManifest::validate($definition + ['id' => (string) $id, 'provider' => $module], $dir)
        : [sprintf('studio "%s" must be a map of manifest keys', $id)];
    }
    return $out;
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
      // A manifest may name a `class` — the factory is where one that does not
      // implement StudioInterface is caught, and a pack's own constructor can
      // throw anything at all, so the net is \Throwable rather than
      // PluginException.
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
