<?php

declare(strict_types=1);

namespace Drupal\aincient_studio_components\Controller;

use Drupal\aincient_pages\Catalog\ExampleRenderer;
use Drupal\aincient_pages\Theme\OverrideAwareComponentNegotiator;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Components studio's live preview (DECISIONS 0455, P1).
 *
 * Stateless: the studio posts the list to show (every component available in
 * the current scope — the contact sheet — or the one selected, with its
 * variant/tone), and gets back one standalone document rendered from the
 * components' declared examples in the published brand. Never persists.
 * `original: true` renders every component as its ORIGINAL, bypassing pack
 * overrides — the Compare half (DECISIONS 0455, P3/P4a).
 */
final class ComponentsPreviewController implements ContainerInjectionInterface {

  /**
   * More than every built-in plus a large pack; a runaway list is truncated.
   */
  private const MAX_ITEMS = 120;

  public function __construct(
    private readonly ExampleRenderer $examples,
    private readonly object $negotiator,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('aincient_pages.example_renderer'),
      $container->get('Drupal\Core\Theme\ComponentNegotiator'),
    );
  }

  /**
   * POST /atelier/components/render.
   */
  public function render(Request $request): Response {
    $data = json_decode((string) $request->getContent(), TRUE);
    $items = is_array($data['items'] ?? NULL) ? array_slice(array_values($data['items']), 0, self::MAX_ITEMS) : [];
    $clean = [];
    foreach ($items as $item) {
      if (!is_array($item) || !is_string($item['component'] ?? NULL)) {
        continue;
      }
      $clean[] = [
        'component' => $item['component'],
        'example' => max(0, (int) ($item['example'] ?? 0)),
        'variant' => is_string($item['variant'] ?? NULL) ? $item['variant'] : '',
        'tone' => is_string($item['tone'] ?? NULL) ? $item['tone'] : '',
      ];
    }
    $title = is_string($data['title'] ?? NULL) && $data['title'] !== '' ? $data['title'] : 'Components';
    $render = fn() => $this->examples->render($clean, $title);
    $response = !empty($data['original']) && $this->negotiator instanceof OverrideAwareComponentNegotiator
      ? $this->negotiator->withOriginals($render)
      : $render();
    $response->headers->set('Cache-Control', 'no-store');
    return $response;
  }

}
