<?php

declare(strict_types=1);

namespace Drupal\aincient_chat\Studio;

/**
 * The schema of one studio definition — a `<module>.studios.yml` entry.
 *
 * The manifest is the canonical way to declare a studio (DECISIONS 0430,
 * plans/studio-modules.md "The manifest"), and a YAML file has no compiler to
 * catch a typo: `capabilites: [create_form]` would otherwise parse, be ignored,
 * and leave the studio's verbs callable while it is switched off. So every key
 * is checked, and an UNKNOWN key is an error rather than a no-op.
 *
 * Two callers, one rule. {@see StudioManager::findDefinitions()} drops a
 * definition that fails with a logged warning (0425: a bad studio must never
 * 500 the console), and the tree test (`StudioManifestTest`) fails the build
 * for every manifest we ship, so ours never get as far as the warning.
 *
 * Pure on purpose — no Drupal services — so a UnitTestCase can call it and the
 * tree test can grade a manifest without booting a site. The only side-effect-
 * free outside reads are the filesystem (`ui.script`, `ui.style`, `demo`) and the autoloader
 * (`class`).
 */
final class StudioManifest {

  /**
   * Every key a studio definition may carry.
   *
   * The manifest keys, plus what discovery itself adds: `id` and `provider`
   * (YamlDiscovery adds both to every entry; the attribute carries them too),
   * `class` (defaulted by the manager when absent) and `deriver` (the core
   * plugin attribute's own optional property).
   */
  public const KEYS = [
    'id',
    'label',
    'description',
    'help',
    'weight',
    'open',
    'default_enabled',
    'ui',
    'flows',
    'capabilities',
    'demo',
    'class',
    'provider',
    'deriver',
  ];

  /**
   * The keys of the `ui` map — the studio's BROWSER half.
   *
   * Two ways to bring a rail, one per tier (plans/console-extension-point.md
   * Phase 4, DECISIONS 0448), mutually exclusive:
   *
   *   - `entry` — a studio module under `web/modules/studio/`: the rail/preview
   *     module (relative to the module) that the ONE console build compiles into
   *     its own lazy chunk (`chat-ui/scripts/gen-studio-registry.mjs`). The
   *     server never serves it.
   *   - `script` (+ optional `style`) — everyone else, i.e. a pack studio our
   *     build never sees: a built browser ES module the console `import()`s and
   *     calls `mount(el, ctx)` on (`chat-ui/src/mount/`). The server serves it
   *     as a static file and puts its URL in the shell only for a user who may
   *     enter the studio.
   *
   * `name` is the console's crumb name (defaults to `label`) and `icon` a kit
   * icon name (`shield-check`; defaults to the chat glyph). Both sit in the
   * manifest, not in the entry, because the nav needs them before the studio's
   * code has loaded (Phase C of plans/studio-modules.md). Absent `entry` and
   * `script` = a chat-only studio.
   */
  public const UI_KEYS = ['entry', 'script', 'style', 'name', 'icon'];

  /**
   * A machine name: a studio id, a flowdrop_workflow id or a capability slug.
   */
  private const MACHINE_NAME = '/^[a-z0-9_]+$/';

  /**
   * A kit icon name: `shield-check` names the kit's `ShieldCheckIcon`.
   */
  private const ICON_NAME = '/^[a-z][a-z0-9-]*$/';

  /**
   * Validates one studio definition.
   *
   * @param array<string, mixed> $definition
   *   The definition as discovery produces it (or as parsed from YAML, with
   *   `id` set from the entry's key).
   * @param string|null $moduleDir
   *   The providing module's directory. When given, `ui.script`, `ui.style` and
   *   `demo` must exist under it; when NULL only their shape is checked.
   *   `ui.entry` is always shape-only (build input, absent from the image).
   *
   * @return list<string>
   *   Human-readable errors; empty when the definition is valid.
   */
  public static function validate(array $definition, ?string $moduleDir): array {
    $errors = [];

    foreach (array_keys($definition) as $key) {
      if (!in_array($key, self::KEYS, TRUE)) {
        $errors[] = sprintf('unknown key "%s" (allowed: %s)', $key, implode(', ', self::KEYS));
      }
    }

    $id = $definition['id'] ?? NULL;
    if (!is_string($id) || preg_match(self::MACHINE_NAME, $id) !== 1) {
      $errors[] = sprintf('"id" must be a machine name matching %s, got %s', self::MACHINE_NAME, self::describe($id));
    }

    $label = $definition['label'] ?? NULL;
    if (!is_string($label) || trim($label) === '') {
      $errors[] = sprintf('"label" is required and must be a non-empty string, got %s', self::describe($label));
    }

    foreach (['description', 'help', 'provider'] as $key) {
      if (array_key_exists($key, $definition) && !is_string($definition[$key])) {
        $errors[] = sprintf('"%s" must be a string, got %s', $key, self::describe($definition[$key]));
      }
    }

    if (array_key_exists('weight', $definition) && !is_int($definition['weight'])) {
      $errors[] = sprintf('"weight" must be an integer, got %s', self::describe($definition['weight']));
    }

    foreach (['open', 'default_enabled'] as $key) {
      if (array_key_exists($key, $definition) && !is_bool($definition[$key])) {
        $errors[] = sprintf('"%s" must be a boolean (true/false), got %s', $key, self::describe($definition[$key]));
      }
    }

    if (array_key_exists('ui', $definition)) {
      array_push($errors, ...self::ui($definition['ui'], $moduleDir));
    }
    if (array_key_exists('demo', $definition)) {
      $error = self::relativePath('demo', $definition['demo'], $moduleDir, TRUE);
      if ($error !== NULL) {
        $errors[] = $error;
      }
    }

    // `capabilities` are BARE slugs: the provider is prepended once, in
    // StudioBase::capabilities(). A colon here would be a studio claiming a
    // verb another module defines, which the ownership rule forbids.
    foreach (['flows', 'capabilities'] as $key) {
      if (array_key_exists($key, $definition)) {
        array_push($errors, ...self::machineNameList($key, $definition[$key]));
      }
    }

    if (array_key_exists('deriver', $definition) && !is_string($definition['deriver'])) {
      $errors[] = sprintf('"deriver" must be a class name, got %s', self::describe($definition['deriver']));
    }

    if (array_key_exists('class', $definition)) {
      $class = $definition['class'];
      if (!is_string($class) || $class === '') {
        $errors[] = sprintf('"class" must be a class name, got %s', self::describe($class));
      }
      elseif (!class_exists($class)) {
        $errors[] = sprintf('"class" names %s, which does not exist', $class);
      }
      elseif (!is_subclass_of($class, StudioInterface::class)) {
        $errors[] = sprintf('"class" names %s, which does not implement %s (extend StudioBase)', $class, StudioInterface::class);
      }
    }

    return $errors;
  }

  /**
   * Checks the `ui` map ({@see UI_KEYS}).
   *
   * @return list<string>
   */
  private static function ui(mixed $ui, ?string $moduleDir): array {
    if (!is_array($ui) || array_is_list($ui)) {
      return [sprintf('"ui" must be a map of %s, got %s', implode(' / ', self::UI_KEYS), self::describe($ui))];
    }
    $errors = [];
    foreach (array_keys($ui) as $key) {
      if (!in_array($key, self::UI_KEYS, TRUE)) {
        $errors[] = sprintf('unknown key "ui.%s" (allowed: %s)', $key, implode(', ', self::UI_KEYS));
      }
    }
    if (array_key_exists('entry', $ui)) {
      // Shape only, never on disk: `ui.entry` is BUILD input, compiled into
      // aincient_chat/js/dist and stripped from the appliance image
      // (.dockerignore drops web/modules/studio/*/ui). An on-disk check here
      // rejected every built-in studio in the released 0.16.0 image. The build
      // (gen-studio-registry.mjs) is what fails on a missing entry.
      $error = self::relativePath('ui.entry', $ui['entry'], NULL, FALSE);
      if ($error !== NULL) {
        $errors[] = $error;
      }
    }
    foreach (['script' => '/\.m?js$/', 'style' => '/\.css$/'] as $key => $extension) {
      if (!array_key_exists($key, $ui)) {
        continue;
      }
      $error = self::relativePath('ui.' . $key, $ui[$key], $moduleDir, FALSE);
      if ($error === NULL && preg_match($extension, (string) $ui[$key]) !== 1) {
        $error = sprintf('"ui.%s" must name a %s file, got "%s"', $key, $key === 'script' ? 'built .js / .mjs' : '.css', $ui[$key]);
      }
      if ($error !== NULL) {
        $errors[] = $error;
      }
    }
    if (array_key_exists('entry', $ui) && array_key_exists('script', $ui)) {
      $errors[] = '"ui.entry" and "ui.script" are mutually exclusive: a studio module under web/modules/studio/ ships an entry the console build compiles, anything else ships a built script the console mounts';
    }
    if (array_key_exists('style', $ui) && !array_key_exists('script', $ui)) {
      $errors[] = '"ui.style" only goes with "ui.script" (a built-in studio\'s CSS is compiled with its entry)';
    }
    // The tier rule needs the module's location, so only a graded-on-disk
    // manifest gets it: `entry` is compiled by OUR build, which only scans the
    // studio tier — an entry anywhere else would be a rail that never exists.
    // `script` is the other way round: a built-in has the build, and a mount
    // boundary there would be an island with no reason to be one.
    if ($moduleDir !== NULL) {
      $inStudioTier = self::inStudioTier($moduleDir);
      if (array_key_exists('entry', $ui) && !$inStudioTier) {
        $errors[] = '"ui.entry" is only for studio modules under web/modules/studio/ (the console build compiles it); a pack studio ships a built "ui.script" instead';
      }
      if (array_key_exists('script', $ui) && $inStudioTier) {
        $errors[] = '"ui.script" is for pack studios; a studio module under web/modules/studio/ ships a "ui.entry" the console build compiles';
      }
    }
    if (array_key_exists('name', $ui) && (!is_string($ui['name']) || trim($ui['name']) === '')) {
      $errors[] = sprintf('"ui.name" must be a non-empty string, got %s', self::describe($ui['name']));
    }
    if (array_key_exists('icon', $ui) && (!is_string($ui['icon']) || preg_match(self::ICON_NAME, $ui['icon']) !== 1)) {
      $errors[] = sprintf('"ui.icon" must be a kit icon name matching %s (e.g. shield-check), got %s', self::ICON_NAME, self::describe($ui['icon']));
    }
    return $errors;
  }

  /**
   * Whether a module directory is a studio-tier module (`…/modules/studio/<m>`).
   */
  public static function inStudioTier(string $moduleDir): bool {
    return preg_match('#(^|/)modules/studio/[^/]+/?$#', str_replace('\\', '/', $moduleDir)) === 1;
  }

  /**
   * Checks a module-relative path that must stay inside the module.
   *
   * `ui.entry` and `demo` are read by the console build and the demo importer
   * relative to the module, so an absolute path or a `..` would let a manifest
   * point either of them anywhere on disk.
   */
  private static function relativePath(string $key, mixed $value, ?string $moduleDir, bool $directory): ?string {
    if (!is_string($value) || $value === '') {
      return sprintf('"%s" must be a non-empty relative path, got %s', $key, self::describe($value));
    }
    if (str_starts_with($value, '/') || in_array('..', explode('/', str_replace('\\', '/', $value)), TRUE)) {
      return sprintf('"%s" must be a path relative to the module with no leading "/" and no "..", got "%s"', $key, $value);
    }
    if ($moduleDir === NULL) {
      return NULL;
    }
    $path = rtrim($moduleDir, '/') . '/' . $value;
    if ($directory ? !is_dir($path) : !is_file($path)) {
      return sprintf('"%s" points at "%s", which is not a %s under %s', $key, $value, $directory ? 'directory' : 'file', $moduleDir);
    }
    return NULL;
  }

  /**
   * Checks a list of machine names.
   *
   * @return list<string>
   */
  private static function machineNameList(string $key, mixed $value): array {
    if (!is_array($value) || !array_is_list($value)) {
      return [sprintf('"%s" must be a list of machine names, got %s', $key, self::describe($value))];
    }
    $errors = [];
    foreach ($value as $index => $item) {
      if (!is_string($item) || preg_match(self::MACHINE_NAME, $item) !== 1) {
        $errors[] = sprintf('"%s"[%d] must be a machine name matching %s, got %s', $key, $index, self::MACHINE_NAME, self::describe($item));
      }
    }
    return $errors;
  }

  /**
   * A short description of a value for an error message.
   */
  private static function describe(mixed $value): string {
    return match (TRUE) {
      $value === NULL => 'nothing',
      is_string($value) => sprintf('"%s"', $value),
      is_bool($value) => $value ? 'true' : 'false',
      is_int($value), is_float($value) => (string) $value,
      is_array($value) => array_is_list($value) ? 'a list' : 'a map',
      default => get_debug_type($value),
    };
  }

}
