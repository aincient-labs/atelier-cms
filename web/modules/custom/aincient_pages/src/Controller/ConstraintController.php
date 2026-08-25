<?php

declare(strict_types=1);

namespace Drupal\aincient_pages\Controller;

use Drupal\aincient_pages\Catalog\ComponentCatalogInterface;
use Drupal\aincient_pages\ComponentCatalog;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
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

  public function __construct(
    private readonly ComponentCatalogInterface $catalog,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('aincient_pages.catalog'),
      $container->get('config.factory'),
    );
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
   * Body: `{ components?: string[], tones?: string[], variants?: {name: [..]} }`
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
    if (is_array($data['variants'] ?? NULL)) {
      $variants = [];
      foreach ($data['variants'] as $name => $removed) {
        if (is_string($name) && $name !== '' && is_array($removed) && $removed !== []) {
          $variants[$name] = array_values(array_unique(array_map('strval', $removed)));
        }
      }
      $config->set('variants', $variants);
      $applied[] = 'variants';
    }

    if ($applied !== []) {
      // Saving invalidates config:aincient_pages.site_constraint, which the
      // compiled per-kind catalogs are tagged with — later requests recompile
      // on their own. The memo reset is for THIS response: its fresh state
      // must reflect the write, not the palette compiled before it.
      $config->save();
      $this->catalog->reset();
    }

    return new JsonResponse(['applied' => $applied] + $this->state());
  }

  /**
   * The pane's full state: stored constraint + vocabulary + honest feedback.
   */
  private function state(): array {
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

    return [
      'constraint' => [
        'components' => $config->get('components') ?? [],
        'tones' => $config->get('tones') ?? [],
        'variants' => (object) ($config->get('variants') ?? []),
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

}
