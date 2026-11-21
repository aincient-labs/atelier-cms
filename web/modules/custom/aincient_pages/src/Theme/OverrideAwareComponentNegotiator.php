<?php

declare(strict_types=1);

namespace Drupal\aincient_pages\Theme;

use Aincient\PageModel\ComponentId;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Render\Component\Exception\IncompatibleComponentSchema;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Theme\Component\SchemaCompatibilityChecker;
use Drupal\Core\Theme\ComponentNegotiator;
use Drupal\Core\Theme\ThemeManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Core's SDC negotiation, with a way back to the ORIGINAL component.
 *
 * A pack may override a built-in through core SDC `replaces:` (the catalog
 * keeps the original's metadata; only the rendering changes). Two things need
 * the original anyway (DECISIONS 0455, P4a):
 *  - the owner's per-site "Use original instead" — the SDC ids in
 *    `aincient_pages.site_constraint:overrides_off` never negotiate, in the
 *    shell, the studio preview and the static export alike (all render through
 *    the one plugin manager);
 *  - the Components studio's Compare, which renders the same example twice —
 *    once inside {@see withOriginals()}.
 *
 * Returning NULL is core's own "no replacement" answer: the plugin manager
 * then instantiates the requested id itself. Twig's component cache key
 * carries the negotiated provider, so the two renderings never share a
 * compiled template ACROSS requests. Within one PHP process Drupal's Twig
 * environment memoises the template class per name, so render the original
 * and the override in separate requests (Compare does).
 *
 * It also bridges the 0.30.0 move of the built-ins (DECISIONS 0534, failure
 * modes S5.1 + S5.4) for two minor releases: a pack that still includes
 * `aincient_pages:card` gets `atelier:card`, and a pack override written as
 * `replaces: 'aincient_pages:hero'` still overrides `atelier:hero` (core drops
 * a `replaces:` whose target is missing, silently). Each old id is logged once
 * and kept in State ({@see LEGACY_STATE}) so pack-validate and Site health can
 * name it.
 */
class OverrideAwareComponentNegotiator extends ComponentNegotiator {

  /**
   * Inside {@see withOriginals()}: nothing negotiates.
   */
  private bool $originals = FALSE;

  /**
   * State key: old ids seen at render, `id => [kind, by, first_seen]`.
   */
  public const LEGACY_STATE = 'aincient_pages.legacy_component_ids';

  /**
   * Old ids already noted in this process.
   *
   * @var array<string, true>
   */
  private array $noted = [];

  public function __construct(
    ThemeManagerInterface $themeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly StateInterface $state,
    private readonly LoggerInterface $logger,
    private readonly SchemaCompatibilityChecker $compatibility,
  ) {
    parent::__construct($themeManager);
  }

  /**
   * {@inheritdoc}
   */
  public function negotiate(string $component_id, array $all_definitions): ?string {
    // An old built-in id renders the built-in under its new id. Returning it
    // (not NULL) is what makes the plugin manager instantiate it: NULL would
    // mean "the requested id itself", which no longer exists.
    $requested = $component_id;
    if (!isset($all_definitions[$component_id])) {
      $bridged = ComponentId::bridge($component_id);
      if ($bridged !== NULL && isset($all_definitions[$bridged])) {
        $component_id = $bridged;
        $this->noteLegacy($requested, 'include', $bridged);
      }
    }
    $fallback = $component_id === $requested ? NULL : $component_id;

    if ($this->originals || in_array($component_id, $this->overridesOff(), TRUE)) {
      return $fallback;
    }
    return parent::negotiate($component_id, $this->bridgeReplaces($all_definitions)) ?? $fallback;
  }

  /**
   * Point each `replaces: aincient_pages:<name>` at `atelier:<name>`.
   *
   * Core checks a replacement's props against the original's at discovery,
   * but only when the target exists — so a bridged one was never checked.
   * Run core's checker here; an incompatible one is not bridged (the
   * original renders) and is logged, where core would have refused the site.
   */
  private function bridgeReplaces(array $definitions): array {
    foreach ($definitions as $id => $definition) {
      $target = $definition['replaces'] ?? NULL;
      $bridged = is_string($target) && !isset($definitions[$target]) ? ComponentId::bridge($target) : NULL;
      if ($bridged === NULL || !isset($definitions[$bridged])) {
        continue;
      }
      $original = $definitions[$bridged]['props'] ?? NULL;
      $new = $definition['props'] ?? NULL;
      try {
        if (!$original || !$new) {
          throw new IncompatibleComponentSchema('component replacement requires both components to have schema definitions.');
        }
        $this->compatibility->isCompatible($original, $new);
      }
      catch (IncompatibleComponentSchema $e) {
        $this->noteLegacy($target, 'replaces', (string) $id, $e->getMessage());
        continue;
      }
      $definitions[$id]['replaces'] = $bridged;
      $this->noteLegacy($target, 'replaces', (string) $id);
    }
    return $definitions;
  }

  /**
   * Log an old id once and remember it in State.
   *
   * @param string $kind
   *   'include' (a Twig include / render of the old id) or 'replaces'.
   * @param string $by
   *   For an include, the id it now renders; for `replaces`, the overriding
   *   component.
   * @param string|null $refused
   *   Why a `replaces` was not bridged, if it was not.
   */
  private function noteLegacy(string $old, string $kind, string $by, ?string $refused = NULL): void {
    $key = "$kind:$old:$by";
    if (isset($this->noted[$key])) {
      return;
    }
    $this->noted[$key] = TRUE;
    $seen = $this->state->get(self::LEGACY_STATE, []);
    if (!is_array($seen)) {
      $seen = [];
    }
    if (isset($seen[$key]) && ($seen[$key]['refused'] ?? NULL) === $refused) {
      return;
    }
    $seen[$key] = ['kind' => $kind, 'id' => $old, 'by' => $by, 'refused' => $refused, 'first_seen' => time()];
    $this->state->set(self::LEGACY_STATE, $seen);
    $new = ComponentId::bridge($old);
    if ($refused !== NULL) {
      $this->logger->error('Component @by replaces @old, which is now @new, but its props do not fit the original, so the original renders: @why Change it to replaces: \'@new\' and match the props.', ['@by' => $by, '@old' => $old, '@new' => $new, '@why' => $refused]);
    }
    elseif ($kind === 'replaces') {
      $this->logger->warning('Component @by replaces @old, which is now @new. It still overrides it until Atelier 0.32.0; change it to replaces: \'@new\'.', ['@by' => $by, '@old' => $old, '@new' => $new]);
    }
    else {
      $this->logger->warning('A template renders @old, which is now @new. It still works until Atelier 0.32.0; change the include to \'@new\'.', ['@old' => $old, '@new' => $new]);
    }
  }

  /**
   * Run $render with every override bypassed — the original components.
   *
   * @template T
   * @param callable(): T $render
   *
   * @return T
   */
  public function withOriginals(callable $render): mixed {
    $previous = $this->originals;
    $this->originals = TRUE;
    try {
      return $render();
    }
    finally {
      $this->originals = $previous;
    }
  }

  /**
   * The SDC ids the owner switched back to the original.
   *
   * @return list<string>
   */
  private function overridesOff(): array {
    $off = $this->configFactory->get('aincient_pages.site_constraint')->get('overrides_off');
    if (!is_array($off)) {
      return [];
    }
    // Until the 0.30.0 update rewrites them, stored ids may still be old ones.
    return array_values(array_map(
      static fn(string $id): string => ComponentId::bridge($id) ?? $id,
      array_filter($off, 'is_string'),
    ));
  }

}
