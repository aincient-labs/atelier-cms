<?php

declare(strict_types=1);

namespace Drupal\aincient_chat\Controller;

use Drupal\aincient_chat\Account\ViewerCard;
use Drupal\aincient_chat\Chat\WorkflowCatalog;
use Drupal\aincient_chat\Studio;
use Drupal\aincient_core\ModelRoleResolver;
use Drupal\Component\Utility\Html;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Menu\MenuLinkTreeInterface;
use Drupal\Core\Menu\MenuTreeParameters;
use Drupal\Core\Render\HtmlResponse;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Template\Attribute;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders the AIncient operator console as a chrome-less SPA shell.
 *
 * Pattern borrowed from Drupal Canvas (\Drupal\canvas\Controller\CanvasController):
 * a minimal HTML document with attachment placeholder tokens, returned as an
 * HtmlResponse so Drupal's HtmlResponseAttachmentsProcessor injects the library
 * assets (CSS/JS) and head tags via the real library system (versioning,
 * aggregation, dependencies) — while emitting NO theme, toolbar, skip-link, or
 * page regions, so the assistant-ui app owns the viewport.
 *
 * Our app config is injected as an inline `window.aincientChat` script rather
 * than drupalSettings: Drupal's drupalSettingsLoader is header-scoped and runs
 * before the footer settings JSON exists in a chrome-less shell, leaving
 * drupalSettings empty. The inline script is guaranteed populated before the
 * (footer) bundle runs.
 *
 * Access is enforced by the route permission; the bundle runs same-origin, so
 * the session cookie authenticates backend calls. Backend is mocked for now
 * (aincientChat.mock = true) — flip to false for the real /atelier/chat.
 */
final class ConsoleController implements ContainerInjectionInterface {

  public function __construct(
    private readonly RendererInterface $renderer,
    private readonly AccountInterface $currentUser,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly WorkflowCatalog $workflowCatalog,
    private readonly MenuLinkTreeInterface $menuTree,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ModelRoleResolver $modelRoles,
    private readonly ViewerCard $viewerCard,
    private readonly CsrfTokenGenerator $csrfToken,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('renderer'),
      $container->get('current_user'),
      $container->get('module_handler'),
      $container->get('aincient_chat.workflow_catalog'),
      $container->get('menu.link_tree'),
      $container->get('config.factory'),
      $container->get('aincient_core.model_role_resolver'),
      $container->get('aincient_chat.viewer_card'),
      $container->get('csrf_token'),
    );
  }

  /**
   * The chrome-less shell. Tokens are filled by the attachments processor.
   */
  private const HTML = <<<HTML
<!doctype html>
<html {{ html_attributes }}>
<head>
  <head-placeholder token="HEAD-HERE-PLEASE">
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="robots" content="noindex">
  <link rel="icon" type="image/svg+xml" href="{{ favicon_url }}">
  <link rel="icon" type="image/png" href="{{ favicon_png_url }}">
  <css-placeholder token="CSS-HERE-PLEASE">
  <js-placeholder token="JS-HERE-PLEASE">
  <title>Atelier</title>
</head>
<body {{ body_attributes }}>
  <div id="aincient-chat-root"></div>
  <script>window.aincientChat = {{ aincient_settings|raw }};</script>
  <js-bottom-placeholder token="JS-BOTTOM-HERE-PLEASE">
</body>
</html>
HTML;

  /**
   * The console page.
   */
  public function app(): HtmlResponse {
    $settings = [
      'endpoint' => Url::fromRoute('aincient_chat.chat')->toString(),
      // The SPA client-route base — where the console is mounted (subdir-install
      // safe). The front-end URL codec (chat-ui/src/console-config.ts) anchors
      // every deep link, room path and same-surface link check here, so the
      // path prefix lives in Drupal's route table, never hardcoded in the bundle.
      'basePath' => $this->consoleBasePath(),
      // The JSON-API prefix every console fetch is built against — DECOUPLED
      // from basePath so the backend can be relocated (a different prefix, or a
      // proxied origin) without moving the SPA. Defaults to the console base
      // (the API routes ship under the same segment), overridable via
      // `aincient_chat.settings:api_base`.
      'apiBase' => $this->apiBase(),
      // Real backend: the httpAdapter parses the /atelier/chat SSE protocol.
      // Set to TRUE to fall back to the client-side mock (no network).
      'mock' => FALSE,
      // Legacy bare display name — kept one release so an un-rebuilt bundle still
      // renders an avatar. The `viewer` object below supersedes it.
      'user' => $this->currentUser->getDisplayName(),
      // Self-contained identity card: name, email, avatar, AIncient roles, tenure
      // and status — so the operator never has to visit Drupal's /user/N page.
      'viewer' => $this->viewer(),
      // Drupal's "User account menu" drives the avatar flyout — site builders
      // add/remove links (Log out, …) via the normal menu UI. The "My account"
      // canonical link is filtered out: the `viewer` card replaces it.
      'accountMenu' => $this->accountMenuItems(),
      // Whether to offer the "Studio backend" (/admin — the curated Atelier
      // landing) door in the account flyout. Gated on the admin-overview
      // permission so a non-admin never sees a link that 403s.
      'canAdmin' => $this->currentUser->hasPermission('access administration pages'),
      // The studio → agents map. The console is always in exactly one studio
      // (default `defaultStudio`); each studio owns a set of agents (FlowDrop
      // workflows) it can run, and a default a new conversation pins. The
      // front-end registry maps a studio key to its name/icon/editor; only
      // studios present here (those with valid agents) are enabled.
      'studios' => $this->studioCatalog(),
      'defaultStudio' => $this->defaultStudio(),
      // The authoritative list of studio keys this user may enter (General +
      // every specialised studio they hold `use aincient studio <key>` for).
      // The front-end switcher intersects its registry with this so EDITOR-ONLY
      // studios (Globals — no server agent, enabled client-side) are gated too,
      // not just the agent-bearing ones filtered out of `studios` above.
      'studioAccess' => $this->studioAccess(),
    ];
    // Let other modules (e.g. aincient_assistant_ui) contribute console config.
    $this->moduleHandler->alter('aincient_console_settings', $settings);
    $settings = json_encode($settings, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $response = (new HtmlResponse($this->buildHtml($settings)))
      ->setAttachments([
        'library' => ['aincient_chat/console'],
        // Tokens are under our control and accept no user input.
        'html_response_attachment_placeholders' => [
          'head' => '<head-placeholder token="HEAD-HERE-PLEASE">',
          'styles' => '<css-placeholder token="CSS-HERE-PLEASE">',
          'scripts' => '<js-placeholder token="JS-HERE-PLEASE">',
          'scripts_bottom' => '<js-bottom-placeholder token="JS-BOTTOM-HERE-PLEASE">',
        ],
      ]);

    // Per-session shell (embeds the logout CSRF token) — never cache or share.
    $response->getCacheableMetadata()->setCacheMaxAge(0);
    $response->headers->set('Cache-Control', 'no-store, private');
    return $response;
  }

  /**
   * The SPA client-route base — the path the console is mounted at.
   *
   * Derived from the console route (subdir-install safe), trailing slash
   * trimmed so the client joins segments as `base . '/content'`. This is the
   * single source of truth for the front-end URL codec; renaming the route
   * path (e.g. /atelier) flows here automatically, no bundle change.
   */
  private function consoleBasePath(): string {
    return rtrim(Url::fromRoute('aincient_chat.console')->toString(), '/') ?: '/';
  }

  /**
   * The JSON-API prefix the console fetches against.
   *
   * A separate knob from {@see consoleBasePath}: the API routes ship under the
   * same segment, so it DEFAULTS to the console base, but an operator can point
   * the console at a decoupled backend (an absolute origin or an alternate
   * prefix) via `aincient_chat.settings:api_base`. Trailing slash trimmed so
   * the client joins `apiBase . '/chat'`.
   */
  private function apiBase(): string {
    $configured = (string) ($this->configFactory->get('aincient_chat.settings')->get('api_base') ?? '');
    $base = $configured !== '' ? $configured : Url::fromRoute('aincient_chat.console')->toString();
    return rtrim($base, '/') ?: '/';
  }

  /**
   * The studio → agents catalog for the console shell.
   *
   * Each enabled studio carries its default agent id and the ordered list of
   * agents it can run; every agent carries its label plus per-flow welcome
   * presentation (welcome/description/sampleAsks/freeformOnly). Keyed by studio
   * id so the front-end registry can map it to name/icon/editor components.
   *
   * @return array<string, array{default: string, agents: list<array{id: string, label: string}>}>
   */
  private function studioCatalog(): array {
    $out = [];
    foreach ($this->workflowCatalog->studios() as $key => $studio) {
      // Per-studio access gate: a studio the user can't enter never reaches the
      // shell (the save/load routes enforce the same permission server-side).
      $case = Studio::tryFromKey($key);
      if ($case !== NULL && !$case->accessibleBy($this->currentUser)) {
        continue;
      }
      // Release feature flag: an in-progress studio is omitted from the shell so
      // it never renders (UI-only gate; its backend routes stay untouched).
      if ($this->hiddenByFeatureFlag($case)) {
        continue;
      }
      // The Media studio's AGENT (Nano Banana) is OPTIONAL: it appears only when
      // an image provider is configured (the `image` model role is bound). Absent
      // that binding, drop the agent so `media` stays out of the catalog — the
      // studio still surfaces as EDITOR-ONLY (its Studio/Preview components + the
      // studioAccess list), exactly the "non-AI editor, no chat rail" state. The
      // generate_image tool resolves through the SAME binding, so a lit rail and a
      // working tool can never disagree.
      if ($case === Studio::Media && $this->modelRoles->imageBinding() === NULL) {
        continue;
      }
      $agents = [];
      foreach ($studio['agents'] as $id => $label) {
        $agents[] = [
          'id' => $id,
          'label' => $label,
        ] + $this->workflowCatalog->presentation($id);
      }
      $out[$key] = [
        'default' => $studio['default'],
        'agents' => $agents,
      ];
    }
    return $out;
  }

  /**
   * The studio keys this user may enter — General plus every specialised studio
   * they hold `use aincient studio <key>` for. Drives the front-end switcher's
   * access gate (incl. editor-only studios with no server agent).
   *
   * @return list<string>
   */
  private function studioAccess(): array {
    $access = [];
    foreach (Studio::cases() as $studio) {
      if ($this->hiddenByFeatureFlag($studio)) {
        continue;
      }
      if ($studio->accessibleBy($this->currentUser)) {
        $access[] = $studio->value;
      }
    }
    return $access;
  }

  /**
   * Whether a studio is hidden from the console by a release feature flag.
   *
   * A UI-only gate: an in-progress studio is kept out of both the agent catalog
   * and the access list so it never renders, while its backend routes stay in
   * place (still permission-gated). Checks ships OFF until the policy runtime
   * (Phase 4) lands — flip `features.checks_enabled` in `aincient_chat.settings`.
   */
  private function hiddenByFeatureFlag(?Studio $studio): bool {
    if ($studio === Studio::Checks) {
      return !(bool) $this->configFactory->get('aincient_chat.settings')->get('features.checks_enabled');
    }
    return FALSE;
  }

  /**
   * The studio a new session opens in, never one the user can't enter.
   *
   * The configured default ({@see WorkflowCatalog::defaultStudio}) is honoured
   * when accessible; otherwise General (always open).
   */
  private function defaultStudio(): string {
    $default = $this->workflowCatalog->defaultStudio();
    $case = Studio::tryFromKey($default);
    if ($case !== NULL && $case->accessibleBy($this->currentUser)) {
      return $default;
    }
    return Studio::default()->value;
  }

  /**
   * The signed-in user's identity card for the console account flyout.
   *
   * A read-only snapshot (name, email, avatar, AIncient roles, tenure, status)
   * that lets the operator see who they are without leaving for Drupal's
   * /user/N page. Delegated to {@see ViewerCard}, which the self-service
   * account pane (AccountController) shares to return a refreshed card.
   *
   * @return array{name: string, email: ?string, avatarUrl: ?string, initial: string, roles: list<string>, joined: string, memberFor: string, status: string}
   */
  private function viewer(): array {
    return $this->viewerCard->build($this->currentUser);
  }

  /**
   * Drupal's "User account menu" as plain {title, url} items for the SPA.
   *
   * Access-checked and sorted via the default tree manipulators, so the list
   * honours per-user access exactly like a themed menu block would. The menu
   * is flat by design — child links are ignored.
   *
   * The "My account" canonical link (entity.user.canonical) is dropped: the
   * `viewer` identity card replaces it, so the flyout never routes to /user/N.
   *
   * The Log out link is CSRF-protected. The URL generator's token is NOT bound
   * to the active session (it validates anonymously), so a serialized SPA link
   * would bounce to /user/logout/confirm — hence we rebuild it via a non-routed
   * URI (bypassing the CSRF outbound processor) with the real session token
   * CsrfAccessCheck expects for the 'user/logout' path. The embedded token is
   * why the shell response is no-store.
   *
   * @return list<array{title: string, url: string}>
   */
  private function accountMenuItems(): array {
    $tree = $this->menuTree->load('account', (new MenuTreeParameters())->onlyEnabledLinks());
    $tree = $this->menuTree->transform($tree, [
      ['callable' => 'menu.default_tree_manipulators:checkAccess'],
      ['callable' => 'menu.default_tree_manipulators:generateIndexAndSort'],
    ]);
    $items = [];
    foreach ($tree as $element) {
      if ($element->access !== NULL && !$element->access->isAllowed()) {
        continue;
      }
      $routeName = $element->link->getRouteName();
      // Skip the "My account" link — the identity card supersedes it. Covers
      // both the menu's default `user.page` (/user redirect) and a direct
      // canonical link, so the flyout never routes to Drupal's profile page.
      if (in_array($routeName, ['user.page', 'entity.user.canonical'], TRUE)) {
        continue;
      }
      if ($routeName === 'user.logout') {
        $url = Url::fromUri('base:/user/logout', [
          'query' => ['token' => $this->csrfToken->get('user/logout')],
        ])->toString();
      }
      else {
        $url = $element->link->getUrlObject()->toString();
      }
      $items[] = [
        'title' => (string) $element->link->getTitle(),
        'url' => $url,
      ];
    }
    return $items;
  }

  /**
   * Builds the shell HTML, filling html/body attributes + the inline settings.
   *
   * We render a bare `html` theme stub to harvest the correct <html>/<body>
   * attributes (langcode, dir, …) — but we do NOT use html.html.twig, because
   * it adds the skip link / #main-content that this SPA shell has no use for.
   */
  private function buildHtml(string $settingsJson): string {
    $stub = ['#theme' => 'html', 'page' => []];
    $dom = Html::load((string) $this->renderer->renderInIsolation($stub));

    // item(1): Drupal's rendered <html>/<body>, not the DOMDocument wrapper.
    $html_element = $dom->getElementsByTagName('html')->item(1);
    $body_element = $dom->getElementsByTagName('body')->item(1);

    $html_attributes = new Attribute();
    $body_attributes = new Attribute();
    foreach ($html_element?->attributes ?? [] as $attr) {
      $html_attributes->setAttribute($attr->name, $attr->value);
    }
    foreach ($body_element?->attributes ?? [] as $attr) {
      $body_attributes->setAttribute($attr->name, $attr->value);
    }

    $build = [
      '#type' => 'inline_template',
      '#template' => self::HTML,
      '#context' => [
        'html_attributes' => $html_attributes,
        'body_attributes' => $body_attributes,
        'aincient_settings' => $settingsJson,
        // The Atelier mark, served statically from the module (subdir-safe via
        // base_path). Twig autoescapes the attribute values. The SVG is primary
        // (crisp at any size); the PNG is the fallback for browsers that don't
        // render SVG favicons (notably Safari's flaky support) — both link tags
        // ship, each browser picks the format it can draw.
        'favicon_url' => base_path() . $this->moduleHandler->getModule('aincient_chat')->getPath() . '/images/favicon.svg',
        'favicon_png_url' => base_path() . $this->moduleHandler->getModule('aincient_chat')->getPath() . '/images/favicon.png',
      ],
    ];
    return (string) $this->renderer->renderInIsolation($build);
  }

}
