<?php

declare(strict_types=1);

namespace Drupal\aincient_onboarding\Plugin\AiFunctionCall;

use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Onboarding capability: show the user an interactive map of the console.
 *
 * Emits a `{ "__widget__": "studio_tour", "payload": … }` envelope the console
 * renders as a card grid — one card per console area (Pages, Library, Design
 * System, Globals), each with a live status line and a deep link into that
 * room. The payload carries only studio KEYS plus server-derived status text;
 * the widget owns names, icons, and hrefs (via the shared studio registry and
 * `consoleBase()`), so display renames and subdir installs never go stale here.
 *
 * Optionally carries an intro video (`tour_video_url` /` tour_video_title` in
 * aincient_onboarding.settings) the widget renders as a click-to-load embed.
 * Empty (the default) means no video block.
 */
#[FunctionCall(
  id: 'aincient_onboarding:studio_tour',
  function_name: 'aincient_studio_tour',
  name: 'Studio tour',
  description: 'Show the user a visual map of the console: one card per area (Pages, Library, Design System, Globals) with what it is for, its current status, and a link that opens it. Call this when the user asks to be shown around, asks what they can do here, asks where to find or change something (pages, images, colours, fonts, logo, header, footer), or at the start of onboarding. Takes no arguments — it renders the map.',
)]
final class StudioTour extends FunctionCallBase implements ExecutableFunctionCallInterface {

  /**
   * The entity type manager (page/media counts for the status lines).
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The config factory (the optional tour video).
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The current user.
   */
  protected AccountInterface $currentUser;

  /**
   * The readable output (the widget envelope).
   */
  protected string $result = '';

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->configFactory = $container->get('config.factory');
    $instance->currentUser = $container->get('current_user');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function execute(): void {
    if (!$this->currentUser->hasPermission('use aincient operator console')) {
      $this->result = 'Error: you do not have access to the operator console.';
      return;
    }

    $pages = $this->countEntities('node', 'type', 'aincient_page');
    $media = $this->countEntities('media');

    // Status lines are server truth; everything display-owned (name, icon,
    // href) stays client-side. NULL count = the entity type isn't installed —
    // the widget simply shows the card without a status line.
    $rooms = [
      [
        'key' => 'content',
        'status' => $pages === NULL ? '' : ($pages === 0
          ? 'No pages yet — a good first stop'
          : sprintf('%d page%s so far', $pages, $pages === 1 ? '' : 's')),
      ],
      [
        'key' => 'media',
        'status' => $media === NULL ? '' : ($media === 0
          ? 'The shelf is empty — add or generate images'
          : sprintf('%d item%s on the shelf', $media, $media === 1 ? '' : 's')),
      ],
      [
        'key' => 'design_system',
        'status' => 'Colours, type, and the feel of the site',
      ],
      [
        'key' => 'globals',
        'status' => 'Header, footer, logo, and site identity',
      ],
    ];

    $payload = ['rooms' => $rooms];

    $settings = $this->configFactory->get('aincient_onboarding.settings');
    $video_url = trim((string) ($settings->get('tour_video_url') ?? ''));
    if ($video_url !== '') {
      $payload['video'] = [
        'url' => $video_url,
        'title' => trim((string) ($settings->get('tour_video_title') ?? '')),
      ];
    }

    $this->result = (string) json_encode([
      '__widget__' => 'studio_tour',
      'payload' => $payload,
      'summary' => 'Here’s a map of your studio — pick a room to open it.',
    ]);
  }

  /**
   * Counts entities of a type (optionally filtered), or NULL when not installed.
   *
   * Counts use the current user's access so the tour never reveals more than
   * the studios themselves would.
   */
  private function countEntities(string $entity_type, ?string $bundle_field = NULL, ?string $bundle = NULL): ?int {
    if (!$this->entityTypeManager->hasDefinition($entity_type)) {
      return NULL;
    }
    try {
      $query = $this->entityTypeManager->getStorage($entity_type)->getQuery()
        ->accessCheck(TRUE)
        ->count();
      if ($bundle_field !== NULL && $bundle !== NULL) {
        $query->condition($bundle_field, $bundle);
      }
      return (int) $query->execute();
    }
    catch (\Throwable) {
      return NULL;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getReadableOutput(): string {
    return $this->result;
  }

}
