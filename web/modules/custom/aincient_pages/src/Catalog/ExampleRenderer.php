<?php

declare(strict_types=1);

namespace Drupal\aincient_pages\Catalog;

use Drupal\aincient_pages\Controller\PageSpikeController;
use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Render\RendererInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Renders components from their declared `examples:` (DECISIONS 0455, P1).
 *
 * The one place an example becomes HTML — the Components studio's preview
 * (`/atelier/components/render`) and the pack dev surfaces (`/atelier/dev/render`,
 * the gallery) all go through it. A PLACEABLE renders through the page pipeline
 * ({@see PageSpikeController::renderFragment()}), so an example is page-schema
 * shaped — exactly what the agent or the Content studio would place — and image
 * tokens, markdown and collections resolve as they would on a page. A
 * non-placeable (a blog part, chrome) renders as a raw SDC with its declared
 * props + slots, the original dev-route behaviour.
 *
 * Example images (D9): a `placeholder:<name>` value anywhere in an example's
 * props resolves to a shipped file — the declaring module's
 * `images/placeholders/<name>.svg`, else aincient_pages' set. Only this renderer
 * resolves the token: it is never a media entity and a page cannot store it.
 */
final class ExampleRenderer {

  /**
   * The placeholder token: `placeholder:<name>`, name = [a-z0-9-].
   */
  private const PLACEHOLDER = '/^placeholder:([a-z0-9][a-z0-9-]*)$/';

  public function __construct(
    private readonly ComponentCatalogInterface $catalog,
    private readonly ClassResolverInterface $classResolver,
    private readonly ModuleExtensionList $moduleList,
    private readonly RendererInterface $renderer,
  ) {}

  /**
   * The declared examples of a discovered component (any tier), or [].
   *
   * @return list<array{name?: string, props?: array, slots?: array}>
   */
  public function examples(string $name): array {
    $def = $this->catalog->discovered()->def($name);
    $examples = is_array($def['examples'] ?? NULL) ? $def['examples'] : [];
    return array_values(array_filter($examples, 'is_array'));
  }

  /**
   * One example as a placeable section: its props with the variant/tone
   * override applied and placeholders resolved. NULL when the component is
   * not a discovered placeable or the example does not exist.
   *
   * @param string $name
   *   The component machine name.
   * @param int $index
   *   The example index.
   * @param string $variant
   *   A variant to show instead of the example's ('' = the example's own).
   * @param string $tone
   *   A tone to show instead of the example's ('' = the example's own).
   */
  public function section(string $name, int $index = 0, string $variant = '', string $tone = ''): ?array {
    $discovered = $this->catalog->discovered();
    if (!in_array($name, $discovered->placeableNames(), TRUE)) {
      return NULL;
    }
    $example = $this->examples($name)[$index] ?? NULL;
    if ($example === NULL) {
      return NULL;
    }
    $props = is_array($example['props'] ?? NULL) ? $example['props'] : [];
    if ($variant !== '' && in_array($variant, $discovered->variantsFor($name) ?? [], TRUE)) {
      $props['variant'] = $variant;
    }
    if ($tone !== '' && in_array($tone, $discovered->tonesFor($name), TRUE)) {
      $props['tone'] = $tone;
    }
    $provider = (string) ($discovered->placeable($name)['provider'] ?? '');
    return [
      // The component name is the slot id: the canvas's click-to-select key.
      'id' => $name,
      'component' => $name,
      'props' => $this->resolvePlaceholders($props, $provider),
    ];
  }

  /**
   * Render a list of examples as one standalone document (the contact sheet,
   * or a single selected component).
   *
   * @param list<array{component: string, example?: int, variant?: string, tone?: string}> $items
   *   What to render, in order. Unknown components / examples are skipped.
   * @param string $title
   *   The document title.
   */
  public function render(array $items, string $title = 'Components'): Response {
    $sections = [];
    foreach ($items as $item) {
      $section = $this->section(
        (string) ($item['component'] ?? ''),
        (int) ($item['example'] ?? 0),
        (string) ($item['variant'] ?? ''),
        (string) ($item['tone'] ?? ''),
      );
      if ($section !== NULL) {
        $sections[] = $section;
      }
    }
    return $this->spike()->renderFragment($sections, $title);
  }

  /**
   * Render ONE example of any atelier component to HTML (no shell) — a
   * placeable through the page pipeline is not possible without the shell, so
   * this is the raw-SDC path for the dev route's non-placeable tiers. Throws
   * nothing: a broken example renders as an error block.
   */
  public function renderRaw(string $name, int $index = 0, string $tone = ''): ?string {
    $def = $this->catalog->discovered()->def($name);
    $example = $this->examples($name)[$index] ?? NULL;
    $pluginId = $this->catalog->discovered()->pluginId($name);
    if ($def === NULL || $example === NULL || $pluginId === NULL) {
      return NULL;
    }
    $props = $this->resolvePlaceholders(is_array($example['props'] ?? NULL) ? $example['props'] : [], (string) ($def['provider'] ?? ''));
    if ($tone !== '') {
      $props['tone'] = $tone;
    }
    $build = ['#type' => 'component', '#component' => $pluginId, '#props' => $props];
    if (is_array($example['slots'] ?? NULL)) {
      $build['#slots'] = array_map(
        static fn($slot) => ['#markup' => is_string($slot) ? $slot : ''],
        $example['slots'],
      );
    }
    try {
      return (string) $this->renderer->renderInIsolation($build);
    }
    catch (\Throwable $e) {
      return '<pre style="padding:2rem;white-space:pre-wrap;color:#b91c1c">' . htmlspecialchars($e->getMessage(), ENT_QUOTES) . '</pre>';
    }
  }

  /**
   * Replace every `placeholder:<name>` string (at any depth) with the shipped
   * file's URL; an unknown name is left as-is (it renders as a broken image,
   * which the example author sees in the gallery).
   */
  public function resolvePlaceholders(array $props, string $provider = ''): array {
    array_walk_recursive($props, function (&$value) use ($provider): void {
      if (is_string($value) && preg_match(self::PLACEHOLDER, $value, $m)) {
        $value = $this->placeholderUrl($m[1], $provider) ?? $value;
      }
    });
    return $props;
  }

  /**
   * The URL of a shipped placeholder: the provider's own first, then ours.
   */
  private function placeholderUrl(string $name, string $provider): ?string {
    foreach (array_unique(array_filter([$provider, 'aincient_pages'])) as $module) {
      try {
        $path = $this->moduleList->getPath($module);
      }
      catch (\Throwable) {
        continue;
      }
      foreach (['svg', 'webp', 'jpg', 'png'] as $ext) {
        if (is_file("$path/images/placeholders/$name.$ext")) {
          return base_path() . "$path/images/placeholders/$name.$ext";
        }
      }
    }
    return NULL;
  }

  /**
   * The page renderer (a controller, built through the class resolver so it
   * gets its own container-injected dependencies).
   */
  private function spike(): PageSpikeController {
    return $this->classResolver->getInstanceFromDefinition(PageSpikeController::class);
  }

}
