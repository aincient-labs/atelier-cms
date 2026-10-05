<?php

declare(strict_types=1);

namespace Drupal\aincient_studio_components\Controller;

use Drupal\aincient_pages\Catalog\AdmissionGate;
use Drupal\aincient_pages\Catalog\ComponentCatalogInterface;
use Drupal\aincient_pages\Catalog\ExampleRenderer;
use Drupal\aincient_pages\ComponentCatalog;
use Drupal\aincient_pages\UsageIndex;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Theme\ComponentPluginManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The Components governance pane's JSON seam (plans/byo-components.md W1b).
 *
 * Serves and saves `aincient_pages.site_constraint` — the site-wide narrowing
 * layer (decision #2): components, tones and per-component variants REMOVED
 * across every kind. One decision, one home: killing a tone here never means
 * editing N kinds. Removal semantics keep it narrowing-only — the pane can
 * disable what discovery admitted, never invent or re-admit anything the gate
 * rejected.
 *
 * The manifest pairs the CURRENT constraint with the UNDIMINISHED discovered
 * vocabulary ({@see ComponentCatalogInterface::discovered()}) — the effective
 * catalog has removals already applied, so it cannot render the checkboxes —
 * plus the landing kind's compile warnings as the pane's honest feedback
 * (never-fatal floor, decision #4). Save merges by provided key and returns
 * the full fresh state, the ChromeController convention.
 */
final class ConstraintController implements ContainerInjectionInterface {

  /**
   * The fixed role groups the rail lists built-ins under (DECISIONS 0455 D12).
   * Anything not listed — every pack component until packs can declare a
   * group — falls into `other`.
   */
  public const GROUPS = [
    'openers' => ['hero', 'banner'],
    'story' => ['content', 'features', 'markdown', 'accordion', 'faq'],
    'media' => ['image', 'gallery'],
    'social_proof' => ['testimonials', 'logos', 'stats', 'team'],
    'conversion' => ['cta', 'pricing', 'newsletter'],
    'structure' => ['grid', 'divider'],
    'from_your_site' => ['collection', 'embed', 'block'],
  ];

  public function __construct(
    private readonly ComponentCatalogInterface $catalog,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly UsageIndex $usage,
    private readonly ExampleRenderer $examples,
    private readonly ComponentPluginManager $components,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('aincient_pages.catalog'),
      $container->get('config.factory'),
      $container->get('aincient_pages.usage_index'),
      $container->get('aincient_pages.example_renderer'),
      $container->get('plugin.manager.sdc'),
    );
  }

  /**
   * The role group of a component (`other` when unmapped).
   */
  public static function groupOf(string $name): string {
    foreach (self::GROUPS as $group => $names) {
      if (in_array($name, $names, TRUE)) {
        return $group;
      }
    }
    return 'other';
  }

  /**
   * GET /atelier/constraint/usage?key=c:hero — where one usage key is used
   * (the rail's "Used on", loaded when a row expands).
   */
  public function usage(Request $request): JsonResponse {
    $key = (string) $request->query->get('key', '');
    if (!preg_match('/^[cvt]:[a-z0-9_-]+(?::[a-z0-9_-]+)?$/', $key)) {
      return new JsonResponse(['error' => 'Pass ?key=c:<component> (or v:/t: keys).'], 400);
    }
    return new JsonResponse(['key' => $key] + $this->usage->where($key, 8));
  }

  /**
   * GET /atelier/constraint/manifest — current constraint + full vocabulary.
   */
  public function manifest(): JsonResponse {
    return new JsonResponse($this->state());
  }

  /**
   * POST /atelier/constraint/save — merge the provided keys, return fresh state.
   *
   * Body: `{ components?: string[], tones?: string[], variants?: {name: [..]},
   * component_tones?: {name: [..]} }`
   * — each key REPLACES its stored list when present (the pane publishes its
   * whole staged slice); an absent key is left untouched.
   */
  public function save(Request $request): JsonResponse {
    $data = json_decode((string) $request->getContent(), TRUE);
    if (!is_array($data)) {
      return new JsonResponse(['error' => 'Body must be a JSON object.'], 400);
    }

    $config = $this->configFactory->getEditable('aincient_pages.site_constraint');
    $applied = [];

    if (is_array($data['components'] ?? NULL)) {
      // Unknown names are stored anyway (a pack may be temporarily uninstalled;
      // its removal must survive) — the compiler warns, never fatals.
      $config->set('components', array_values(array_unique(array_map('strval', $data['components']))));
      $applied[] = 'components';
    }
    if (is_array($data['tones'] ?? NULL)) {
      $tones = array_values(array_intersect(ComponentCatalog::TONES, array_map('strval', $data['tones'])));
      // Refuse the one destructive shape outright: removing EVERY tone. The
      // compiler would degrade + warn, but the pane should never publish a
      // constraint that is silently ignored wholesale.
      if (count($tones) >= count(ComponentCatalog::TONES)) {
        return new JsonResponse(['error' => 'At least one tone must remain available.'], 422);
      }
      $config->set('tones', $tones);
      $applied[] = 'tones';
    }
    $discovered = $this->catalog->discovered();
    if (is_array($data['variants'] ?? NULL)) {
      $variants = [];
      foreach ($data['variants'] as $name => $removed) {
        if (is_string($name) && $name !== '' && is_array($removed) && $removed !== []) {
          $removed = array_values(array_unique(array_map('strval', $removed)));
          // The last-variant guard: a component keeps at least one variant.
          $declared = $discovered->variantsFor($name);
          if ($declared !== NULL && array_diff($declared, $removed) === []) {
            return new JsonResponse(['error' => sprintf('At least one variant of "%s" must remain available.', $name)], 422);
          }
          $variants[$name] = $removed;
        }
      }
      $config->set('variants', $variants);
      $applied[] = 'variants';
    }
    if (is_array($data['component_tones'] ?? NULL)) {
      $componentTones = [];
      foreach ($data['component_tones'] as $name => $removed) {
        if (is_string($name) && $name !== '' && is_array($removed) && $removed !== []) {
          $removed = array_values(array_intersect(ComponentCatalog::TONES, array_map('strval', $removed)));
          // The last-tone guard, per component.
          if (array_diff($discovered->tonesFor($name), $removed) === []) {
            return new JsonResponse(['error' => sprintf('At least one tone of "%s" must remain available.', $name)], 422);
          }
          if ($removed !== []) {
            $componentTones[$name] = $removed;
          }
        }
      }
      $config->set('component_tones', $componentTones);
      $applied[] = 'component_tones';
    }

    if (is_array($data['overrides_off'] ?? NULL)) {
      // Only built-ins a pack actually overrides can be switched back (P4b).
      $overridden = array_keys(self::replacements($this->components->getDefinitions()));
      $next = array_values(array_intersect($overridden, array_map('strval', $data['overrides_off'])));
      // Which SDC renders changes everywhere, like a theme switch: pages don't
      // carry the constraint's tag, so their cached renders go too.
      $rerender = $next != ($config->get('overrides_off') ?? []);
      $config->set('overrides_off', $next);
      $applied[] = 'overrides_off';
    }
    if ($applied !== []) {
      // The compiled per-kind catalogs are tagged with
      // config:aincient_pages.site_constraint. Core invalidates that tag on an
      // UPDATE save only — the site's FIRST publish creates this config, so
      // the tag is invalidated here explicitly or that publish would stay
      // invisible until a cache rebuild (seen live, DECISIONS 0455). The memo
      // reset is for THIS response: its state must reflect the write.
      $config->save();
      Cache::invalidateTags(array_merge($config->getCacheTags(), !empty($rerender) ? ['rendered'] : []));
      $this->catalog->reset();
    }

    return new JsonResponse(['applied' => $applied] + $this->state());
  }

  /**
   * The pane's full state: stored constraint + vocabulary + honest feedback.
   * Public for the agent's context node, which reads the same picture the
   * rail shows.
   *
   * @see \Drupal\aincient_studio_components\Plugin\FlowDropNodeProcessor\ComponentsState
   */
  public function state(): array {
    $config = $this->configFactory->get('aincient_pages.site_constraint');
    $discovered = $this->catalog->discovered();

    $components = [];
    foreach (['section' => $discovered->sections(), 'layout' => $discovered->layout(), 'reference' => $discovered->reference()] as $tier => $defs) {
      foreach ($defs as $name => $def) {
        $components[] = [
          'name' => $name,
          'tier' => $tier,
          'icon' => $def['icon'] ?? '',
          'use' => $def['use'] ?? '',
        ];
      }
    }

    // The landing kind's effective palette + compile warnings — what the site
    // actually ends up with under the current constraint.
    $effective = $this->catalog->for('landing');

    // Manifest v2 (DECISIONS 0455): one entry per discovered placeable with
    // everything the rail shows — provenance, role group, its OWN variants +
    // tones, usage counts, example names. The v1 `vocabulary` keys stay one
    // release for anything still reading them.
    $counts = $this->usage->counts();
    $definitions = $this->components->getDefinitions();
    $replacements = self::replacements($definitions);
    $overridesOff = $config->get('overrides_off') ?? [];
    $entries = [];
    foreach ($components as $component) {
      $name = $component['name'];
      $def = $discovered->placeable($name) ?? [];
      $variants = $discovered->variantsFor($name) ?? [];
      $tones = array_key_exists('tone', $def['props'] ?? []) ? $discovered->tonesFor($name) : [];
      $usage = $counts["c:$name"] ?? ['pages' => 0, 'blocks' => 0];
      $sdcId = ($def['provider'] ?? '') . ':' . $name;
      $entries[] = $component + [
        // A pack's SDC `replaces:` override of this component (P3) and
        // whether the owner switched it back to the original (P4b).
        'replaced_by' => $replacements[$sdcId] ?? NULL,
        'override_off' => in_array($sdcId, $overridesOff, TRUE),
        'provider' => (string) ($def['provider'] ?? ''),
        // The SDC's own description — the rail's fallback summary for a
        // pack component (built-ins have a plain-language one in the UI).
        'description' => (string) ($definitions[$sdcId]['description'] ?? ''),
        'group' => self::groupOf($name),
        'variants' => $variants,
        'tones' => $tones,
        'examples' => array_map(
          static fn(array $e, int $i): string => (string) ($e['name'] ?? "Example " . ($i + 1)),
          $this->examples->examples($name),
          array_keys($this->examples->examples($name)),
        ),
        'usage' => [
          'pages' => $usage['pages'],
          'blocks' => $usage['blocks'],
          'variants' => (object) array_filter(array_combine($variants, array_map(static fn(string $v) => array_sum($counts["v:$name:$v"] ?? []), $variants)) ?: []),
          'tones' => (object) array_filter(array_combine($tones, array_map(static fn(string $t) => array_sum($counts["t:$name:$t"] ?? []), $tones)) ?: []),
        ],
      ];
    }

    return [
      'version' => 2,
      'components' => $entries,
      // The "Applies to" menu (P1b): every kind from the registry.
      'scopes' => \Drupal::classResolver(KindController::class)->scopes(),
      'groups' => array_merge(array_keys(self::GROUPS), ['other']),
      // Pack components the admission gate refused — the rail's "Can't be
      // used" group, each with a plain-language reason (P3).
      'blocked' => self::blocked($definitions),
      'constraint' => [
        'overrides_off' => $overridesOff,
        'components' => $config->get('components') ?? [],
        'tones' => $config->get('tones') ?? [],
        'variants' => (object) ($config->get('variants') ?? []),
        'component_tones' => (object) ($config->get('component_tones') ?? []),
      ],
      'vocabulary' => [
        'components' => $components,
        'tones' => ComponentCatalog::TONES,
        'variants' => (object) $discovered->variants(),
      ],
      'effective' => [
        'placeable' => $effective->placeableNames(),
        'tones' => $effective->tones(),
        'warnings' => $effective->warnings(),
      ],
    ];
  }

  /**
   * Which pack overrides each built-in: original SDC id => the replacement.
   *
   * @return array<string, array{id: string, provider: string, label: string}>
   */
  public static function replacements(array $definitions): array {
    $out = [];
    foreach ($definitions as $id => $definition) {
      $target = $definition['replaces'] ?? NULL;
      if (is_string($target) && $target !== '') {
        $out[$target] = [
          'id' => (string) $id,
          'provider' => (string) ($definition['provider'] ?? ''),
          'label' => (string) ($definition['name'] ?? $id),
        ];
      }
    }
    return $out;
  }

  /**
   * The atelier-carrying definitions the admission gate refused.
   *
   * @return list<array{name: string, provider: string, label: string, reason: string, detail: string}>
   */
  public static function blocked(array $definitions): array {
    // The gate's default virtual names (block) are the compiler's too.
    $verdicts = AdmissionGate::checkEach($definitions);
    $out = [];
    foreach ($definitions as $id => $definition) {
      $name = (string) ($definition['machineName'] ?? '');
      $verdict = $verdicts[$id] ?? [];
      if (!is_array($definition['thirdPartySettings']['atelier'] ?? NULL) || ($verdict['errors'] ?? []) === []) {
        continue;
      }
      $errors = $verdict['errors'];
      $out[] = [
        'name' => $name,
        'provider' => (string) ($definition['provider'] ?? ''),
        'label' => (string) ($definition['name'] ?? $name),
        'reason' => self::plainReason($errors[0]),
        'detail' => implode(' ', $errors),
      ];
    }
    return $out;
  }

  /**
   * One admission-gate error in the owner's words; the raw text stays the
   * detail a pack developer reads.
   */
  public static function plainReason(string $error): string {
    return match (TRUE) {
      str_contains($error, 'atelier api version') => 'Made for a different version of Atelier.',
      str_starts_with($error, 'unknown tier') => 'Its pack declares a kind of component Atelier does not know.',
      str_starts_with($error, 'name collides') => 'Another component already uses this name.',
      str_contains($error, 'reserved layout word') => 'Its name is reserved by Atelier.',
      str_contains($error, 'locked vocabulary') => 'It has a field Atelier cannot fill.',
      str_starts_with($error, 'variant ') => 'One of its variants is declared incorrectly.',
      str_contains($error, 'image slot') => 'It has an image area Atelier cannot fill.',
      str_starts_with($error, 'examples[') => 'Its examples are malformed.',
      str_starts_with($error, 'stylesheet') => 'Its stylesheet path is not allowed.',
      default => 'It does not meet the rules for Atelier components.',
    };
  }

}
