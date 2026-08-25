<?php

declare(strict_types=1);

namespace Drupal\aincient_pages\Controller;

use Drupal\aincient_pages\BrandRepository;
use Drupal\aincient_pages\Catalog\ComponentCatalogInterface;
use Drupal\aincient_pages\Catalog\KindCheck;
use Drupal\aincient_pages\Catalog\PackValidator;
use Drupal\aincient_pages\SchemaLinter;
use Drupal\aincient_core\Inference\AiGateway;
use Drupal\aincient_core\ModelRoles;
use Drupal\Component\Serialization\Yaml;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Theme\ComponentPluginManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The pack developer's ground-truth endpoints — DEV MODE ONLY (W9).
 *
 * Every route here carries `_atelier_dev` ({@see AtelierDevAccessCheck}):
 * they exist solely on an `atelier pack dev` stack, where the `atelier mcp`
 * stdio server proxies them so the developer's coding agent works against the
 * live contract (the compiled catalog, the real gate, the exact prompt text)
 * instead of guessing. Deliberately session-less machine endpoints; the
 * gallery is the one human-facing page. Nothing here mutates anything.
 *
 * Each JSON tool result mirrors one deliverable this plan already owes in CLI
 * form — the HTTP shape is a thin projection, never new logic
 * (plans/byo-components.md W9).
 */
final class PackDevController implements ContainerInjectionInterface {

  /** Gallery iframe widths — phone / tablet / desktop. */
  private const WIDTHS = [375, 768, 1280];

  public function __construct(
    private readonly ComponentCatalogInterface $catalog,
    private readonly PackValidator $packValidator,
    private readonly KindCheck $kindCheck,
    private readonly ComponentPluginManager $componentManager,
    private readonly ModuleExtensionList $moduleList,
    private readonly RendererInterface $renderer,
    private readonly BrandRepository $brand,
    private readonly AiGateway $gateway,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('aincient_pages.catalog'),
      $container->get('aincient_pages.pack_validator'),
      $container->get('aincient_pages.kind_check'),
      $container->get('plugin.manager.sdc'),
      $container->get('extension.list.module'),
      $container->get('renderer'),
      $container->get('aincient_pages.brand'),
      $container->get('aincient_core.inference.gateway'),
    );
  }

  /**
   * GET /atelier/dev/catalog?kind=K — the compiled EffectiveCatalog as JSON.
   *
   * The same object every consumer reads (renderer, validator, prompt,
   * studio), serialized whole so an agent sees exactly what the site sees.
   */
  public function catalog(Request $request): JsonResponse {
    $kind = (string) $request->query->get('kind', 'landing');
    $effective = $this->catalog->for($kind);
    return new JsonResponse([
      'kind' => $effective->kind(),
      'kinds' => $this->catalog->kinds(),
      'mode' => $effective->mode(),
      'opener' => $effective->opener(),
      'limits' => $effective->limits(),
      'tones' => $effective->tones(),
      'variants' => $effective->variants(),
      'sections' => $effective->sections(),
      'layout' => $effective->layout(),
      'reference' => $effective->reference(),
      'chrome' => $effective->chrome(),
      'content_atoms' => $effective->contentAtoms(),
      'reserved_names' => $effective->reservedNames(),
      'stylesheets' => $this->catalog->discovered()->stylesheets(),
      'warnings' => $effective->warnings(),
    ]);
  }

  /**
   * GET /atelier/dev/pack-validate?module=M — the full pack-validate pass.
   *
   * Identical to `drush atelier:pack-validate` (one shared service): gate over
   * all definitions, CSS lint, atelier.pack.yml when a module is named.
   */
  public function packValidate(Request $request): JsonResponse {
    try {
      $report = $this->packValidator->validate((string) $request->query->get('module', ''));
    }
    catch (\InvalidArgumentException $e) {
      return new JsonResponse(['error' => $e->getMessage()], 404);
    }
    return new JsonResponse($report + ['ok' => $report['rejected'] === 0]);
  }

  /**
   * GET /atelier/dev/kind-check — §6.3 dry run: catalog vs stored pages.
   */
  public function kindCheck(): JsonResponse {
    $report = $this->kindCheck->run();
    return new JsonResponse($report + ['ok' => $report['impacts'] === 0]);
  }

  /**
   * GET /atelier/dev/prompt-manifest?kind=K — the EXACT text the page agent
   * sees for this kind, with its size (the ≤9k-char budget is a shipped test).
   *
   * Token count is the chars/4 heuristic, labelled as such — good enough to
   * watch a pack blow the budget, not a tokenizer.
   */
  public function promptManifest(Request $request): JsonResponse {
    $kind = (string) $request->query->get('kind', 'landing');
    $text = $this->catalog->for($kind)->manifest();
    return new JsonResponse([
      'kind' => $kind,
      'text' => $text,
      'chars' => mb_strlen($text),
      'approx_tokens' => intdiv(mb_strlen($text), 4),
    ]);
  }

  /**
   * GET /atelier/dev/agent-eval?kind=K&ask=…&expect=component — §5's per-kind
   * eval: "would the page agent place my component?" (Phase 5).
   *
   * One REAL model call on the same role the page agent runs, against the same
   * per-kind manifest the prompt inlines — then the answer is graded by the
   * same catalog: unknown components, prop lint, the kind's opener. `expect`
   * (optional) asserts a specific component was placed. This is the top
   * support ticket pre-empted ("the agent won't use my component"): the
   * verdict names what went wrong — a missing `use` hint, a palette the kind
   * excludes, or props the model can't guess.
   */
  public function agentEval(Request $request): JsonResponse {
    $kind = (string) $request->query->get('kind', 'landing');
    $ask = trim((string) $request->query->get('ask', ''));
    $expect = trim((string) $request->query->get('expect', ''));
    if ($ask === '') {
      return new JsonResponse(['error' => 'Pass ?ask= — the user request to evaluate (e.g. "add a spotlight for our spring launch").'], 400);
    }
    if (!$this->gateway->canText(ModelRoles::REASONING)) {
      return new JsonResponse(['error' => 'The reasoning role is unbound — connect a provider in onboarding before running evals.'], 409);
    }

    $effective = $this->catalog->for($kind);
    $prompt = implode("\n", [
      'You compose web pages by emitting section ops. Use ONLY the components below, and ONLY the props listed for each.',
      '',
      $effective->manifest(),
      '',
      'USER REQUEST: ' . $ask,
      '',
      'Reply with ONLY a JSON array of ops, no prose, no code fence:',
      '[{"op":"add_section","component":"…","props":{…}}, …]',
    ]);
    $raw = $this->gateway->text($prompt, ModelRoles::REASONING, 'aincient_pages_agent_eval');

    $ops = $this->decodeOps($raw);
    if ($ops === NULL) {
      return new JsonResponse([
        'kind' => $kind,
        'ask' => $ask,
        'ok' => FALSE,
        'error' => 'The model did not return a parseable ops array.',
        'raw' => $raw,
      ]);
    }

    $placed = [];
    $unknown = [];
    $lint = [];
    foreach ($ops as $op) {
      if (!is_array($op) || ($op['op'] ?? '') !== 'add_section') {
        continue;
      }
      $component = (string) ($op['component'] ?? '');
      $placed[] = $component;
      if (!in_array($component, $effective->placeableNames(), TRUE)) {
        $unknown[] = $component;
        continue;
      }
      if (is_array($op['props'] ?? NULL)) {
        $lint = array_merge($lint, SchemaLinter::lint($effective, $component, $op['props']));
      }
    }
    $opener = $effective->opener();
    $openerOk = $opener === NULL || $opener === '' || ($placed[0] ?? '') === $opener
      // A one-section ask ("add a spotlight") legitimately opens with it.
      || count($placed) <= 1;
    $expectPlaced = $expect === '' || in_array($expect, $placed, TRUE);

    return new JsonResponse([
      'kind' => $kind,
      'ask' => $ask,
      'expect' => $expect === '' ? NULL : $expect,
      'ops' => $ops,
      'placed' => $placed,
      'unknown_components' => $unknown,
      'prop_lint' => $lint,
      'opener_ok' => $openerOk,
      'expect_placed' => $expectPlaced,
      'ok' => $unknown === [] && $lint === [] && $openerOk && $expectPlaced,
    ]);
  }

  /**
   * Decode the model's reply into an ops array — tolerant of a stray code
   * fence or leading prose, strict about the result being a list.
   */
  private function decodeOps(string $raw): ?array {
    $text = trim($raw);
    if (preg_match('/```(?:json)?\s*(.*?)```/s', $text, $m)) {
      $text = trim($m[1]);
    }
    if (!str_starts_with($text, '[')) {
      $start = strpos($text, '[');
      $end = strrpos($text, ']');
      if ($start === FALSE || $end === FALSE || $end < $start) {
        return NULL;
      }
      $text = substr($text, $start, $end - $start + 1);
    }
    $decoded = json_decode($text, TRUE);
    return is_array($decoded) && array_is_list($decoded) ? $decoded : NULL;
  }

  /**
   * GET /atelier/dev/design-tokens — the token contract a pack's CSS routes
   * through: the declared tokens plus the generated preset CSS a pack imports.
   */
  public function designTokens(): JsonResponse {
    $path = $this->moduleList->getPath('aincient_pages');
    $read = static fn(string $file): ?string => is_file("$path/$file") ? (string) file_get_contents("$path/$file") : NULL;
    $yml = $read('design-tokens.yml');
    return new JsonResponse([
      'tokens' => $yml !== NULL ? Yaml::decode($yml) : NULL,
      'preset' => [
        // The importable preset a pack's build/input.css pulls in (W5): the
        // committed generated files, verbatim.
        'tokens_css' => $read('build/tokens.generated.css'),
        'palette_css' => $read('build/tw-palette.generated.css'),
      ],
    ]);
  }

  /**
   * GET /atelier/dev/render?component=X&example=0&tone=inverted — one declared
   * example rendered in the real shell CSS (module bundle + pack sheets +
   * brand :root), standalone HTML for the gallery iframes and the MCP
   * `render_example` tool.
   */
  public function renderExample(Request $request): Response {
    $name = (string) $request->query->get('component', '');
    $index = (int) $request->query->get('example', 0);
    $tone = (string) $request->query->get('tone', '');

    $def = $this->rawDefinition($name);
    if ($def === NULL) {
      throw new NotFoundHttpException(sprintf('No atelier component "%s".', $name));
    }
    $examples = $def['thirdPartySettings']['atelier']['examples'] ?? [];
    if (!isset($examples[$index]) || !is_array($examples[$index])) {
      throw new NotFoundHttpException(sprintf('Component "%s" declares no example #%d — add an `examples:` entry to its thirdPartySettings.atelier.', $name, $index));
    }
    $example = $examples[$index];
    $props = is_array($example['props'] ?? NULL) ? $example['props'] : [];
    if ($tone !== '') {
      $props['tone'] = $tone;
    }
    $build = [
      '#type' => 'component',
      '#component' => (string) $def['id'],
      '#props' => $props,
    ];
    if (is_array($example['slots'] ?? NULL)) {
      $build['#slots'] = array_map(
        static fn($slot) => ['#markup' => is_string($slot) ? $slot : ''],
        $example['slots'],
      );
    }
    try {
      $content = (string) $this->renderer->renderInIsolation($build);
    }
    catch (\Throwable $e) {
      // A broken example must render AS a failure, not 500 the gallery grid:
      // the whole point of the surface is showing the developer what's wrong.
      $content = '<pre style="padding:2rem;white-space:pre-wrap;color:#b91c1c">' . htmlspecialchars($e->getMessage(), ENT_QUOTES) . '</pre>';
    }
    return new Response($this->shell($content, (string) ($example['name'] ?? "$name #$index")));
  }

  /**
   * GET /atelier/packs/{module}/gallery — every example the pack declares,
   * at three widths, plus the inverted tone where the component supports it.
   *
   * The dev surface (plans/byo-components.md §6.2): examples triple as agent
   * few-shots, gallery fixtures and the visual-regression baseline.
   */
  public function gallery(string $module): Response {
    $components = [];
    foreach ($this->componentManager->getDefinitions() as $def) {
      if ((string) ($def['provider'] ?? '') !== $module || !isset($def['thirdPartySettings']['atelier'])) {
        continue;
      }
      $components[(string) $def['machineName']] = $def;
    }
    if ($components === []) {
      throw new NotFoundHttpException(sprintf('Module "%s" provides no atelier components.', $module));
    }
    ksort($components);

    $base = base_path();
    $body = '';
    $missing = [];
    foreach ($components as $name => $def) {
      $atelier = $def['thirdPartySettings']['atelier'];
      $examples = is_array($atelier['examples'] ?? NULL) ? $atelier['examples'] : [];
      if ($examples === []) {
        $missing[] = $name;
        continue;
      }
      // The tone enum lives in the SDC schema — the atelier `tone` prop hint
      // is mandatorily empty (the shared enum is injected by the catalog).
      $tones = is_array($def['props']['properties']['tone']['enum'] ?? NULL) ? $def['props']['properties']['tone']['enum'] : [];
      $body .= '<section class="component"><h2>' . htmlspecialchars($name, ENT_QUOTES)
        . ' <small>' . htmlspecialchars((string) ($atelier['tier'] ?? ''), ENT_QUOTES) . '</small></h2>';
      foreach (array_keys($examples) as $i) {
        $label = htmlspecialchars((string) ($examples[$i]['name'] ?? "example $i"), ENT_QUOTES);
        $variants = [['', $label]];
        if (in_array('inverted', $tones, TRUE)) {
          $variants[] = ['inverted', "$label · tone: inverted"];
        }
        foreach ($variants as [$tone, $caption]) {
          $src = htmlspecialchars("{$base}atelier/dev/render?component=$name&example=$i" . ($tone !== '' ? "&tone=$tone" : ''), ENT_QUOTES);
          $body .= "<h3>$caption</h3><div class=\"row\">";
          foreach (self::WIDTHS as $w) {
            $body .= "<figure><iframe src=\"$src\" width=\"$w\" loading=\"lazy\" title=\"$caption at {$w}px\"></iframe><figcaption>{$w}px</figcaption></figure>";
          }
          $body .= '</div>';
        }
      }
      $body .= '</section>';
    }
    if ($missing !== []) {
      $body = '<p class="missing">No <code>examples:</code> declared (not rendered): '
        . htmlspecialchars(implode(', ', $missing), ENT_QUOTES) . '</p>' . $body;
    }

    $title = htmlspecialchars("$module — component gallery", ENT_QUOTES);
    $html = <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>$title</title>
<style>
  body { margin: 0; padding: 2rem; font: 14px/1.5 ui-sans-serif, system-ui, sans-serif; background: #f4f2ee; color: #1c1a17; }
  h1 { font-size: 1.4rem; } h2 { margin: 2.5rem 0 .25rem; font-size: 1.1rem; } h2 small { font-weight: normal; color: #63605a; }
  h3 { margin: 1rem 0 .5rem; font-size: .85rem; font-weight: 500; color: #63605a; }
  .row { display: flex; gap: 1rem; align-items: flex-start; overflow-x: auto; }
  figure { margin: 0; } figcaption { font-size: .75rem; color: #63605a; margin-top: .25rem; }
  iframe { border: 1px solid #d8d4cc; background: #fff; height: 480px; display: block; }
  .missing { padding: .75rem 1rem; background: #fdf3e7; border: 1px solid #eeddc4; }
</style>
</head>
<body>
<h1>$title</h1>
$body
</body>
</html>
HTML;
    return new Response($html);
  }

  /** The raw SDC definition for an atelier component, by machine name. */
  private function rawDefinition(string $name): ?array {
    foreach ($this->componentManager->getDefinitions() as $def) {
      if ((string) ($def['machineName'] ?? '') === $name && isset($def['thirdPartySettings']['atelier'])) {
        return $def;
      }
    }
    return NULL;
  }

  /**
   * A minimal standalone page in the REAL page CSS: the module bundle, every
   * catalog-declared pack stylesheet, then the brand :root override — the
   * same cascade order the live shell emits (PageSpikeController::shell()).
   */
  private function shell(string $content, string $title): string {
    $path = $this->moduleList->getPath('aincient_pages');
    $links = '<link rel="stylesheet" href="' . htmlspecialchars(base_path() . "$path/assets/aincient-pages.css", ENT_QUOTES) . '">';
    foreach ($this->catalog->discovered()->stylesheets() as $sheet) {
      $modulePath = $this->moduleList->getPath($sheet['provider']);
      if ($modulePath === '' || !is_file("$modulePath/{$sheet['path']}")) {
        continue;
      }
      $links .= "\n  " . '<link rel="stylesheet" href="' . htmlspecialchars(base_path() . "$modulePath/{$sheet['path']}?v=" . @filemtime("$modulePath/{$sheet['path']}"), ENT_QUOTES) . '">';
    }
    $brandCss = $this->brand->cssVariables();
    $brand = $brandCss !== '' ? "\n  <style>$brandCss</style>" : '';
    $titleEsc = htmlspecialchars($title, ENT_QUOTES);
    return <<<HTML
<!doctype html>
<html lang="en" dir="ltr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  $links$brand
  <title>$titleEsc</title>
</head>
<body class="min-h-screen bg-background text-foreground antialiased font-sans">
$content
</body>
</html>
HTML;
  }

}
