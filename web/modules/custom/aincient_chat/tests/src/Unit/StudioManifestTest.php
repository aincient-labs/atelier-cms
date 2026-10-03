<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_chat\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\aincient_chat\Studio\Studio;
use Drupal\aincient_chat\Studio\StudioManifest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Yaml\Yaml;

/**
 * The studio manifest schema, and every manifest in the tree against it.
 *
 * The validator cases pin the error shapes. The TREE test is the guard from
 * plans/studio-modules.md "Guards": a manifest we ship must never get as far
 * as the manager's drop-with-a-warning, because on a client's site that
 * warning is a studio that silently isn't there.
 *
 * @group aincient
 */
#[CoversClass(StudioManifest::class)]
final class StudioManifestTest extends UnitTestCase {

  /**
   * The one manifest in the tree that is MEANT to be invalid: the fixture the
   * kernel test uses to prove a bad studio is dropped, not thrown.
   */
  private const KNOWN_BAD = 'aincient_studio_bad_test/aincient_studio_bad_test.studios.yml';

  /**
   * @return iterable<string, array{array<string, mixed>, ?string}>
   *   A definition and a substring the first error must contain (NULL = valid).
   */
  public static function definitions(): iterable {
    yield 'valid minimal' => [['id' => 'forms', 'label' => 'Forms'], NULL];
    yield 'valid full, discovery keys included' => [[
      'id' => 'forms',
      'label' => 'Forms',
      'description' => 'Build forms.',
      'help' => 'Ask for a form.',
      'weight' => 60,
      'open' => FALSE,
      'default_enabled' => TRUE,
      'ui' => ['entry' => 'ui/index.tsx', 'name' => 'Forms', 'icon' => 'sliders'],
      'flows' => ['forms_agent'],
      'capabilities' => ['create_form', 'list_submissions'],
      'demo' => 'content/demo',
      'class' => Studio::class,
      'provider' => 'aincient_studio_forms',
    ], NULL];
    yield 'missing label' => [['id' => 'forms'], '"label" is required'];
    yield 'empty label' => [['id' => 'forms', 'label' => '  '], '"label" is required'];
    yield 'bad id' => [['id' => 'Forms-Studio', 'label' => 'Forms'], '"id" must be a machine name'];
    yield 'unknown key' => [['id' => 'forms', 'label' => 'Forms', 'capabilites' => ['x']], 'unknown key "capabilites"'];
    yield 'weight not an int' => [['id' => 'forms', 'label' => 'Forms', 'weight' => '60'], '"weight" must be an integer, got "60"'];
    yield 'open not a bool' => [['id' => 'forms', 'label' => 'Forms', 'open' => 'yes'], '"open" must be a boolean'];
    yield 'flows not a list' => [['id' => 'forms', 'label' => 'Forms', 'flows' => 'forms_agent'], '"flows" must be a list of machine names, got "forms_agent"'];
    yield 'flows a map' => [['id' => 'forms', 'label' => 'Forms', 'flows' => ['a' => 'forms_agent']], '"flows" must be a list of machine names, got a map'];
    yield 'bad capability slug' => [['id' => 'forms', 'label' => 'Forms', 'capabilities' => ['create_form', 'aincient_core:list_pages']], '"capabilities"[1] must be a machine name'];
    yield 'class not a StudioInterface' => [['id' => 'forms', 'label' => 'Forms', 'class' => \stdClass::class], 'does not implement'];
    yield 'class missing' => [['id' => 'forms', 'label' => 'Forms', 'class' => 'Drupal\\nowhere\\Nope'], 'does not exist'];
    yield 'ui a bare path (the pre-Phase-C shape)' => [['id' => 'forms', 'label' => 'Forms', 'ui' => 'ui/index.tsx'], '"ui" must be a map of entry / script / style / name / icon, got "ui/index.tsx"'];
    yield 'ui a list' => [['id' => 'forms', 'label' => 'Forms', 'ui' => ['ui/index.tsx']], '"ui" must be a map'];
    yield 'ui unknown key' => [['id' => 'forms', 'label' => 'Forms', 'ui' => ['entyr' => 'ui/index.tsx']], 'unknown key "ui.entyr"'];
    yield 'ui name only (chat-only, named)' => [['id' => 'forms', 'label' => 'Forms', 'ui' => ['name' => 'Forms', 'icon' => 'sliders']], NULL];
    yield 'ui entry escaping with ..' => [['id' => 'forms', 'label' => 'Forms', 'ui' => ['entry' => 'ui/../../secret.tsx']], '"ui.entry" must be a path relative to the module'];
    yield 'ui entry absolute' => [['id' => 'forms', 'label' => 'Forms', 'ui' => ['entry' => '/etc/passwd']], '"ui.entry" must be a path relative to the module'];
    yield 'ui name empty' => [['id' => 'forms', 'label' => 'Forms', 'ui' => ['name' => ' ']], '"ui.name" must be a non-empty string'];
    yield 'ui script (a pack studio)' => [['id' => 'forms', 'label' => 'Forms', 'ui' => ['script' => 'studio/dist/studio.js', 'style' => 'studio/studio.css']], NULL];
    yield 'ui script an .mjs' => [['id' => 'forms', 'label' => 'Forms', 'ui' => ['script' => 'studio/studio.mjs']], NULL];
    yield 'ui script not built JS' => [['id' => 'forms', 'label' => 'Forms', 'ui' => ['script' => 'studio/index.tsx']], '"ui.script" must name a built .js / .mjs file'];
    yield 'ui script escaping with ..' => [['id' => 'forms', 'label' => 'Forms', 'ui' => ['script' => '../other/studio.js']], '"ui.script" must be a path relative to the module'];
    yield 'ui style not CSS' => [['id' => 'forms', 'label' => 'Forms', 'ui' => ['script' => 'studio.js', 'style' => 'studio.scss']], '"ui.style" must name a .css file'];
    yield 'ui entry and script together' => [['id' => 'forms', 'label' => 'Forms', 'ui' => ['entry' => 'ui/index.tsx', 'script' => 'studio.js']], 'mutually exclusive'];
    yield 'ui style without script' => [['id' => 'forms', 'label' => 'Forms', 'ui' => ['entry' => 'ui/index.tsx', 'style' => 'ui/studio.css']], '"ui.style" only goes with "ui.script"'];
    yield 'ui icon not a kit name' => [['id' => 'forms', 'label' => 'Forms', 'ui' => ['icon' => 'ShieldCheckIcon']], '"ui.icon" must be a kit icon name'];
  }

  /**
   * Each case yields exactly the error it is about (or none).
   */
  #[DataProvider('definitions')]
  public function testValidate(array $definition, ?string $expected): void {
    $errors = StudioManifest::validate($definition, NULL);
    if ($expected === NULL) {
      $this->assertSame([], $errors);
      return;
    }
    $this->assertNotSame([], $errors);
    $this->assertStringContainsString($expected, $errors[0]);
  }

  /**
   * With a module directory, `ui.entry` must be a file and `demo` a directory in it.
   */
  public function testPathsAreCheckedOnDiskWhenModuleDirGiven(): void {
    $root = sys_get_temp_dir() . '/studio_manifest_' . bin2hex(random_bytes(4));
    // A studio-tier module, since `ui.entry` is only allowed there.
    $dir = $root . '/modules/studio/forms';
    mkdir($dir . '/ui', 0777, TRUE);
    mkdir($dir . '/content/demo', 0777, TRUE);
    touch($dir . '/ui/index.tsx');
    try {
      $base = ['id' => 'forms', 'label' => 'Forms'];
      $this->assertSame([], StudioManifest::validate($base + ['ui' => ['entry' => 'ui/index.tsx'], 'demo' => 'content/demo'], $dir));

      $errors = StudioManifest::validate($base + ['ui' => ['entry' => 'ui/missing.tsx']], $dir);
      $this->assertCount(1, $errors);
      $this->assertStringContainsString('"ui.entry" points at "ui/missing.tsx", which is not a file', $errors[0]);

      // A directory is not a UI entry, and a file is not a demo source.
      $this->assertNotSame([], StudioManifest::validate($base + ['ui' => ['entry' => 'ui']], $dir));
      $this->assertNotSame([], StudioManifest::validate($base + ['demo' => 'ui/index.tsx'], $dir));
    }
    finally {
      exec('rm -rf ' . escapeshellarg($root));
    }
  }

  /**
   * The tier rule: `ui.entry` only in the studio tier (our build compiles it),
   * `ui.script` everywhere else (a pack's built rail), and a script must exist.
   */
  public function testEntryAndScriptBelongToTheirTier(): void {
    $root = sys_get_temp_dir() . '/studio_manifest_' . bin2hex(random_bytes(4));
    $studioTier = $root . '/web/modules/studio/forms';
    $pack = $root . '/web/modules/packs/acme';
    foreach ([$studioTier, $pack] as $dir) {
      mkdir($dir . '/ui', 0777, TRUE);
      touch($dir . '/ui/index.tsx');
      touch($dir . '/ui/studio.js');
    }
    try {
      $base = ['id' => 'forms', 'label' => 'Forms'];
      $this->assertSame([], StudioManifest::validate($base + ['ui' => ['entry' => 'ui/index.tsx']], $studioTier));
      $this->assertSame([], StudioManifest::validate($base + ['ui' => ['script' => 'ui/studio.js']], $pack));

      $errors = StudioManifest::validate($base + ['ui' => ['entry' => 'ui/index.tsx']], $pack);
      $this->assertCount(1, $errors);
      $this->assertStringContainsString('"ui.entry" is only for studio modules under web/modules/studio/', $errors[0]);

      $errors = StudioManifest::validate($base + ['ui' => ['script' => 'ui/studio.js']], $studioTier);
      $this->assertCount(1, $errors);
      $this->assertStringContainsString('"ui.script" is for pack studios', $errors[0]);

      $errors = StudioManifest::validate($base + ['ui' => ['script' => 'ui/missing.js']], $pack);
      $this->assertStringContainsString('"ui.script" points at "ui/missing.js", which is not a file', $errors[0]);

      $this->assertTrue(StudioManifest::inStudioTier('/var/www/html/web/modules/studio/aincient_audit'));
      $this->assertTrue(StudioManifest::inStudioTier('modules/studio/aincient_audit/'));
      $this->assertFalse(StudioManifest::inStudioTier('/var/www/html/web/modules/custom/aincient_chat'));
      $this->assertFalse(StudioManifest::inStudioTier('/var/www/html/web/modules/studio/forms/tests/modules/x'));
    }
    finally {
      exec('rm -rf ' . escapeshellarg($root));
    }
  }

  /**
   * Every `*.studios.yml` in our tiers (and the test fixtures) validates
   * against the schema with its own module directory — except the one fixture
   * that exists to be invalid, which must stay invalid.
   */
  public function testEveryManifestInTheTreeValidates(): void {
    // tests/src/Unit → tests/src → tests → aincient_chat → custom → modules.
    $modules = dirname(__DIR__, 5);
    $files = array_merge(
      glob($modules . '/custom/*/*.studios.yml') ?: [],
      glob($modules . '/studio/*/*.studios.yml') ?: [],
      glob($modules . '/custom/*/tests/modules/*/*.studios.yml') ?: [],
    );
    // The fixture module's manifest is what makes this non-vacuous until the
    // first studio module lands under web/modules/studio/.
    $this->assertNotEmpty($files, 'No *.studios.yml found — the tree walk is looking in the wrong place.');

    $invalid = [];
    foreach ($files as $file) {
      $relative = basename(dirname($file)) . '/' . basename($file);
      $parsed = Yaml::parseFile($file);
      $this->assertIsArray($parsed, "$relative must be a map of studio id => definition.");
      foreach ($parsed as $id => $definition) {
        $this->assertIsArray($definition, "$relative: studio $id must be a map.");
        // What YamlDiscovery adds to every entry before the manager grades it.
        $definition += ['id' => (string) $id, 'provider' => basename(dirname($file))];
        $errors = StudioManifest::validate($definition, dirname($file));
        if ($errors !== []) {
          $invalid[$relative][$id] = $errors;
        }
      }
    }

    $this->assertArrayHasKey(self::KNOWN_BAD, $invalid, 'The bad-manifest fixture must stay invalid, or its kernel test proves nothing.');
    unset($invalid[self::KNOWN_BAD]);
    $this->assertSame([], $invalid, 'Every shipped studio manifest must validate.');
  }

}
