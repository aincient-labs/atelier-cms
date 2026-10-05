<?php

declare(strict_types=1);

namespace Drupal\aincient_studio_components\Plugin\FlowDropNodeProcessor;

use Drupal\aincient_pages\BrandRepository;
use Drupal\aincient_pages\ColorContrast;
use Drupal\aincient_pages\SiteIdentity;
use Drupal\aincient_studio_components\Controller\ConstraintController;
use Drupal\aincient_studio_components\Controller\KindController;
use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\flowdrop\Attribute\FlowDropNodeProcessor;
use Drupal\flowdrop\DTO\ParameterBagInterface;
use Drupal\flowdrop\DTO\ValidationResult;
use Drupal\flowdrop\Plugin\FlowDropNodeProcessor\AbstractFlowDropNodeProcessor;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Reads the site's component governance at RUNTIME for the Components agent.
 *
 * The {@see \Drupal\aincient_flows\Plugin\FlowDropNodeProcessor\BrandState}
 * pattern (DECISIONS 0455, P2): a pure data node between the workflow's
 * `variables` input and the system-prompt template. It passes the incoming
 * variables through and adds `components_state` — the same picture the
 * studio rail shows (built by the studio's own controllers, so it cannot
 * drift): every component with its role group, provenance, what is on or off
 * everywhere, usage on pages and blocks, its variants and tones, each page
 * type's rules, the site's identity brief, and how each tone's surface reads
 * under the PUBLISHED brand (WCAG contrast). Read server-side every turn — the
 * agent never depends on what the browser happens to send.
 */
#[FlowDropNodeProcessor(
  id: "components_state",
  label: new TranslatableMarkup("Components state"),
  description: "Read the site's component governance (on/off, usage, page types, tone contrast, identity) at runtime and emit it into the prompt as components_state.",
  version: "0.1.0",
)]
class ComponentsState extends AbstractFlowDropNodeProcessor {

  /**
   * Each tone's surface/on-colour token pair (the SDCs' tone classes:
   * default = background, muted = muted, brand = primary, inverted =
   * foreground on background reversed — the same pair, so the same ratio).
   */
  private const TONE_PAIRS = [
    'default' => 'background',
    'muted' => 'muted',
    'brand' => 'primary',
    'inverted' => 'background',
  ];

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    private readonly ClassResolverInterface $classResolver,
    private readonly SiteIdentity $identity,
    private readonly ColorContrast $contrast,
    private readonly BrandRepository $brand,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('class_resolver'),
      $container->get('aincient_pages.site_identity'),
      $container->get('aincient_pages.color_contrast'),
      $container->get('aincient_pages.brand'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function process(ParameterBagInterface $params): array {
    $state = $this->brief();
    return [
      'variables' => $params->getArray('variables', []) + ['components_state' => $state],
      'components_state' => $state,
    ];
  }

  /**
   * The compact, model-facing governance brief.
   */
  public function brief(): string {
    $site = $this->classResolver->getInstanceFromDefinition(ConstraintController::class)->state();
    $kinds = $this->classResolver->getInstanceFromDefinition(KindController::class);
    $offSite = $site['constraint']['components'];
    $variantsOff = (array) $site['constraint']['variants'];
    $tonesOff = (array) $site['constraint']['component_tones'];

    $lines = [];
    $identity = $this->identity->promptBrief();
    if ($identity !== '') {
      $lines[] = "SITE IDENTITY:\n$identity";
    }

    $scopes = [];
    foreach ($site['scopes'] as $scope) {
      $what = $scope['mode'] === 'recipe' ? 'fixed layout, nothing to place' : ($scope['fragment'] ? 'reusable block' : 'page type');
      $scopes[] = sprintf('%s "%s" (%s, %d)', $scope['id'], $scope['label'], $what, $scope['count']);
    }
    $lines[] = 'SCOPES: site (everywhere) · ' . implode(' · ', $scopes);

    $rows = [];
    foreach ($site['components'] as $c) {
      $name = $c['name'];
      $parts = [
        $c['group'] . ($c['provider'] !== '' && $c['provider'] !== 'aincient_pages' ? ', from ' . $c['provider'] : ''),
        in_array($name, $offSite, TRUE) ? 'OFF everywhere' : 'on',
        sprintf('used on %d pages, %d blocks', $c['usage']['pages'], $c['usage']['blocks']),
      ];
      if ($c['variants'] !== []) {
        $off = $variantsOff[$name] ?? [];
        $parts[] = 'variants ' . implode(', ', $c['variants']) . ($off !== [] ? ' (off: ' . implode(', ', $off) . ')' : '');
      }
      if (!empty($tonesOff[$name])) {
        $parts[] = 'tones off: ' . implode(', ', $tonesOff[$name]);
      }
      $use = trim(preg_replace('/\s+/', ' ', (string) $c['use']));
      $rows[] = "- $name — " . implode('; ', $parts) . ($use !== '' ? ' — ' . mb_strimwidth($use, 0, 140, '…') : '');
    }
    $lines[] = "COMPONENTS (name — group; everywhere; usage; variants; purpose):\n" . implode("\n", $rows);

    $tones = $site['constraint']['tones'];
    $lines[] = 'TONES EVERYWHERE: off: ' . ($tones === [] ? 'none' : implode(', ', $tones));

    $contrast = $this->toneContrast();
    if ($contrast !== '') {
      $lines[] = "TONE CONTRAST under the published brand (WCAG AA needs 4.5):\n$contrast";
    }

    $kindLines = [];
    foreach ($site['scopes'] as $scope) {
      if ($scope['mode'] === 'recipe') {
        continue;
      }
      $k = $kinds->kindState($scope['id'])['kind'];
      $rule = $k['include_new']
        ? 'new pack components allowed automatically' . ($k['removed'] !== [] ? '; off here: ' . implode(', ', $k['removed']) : '')
        : 'only these allowed: ' . (((array) $k['components']) === [] ? 'everything' : implode(', ', array_keys((array) $k['components'])));
      $extra = [];
      if ($k['opener'] !== '') {
        $extra[] = 'opener ' . $k['opener'];
      }
      foreach ((array) $k['limits'] as $name => $max) {
        $extra[] = "$name at most $max per page";
      }
      $kindLines[] = "- {$scope['id']}: $rule" . ($extra !== [] ? '; ' . implode('; ', $extra) : '');
    }
    if ($kindLines !== []) {
      $lines[] = "PAGE TYPE RULES:\n" . implode("\n", $kindLines);
    }

    return implode("\n\n", $lines);
  }

  /**
   * One line per tone: its contrast ratio under the published brand.
   */
  private function toneContrast(): string {
    $bySurface = [];
    foreach ($this->contrast->pairReport($this->brand->tokens()) as $pair) {
      $bySurface[$pair['surface']] = $pair['ratio'];
    }
    $out = [];
    foreach (self::TONE_PAIRS as $tone => $surface) {
      $ratio = $bySurface[$surface] ?? NULL;
      if ($ratio !== NULL) {
        $out[] = sprintf('- %s: %.1f (%s)', $tone, $ratio, $ratio >= ColorContrast::AA_NORMAL ? 'passes' : 'FAILS');
      }
    }
    return implode("\n", $out);
  }

  /**
   * {@inheritdoc}
   */
  public function validateParams(array $params): ValidationResult {
    return ValidationResult::success();
  }

  /**
   * {@inheritdoc}
   */
  public function getParameterSchema(): array {
    return [
      'type' => 'object',
      'properties' => [
        'variables' => [
          'type' => 'object',
          'title' => 'Variables',
          'description' => "The incoming system-prompt template variables. Wire the workflow's variables input here; the node returns it enriched with components_state.",
          'default' => [],
          'required' => FALSE,
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getOutputSchema(): array {
    return [
      'type' => 'object',
      'properties' => [
        'variables' => [
          'type' => 'object',
          'description' => 'The incoming template variables enriched with components_state. Wire this into the system-prompt PromptTemplate node.',
        ],
        'components_state' => [
          'type' => 'string',
          'description' => "The site's component governance brief.",
        ],
      ],
    ];
  }

}
