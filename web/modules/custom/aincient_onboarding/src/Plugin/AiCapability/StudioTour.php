<?php

declare(strict_types=1);

namespace Drupal\aincient_onboarding\Plugin\AiCapability;

use Drupal\aincient_core\Attribute\Capability;
use Drupal\aincient_core\Capability\CapabilityBase;
use Drupal\aincient_core\Capability\ExecutableCapabilityInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
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
 * ONE ROOM OR ALL OF THEM. The optional `rooms` argument narrows the map to the
 * room(s) the ask actually maps to — "where do I build a page?" is a HANDOFF, not
 * a tour, and answering it with all four cards plus "pick a room" makes the user
 * do the routing the model already did. Naming one room renders one card and the
 * widget switches its header to a handoff ("Pages — this is where landing pages
 * are built"). Omitting the argument keeps the original behaviour (the whole map),
 * which is what "show me around" wants.
 *
 * It is deliberately the SAME widget: the client owns access filtering, names,
 * icons and hrefs for tour cards, and a second dedicated widget would have to
 * duplicate all of it and then stay in step with it forever.
 *
 * UNKNOWN ROOM KEYS ARE IGNORED, and an argument that names nothing we recognise
 * falls back to the whole map. A tour is a navigation aid: the full map is always
 * a truthful answer, whereas an empty widget is a dead end — so a model that
 * invents a room name degrades to the old behaviour instead of to nothing.
 *
 * Optionally carries an intro video (`tour_video_url` /` tour_video_title` in
 * aincient_onboarding.settings) the widget renders as a click-to-load embed.
 * Empty (the default) means no video block.
 */
#[Capability(
  id: 'aincient_onboarding:studio_tour',
  function_name: 'aincient_studio_tour',
  name: 'Studio tour',
  description: 'Show the user a visual map of the console — one card per area with what it is for, its current status, and a link that opens it. Call this when the user asks to be shown around, asks what they can do here, asks where to find or change something (pages, images, colours, fonts, logo, header, footer), or at the start of onboarding. Pass "rooms" with EXACTLY ONE room key when the ask maps to a single room, so the user gets one card that opens it instead of a menu they must re-read: content = Pages (build/edit pages, landing pages, posts), media = Library (images, uploads, image generation), design_system = Design System (colours, typography, mood), globals = Globals (header, footer, logo, site name). Omit "rooms" only for a genuine "show me around" / "what can I do here" — that renders all four.',
  context_definitions: [
    'rooms' => new ContextDefinition(
      data_type: 'list',
      label: new TranslatableMarkup('Rooms'),
      description: new TranslatableMarkup('Which rooms to show, as an ARRAY of room keys drawn from: "content" (Pages), "media" (Library), "design_system", "globals". Pass exactly ONE key when the ask belongs to one room — the card becomes a direct handoff into it. Omit entirely to show all four (a real tour). Unknown keys are ignored.'),
      required: FALSE,
      constraints: [
        'SimpleToolItems' => [
          'type' => 'string',
          'description' => 'A room key: content, media, design_system, or globals.',
        ],
      ],
    ),
  ],
)]
final class StudioTour extends CapabilityBase implements ExecutableCapabilityInterface {

  /**
   * The room keys the tour can show, in display order (mirrors the studio enum).
   */
  private const ROOM_KEYS = ['content', 'media', 'design_system', 'globals'];

  /**
   * Display names (and near misses) the model may send, mapped to room keys.
   */
  private const ROOM_ALIASES = [
    'pages' => 'content',
    'page' => 'content',
    'library' => 'media',
    'design' => 'design_system',
    'design_studio' => 'design_system',
    'brand' => 'design_system',
    'global' => 'globals',
    'site' => 'globals',
  ];

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

    $wanted = $this->wantedRooms();
    if ($wanted !== []) {
      $rooms = array_values(array_filter(
        $rooms,
        static fn (array $room): bool => in_array($room['key'], $wanted, TRUE),
      ));
    }

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
      'summary' => count($rooms) === 1
        ? 'That work lives in one room — the card opens it.'
        : 'Here’s a map of your studio — pick a room to open it.',
    ]);
  }

  /**
   * The room keys the model asked for, normalised — or [] for "the whole map".
   *
   * Accepts the display names a model naturally reaches for ("Pages",
   * "Library") alongside the canonical keys, since the keys are an internal
   * vocabulary and the prompt talks in room names. Anything unrecognised is
   * dropped; if that leaves nothing, we return [] so the caller renders all
   * four (see the class docblock — a full map beats an empty widget).
   */
  private function wantedRooms(): array {
    $raw = $this->getContextValue('rooms') ?? [];
    if (!is_array($raw)) {
      return [];
    }
    $wanted = [];
    foreach ($raw as $value) {
      if (!is_string($value) && !is_numeric($value)) {
        continue;
      }
      $key = strtr(strtolower(trim((string) $value)), [' ' => '_', '-' => '_']);
      $key = self::ROOM_ALIASES[$key] ?? $key;
      if (in_array($key, self::ROOM_KEYS, TRUE) && !in_array($key, $wanted, TRUE)) {
        $wanted[] = $key;
      }
    }
    return $wanted;
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
