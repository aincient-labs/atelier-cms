<?php

declare(strict_types=1);

namespace Drupal\aincient_chat\Controller;

use Drupal\Component\Utility\Crypt;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\State\StateInterface;
use Drupal\aincient_chat\Chat\ChatProcessorInterface;
use Drupal\aincient_chat\Chat\SessionThreadStore;
use Drupal\aincient_chat\Chat\StreamRelay;
use Drupal\aincient_chat\Chat\ThreadNamer;
use Drupal\aincient_chat\Chat\WorkflowCatalog;
use Drupal\aincient_chat\Event\ChatEvent;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * HTTP entry points for the chat layer. assistant-ui's ChatModelAdapter targets
 * these (topology §4a). The POST streams a turn as Server-Sent Events; the GET
 * lets the UI re-subscribe to a thread for long-running flows.
 */
final class ChatController extends ControllerBase {

  /**
   * The onboarding-complete State flag.
   *
   * Kept as a literal so the chat layer detects the first-run state with only
   * core services (config + state) and never has to depend on the
   * onboarding module — an unknown `aincient_onboarding` workflow id simply
   * isn't dispatched if the module isn't installed. The completion flag is set
   * for ANY connected provider, so this gate closes whichever one the user
   * picked; a pinned default chat provider (set headlessly) also counts.
   */
  private const ONBOARDING_COMPLETED = 'aincient_onboarding.completed';

  public function __construct(
    private readonly ChatProcessorInterface $processor,
    private readonly SessionThreadStore $threadStore,
    private readonly WorkflowCatalog $workflowCatalog,
    private readonly StreamRelay $streamRelay,
    private readonly StateInterface $state,
    private readonly ThreadNamer $threadNamer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('aincient_chat.processor'),
      $container->get('aincient_chat.thread_store'),
      $container->get('aincient_chat.workflow_catalog'),
      $container->get('aincient_chat.stream_relay'),
      $container->get('state'),
      $container->get('aincient_chat.thread_namer'),
    );
  }

  /**
   * POST /aincient/chat — run a turn, stream typed events as SSE.
   */
  public function chat(Request $request): StreamedResponse {
    $payload = json_decode($request->getContent() ?: '{}', TRUE) ?: [];
    $message = trim((string) ($payload['message'] ?? ''));
    $threadId = (string) ($payload['thread_id'] ?? '');
    if ($threadId === '') {
      $threadId = 'thr_' . Crypt::randomBytesBase64(9);
    }
    $flow = isset($payload['flow']) && $payload['flow'] !== '' ? (string) $payload['flow'] : NULL;

    // The user's workflow pick for a NEW conversation. Gate it against the
    // admin-exposed catalog here, before it enters the stream: an id the
    // admin didn't expose is dropped (the dispatcher then runs the default).
    $workflow = isset($payload['workflow']) && $payload['workflow'] !== '' ? (string) $payload['workflow'] : NULL;
    if ($workflow !== NULL && $this->workflowCatalog->resolve($workflow) !== $workflow) {
      $workflow = NULL;
    }

    // Per-turn client context (transient): each studio seeds the workflow's
    // declared `variables` input, which its agent maps into a system-prompt
    // PromptTemplate node. The page studio sends `page_context` (the unsaved
    // page-schema draft → live_page_state + translation_directive); the brand
    // console sends `brand_context` (the unsaved token overrides + staged fonts
    // → live_preview_state); the Globals studio sends `chrome_context` (the
    // unsaved identity + header/footer layout → live_chrome_state). A turn comes
    // from one studio, so at most one is
    // present. It rides turn state only — never the persisted conversation
    // buffer, and never an ambient side channel.
    $variables = $this->pageVariables($payload['page_context'] ?? NULL)
      ?? $this->brandVariables($payload['brand_context'] ?? NULL)
      ?? $this->chromeVariables($payload['chrome_context'] ?? NULL)
      // The Media studio sends `media_context` — the `media:<id>` token of the
      // image currently open — so the image agent can EDIT "this" image
      // (image→image) without the user pasting a token.
      ?? $this->mediaVariables($payload['media_context'] ?? NULL);
    // The page agent ALSO gets the site's already-known context — the brand
    // identity brief + the header/footer nav links — read server-side from the
    // persisted config (unlike the drafts above, these aren't a browser draft).
    // So content creators never restate the site name/voice or paste existing
    // links. The content studio flags its turns with `site_context`; it fires
    // even for a FRESH page (which sends no page_context above), which is
    // exactly when the brief matters most. Merged with `+` — the keys
    // (site_identity/site_nav) never collide with the draft vars, and a NULL
    // draft becomes just the site context.
    if (!empty($payload['site_context'])) {
      $variables = ($variables ?? []) + $this->siteContextVariables();
    }
    $clientContext = array_filter([
      'variables' => $variables,
    ], static fn($v): bool => $v !== NULL && $v !== '' && $v !== []);

    // First-run guard: with no usable Anthropic key (and onboarding not yet
    // completed), force the deterministic onboarding flow regardless of the
    // typed message or the user's workflow pick — the agent can't run without a
    // key, so AI setup can't be skipped. An operator who set the key via env is
    // already configured and never sees this.
    if ($this->needsOnboarding()) {
      $flow = 'onboarding';
      $workflow = NULL;
    }

    $uid = (int) $this->currentUser()->id();

    // Wrapped-up guard: a locked thread is finished (its page is published) and
    // accepts no new turns — the console hides the composer, but a crafted
    // request still can't append. New threads (no/blank id) are never locked.
    $locked = $threadId !== '' && $this->threadStore->isLocked($threadId, $uid);

    // Resource homing (studio-navigation.md §3.4): a page/audit turn on a saved
    // node carries its identity so the thread homes to that resource. Stamped
    // AFTER the turn (the session may not exist until the first message runs);
    // home-once semantics live in the store, so re-sending is harmless.
    $workingNode = NULL;
    $wn = $payload['working_node'] ?? NULL;
    if (is_array($wn) && !empty($wn['nid'])) {
      $workingNode = [
        'nid' => (int) $wn['nid'],
        'langcode' => trim((string) ($wn['langcode'] ?? '')),
      ];
    }

    $processor = $this->processor;
    $relay = $this->streamRelay;
    $threadStore = $this->threadStore;
    $namer = $this->threadNamer;
    $response = new StreamedResponse(static function () use ($processor, $relay, $threadStore, $namer, $message, $threadId, $flow, $workflow, $clientContext, $locked, $workingNode, $uid): void {
      // Disable PHP output buffering so each frame flushes immediately.
      while (ob_get_level() > 0) {
        ob_end_flush();
      }

      if ($message === '') {
        echo ChatEvent::error('Empty message.')->toSseFrame();
        echo ChatEvent::done(['thread_id' => $threadId])->toSseFrame();
        flush();
        return;
      }

      if ($locked) {
        echo ChatEvent::error('This conversation is wrapped up. Start a new thread to keep going.')->toSseFrame();
        echo ChatEvent::done(['thread_id' => $threadId])->toSseFrame();
        flush();
        return;
      }

      // Arm the side channel: the FlowDrop turn blocks the generator while it
      // runs, but its per-node events fire in this same request — subscribers
      // push them straight onto the stream between yields.
      $relay->arm(static function (ChatEvent $event): void {
        echo $event->toSseFrame();
        flush();
      });
      try {
        foreach ($processor->processTurn($message, $threadId, $flow, $workflow, $clientContext) as $event) {
          echo $event->toSseFrame();
          flush();
        }
        // The turn created/reused the session — now home the thread to its
        // resource (no-op if already homed, or if the turn made no session).
        if ($workingNode !== NULL) {
          $threadStore->setWorkingNode($threadId, $uid, $workingNode['nid'], $workingNode['langcode']);
        }
        // The studio names the thread after its FIRST exchange (study 02,
        // Plate 8) — one small FAST-role call, after the reply has already
        // streamed so it never delays the answer. No-op once named; every
        // failure leaves the raw-first-message fallback in place.
        $title = $namer->maybeName($threadId, $uid);
        if ($title !== NULL) {
          echo ChatEvent::threadTitle($threadId, $title)->toSseFrame();
          flush();
        }
      }
      finally {
        $relay->disarm();
      }
    });

    $this->applyStreamHeaders($response);
    return $response;
  }

  /**
   * Assemble the brand console's per-turn template variable for the brand agent.
   *
   * Maps to the `variables` workflow input → the system-prompt PromptTemplate
   * node's `live_preview_state` Twig var: the unsaved token overrides + staged
   * fonts the user is currently previewing. NULL when there's no draft (a
   * non-brand turn, or the saved brand with no edits → no injection).
   *
   * @param mixed $brandContext
   *   The decoded `brand_context` payload, or NULL.
   *
   * @return array<string, string>|null
   *   The template variables, or NULL when there's nothing to inject.
   */
  private function brandVariables(mixed $brandContext): ?array {
    $draft = $this->brandContext($brandContext);
    return $draft === NULL ? NULL : ['live_preview_state' => $draft];
  }

  /**
   * Compact the brand console's live preview draft into a string for the agent.
   *
   * Carries `{overrides: {css_var: value}, fonts: [name]}`. We render it as
   * readable lines (token = value) rather than raw JSON so the model parses it
   * easily. Returns NULL when there's nothing to send (no draft → no injection).
   *
   * @param mixed $brandContext
   *   The decoded `brand_context` payload, or NULL.
   *
   * @return string|null
   *   The compacted context, or NULL when empty/malformed.
   */
  private function brandContext(mixed $brandContext): ?string {
    if (!is_array($brandContext)) {
      return NULL;
    }
    $lines = [];
    $overrides = $brandContext['overrides'] ?? NULL;
    if (is_array($overrides)) {
      foreach ($overrides as $cssVar => $value) {
        if (is_string($value) && trim($value) !== '') {
          $lines[] = '- ' . (string) $cssVar . ' = ' . trim($value);
        }
      }
    }
    $fonts = $brandContext['fonts'] ?? NULL;
    if (is_array($fonts) && $fonts !== []) {
      $names = array_filter(array_map(static fn($f) => is_string($f) ? trim($f) : '', $fonts));
      if ($names !== []) {
        $lines[] = '- web fonts loaded: ' . implode(', ', $names);
      }
    }
    return $lines === [] ? NULL : implode("\n", $lines);
  }

  /**
   * Compact the page studio's unsaved page-schema draft for the agent.
   *
   * Carries `{schema: {…page-schema…}}` — the draft the user is currently
   * previewing in the studio. Unlike the brand draft, this rides as the raw
   * page-schema JSON: the Reason node shows it to the agent as the LIVE PAGE
   * STATE to build on, AND the `preview_page` tool decodes the same value to
   * apply the agent's ops against it (one value, two consumers). Returns NULL
   * when there's no draft (a fresh page → no injection).
   *
   * @param mixed $pageContext
   *   The decoded `page_context` payload, or NULL.
   *
   * @return string|null
   *   The page-schema JSON, or NULL when empty/malformed.
   */
  /**
   * Assemble the page studio's per-turn template variables for the page agent.
   *
   * Maps to the `variables` workflow input → the system-prompt PromptTemplate
   * node: `live_page_state` (the page-schema JSON the user is previewing) and,
   * when editing a translation, `translation_directive` (translate-into-this-
   * language guidance). NULL when there's no page draft (a fresh page / a
   * non-page turn → no injection).
   *
   * @param mixed $pageContext
   *   The decoded `page_context` payload, or NULL.
   *
   * @return array<string, string>|null
   *   The template variables, or NULL when there's nothing to inject.
   */
  private function pageVariables(mixed $pageContext): ?array {
    $state = $this->pageContext($pageContext);
    if ($state === NULL) {
      return NULL;
    }
    $vars = ['live_page_state' => $state];
    $directive = $this->pageLangContext($pageContext);
    if ($directive !== NULL) {
      $vars['translation_directive'] = $directive;
    }
    return $vars;
  }

  private function pageContext(mixed $pageContext): ?string {
    if (!is_array($pageContext)) {
      return NULL;
    }
    $schema = $pageContext['schema'] ?? NULL;
    if (!is_array($schema) || $schema === []) {
      return NULL;
    }
    $json = json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return is_string($json) ? $json : NULL;
  }

  /**
   * The translation directive for the page agent, when the studio is editing a
   * non-source language.
   *
   * The page-schema in `page_draft` resolves to the SOURCE copy as a per-slot
   * fallback for untranslated text, so the agent sees what to translate FROM.
   * This tells it what to translate INTO (the active langcode) and that a
   * symmetric translation's layout is inherited + locked — so it only rewrites
   * the words, never the structure. Returns NULL for the source/default
   * language (no `langcode`) — a monolingual edit needs no directive.
   *
   * @param mixed $pageContext
   *   The decoded `page_context` payload, or NULL.
   *
   * @return string|null
   *   The directive, or NULL when editing the source language.
   */
  private function pageLangContext(mixed $pageContext): ?string {
    if (!is_array($pageContext)) {
      return NULL;
    }
    $langcode = trim((string) ($pageContext['langcode'] ?? ''));
    if ($langcode === '') {
      return NULL;
    }
    $manager = $this->languageManager();
    $target = $manager->getLanguage($langcode);
    if (!$target) {
      return NULL;
    }
    $source = $manager->getDefaultLanguage();
    $mode = trim((string) ($pageContext['mode'] ?? '')) ?: 'symmetric';

    $directive = sprintf(
      "TRANSLATING — the studio is editing the %s (%s) TRANSLATION of this page (layout mode: %s). "
      . "The copy in LIVE PAGE STATE is shown in the source language (%s) as a fallback: translate that "
      . "text INTO %s with update_section ops (and set_meta for the page title), one op per section, "
      . "reusing each section's `id`. Keep every non-text prop verbatim — media:/entity:/block: tokens, "
      . "image/avatar/cover props, and variant/tone/columns.",
      $target->getName(),
      $langcode,
      $mode,
      $source->getName(),
      $target->getName(),
    );
    if ($mode !== 'asymmetric') {
      $directive .= " The layout is INHERITED and LOCKED in symmetric mode: never add, remove, or reorder "
        . "sections — only translate the words. (If the user wants a different layout for this language, "
        . "tell them to click \"Make layout independent\" first.)";
    }
    return $directive;
  }

  /**
   * Assemble the Globals studio's per-turn template variable for the chrome agent.
   *
   * Maps to the `variables` workflow input → the system-prompt PromptTemplate
   * node's `live_chrome_state` Twig var: the unsaved identity + header/footer
   * layout the user is currently previewing. NULL when there's no draft (a
   * non-chrome turn → no injection).
   *
   * @param mixed $chromeContext
   *   The decoded `chrome_context` payload, or NULL.
   *
   * @return array<string, string>|null
   *   The template variables, or NULL when there's nothing to inject.
   */
  private function chromeVariables(mixed $chromeContext): ?array {
    $state = $this->chromeContext($chromeContext);
    return $state === NULL ? NULL : ['live_chrome_state' => $state];
  }

  /**
   * Seed `current_image` from the Media studio's open item token.
   *
   * The image agent's system prompt exposes this as the CURRENT IMAGE token so an
   * "edit this" turn can pass it to generate_image as `source` (image→image). Only
   * a well-formed `media:<id>` token passes through — anything else is dropped, so
   * a malformed value never lands in the prompt.
   *
   * @param mixed $mediaContext
   *   The `media_context` payload — expected to be a `media:<id>` token string.
   *
   * @return array{current_image: string}|null
   *   The variable, or NULL when there's no usable token.
   */
  private function mediaVariables(mixed $mediaContext): ?array {
    if (!is_string($mediaContext)) {
      return NULL;
    }
    $token = trim($mediaContext);
    return preg_match('/^media:\d+$/', $token) === 1 ? ['current_image' => $token] : NULL;
  }

  /**
   * Compact the Globals studio's live chrome draft into readable lines.
   *
   * Carries `{chrome: {header, footer}, identity: {guidelines, footer_note},
   * menus: {main, footer}}` — the draft the user is previewing. Rendered as
   * readable lines (not raw JSON) so the model parses it easily; menus are
   * read-only here (the agent can't edit them) but shown so it knows the nav.
   * Returns NULL when there's nothing to send.
   *
   * @param mixed $chromeContext
   *   The decoded `chrome_context` payload, or NULL.
   *
   * @return string|null
   *   The compacted context, or NULL when empty/malformed.
   */
  private function chromeContext(mixed $chromeContext): ?string {
    if (!is_array($chromeContext)) {
      return NULL;
    }
    $lines = [];

    $identity = $chromeContext['identity'] ?? NULL;
    if (is_array($identity)) {
      $guidelines = is_array($identity['guidelines'] ?? NULL) ? $identity['guidelines'] : [];
      foreach (['name', 'tagline', 'description', 'tone', 'imagery_style', 'imagery_avoid'] as $key) {
        $v = trim((string) ($guidelines[$key] ?? ''));
        if ($v !== '') {
          $lines[] = '- identity.' . $key . ' = ' . $v;
        }
      }
      $note = trim((string) ($identity['footer_note'] ?? ''));
      if ($note !== '') {
        $lines[] = '- identity.footer_note = ' . $note;
      }
    }

    $chrome = $chromeContext['chrome'] ?? NULL;
    if (is_array($chrome)) {
      foreach (['header', 'footer'] as $section) {
        $settings = is_array($chrome[$section] ?? NULL) ? $chrome[$section] : [];
        foreach ($settings as $key => $value) {
          $rendered = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
          $lines[] = '- layout.' . $section . '.' . (string) $key . ' = ' . $rendered;
        }
      }
    }

    $menus = $chromeContext['menus'] ?? NULL;
    if (is_array($menus)) {
      foreach (['main', 'footer'] as $menu) {
        $links = is_array($menus[$menu] ?? NULL) ? $menus[$menu] : [];
        $titles = [];
        foreach ($links as $link) {
          $title = is_array($link) ? trim((string) ($link['title'] ?? '')) : '';
          if ($title !== '') {
            $titles[] = $title;
          }
        }
        if ($titles !== []) {
          $lines[] = '- menu.' . $menu . ' (read-only) = ' . implode(', ', $titles);
        }
      }
    }

    return $lines === [] ? NULL : implode("\n", $lines);
  }

  /**
   * The site's persisted, already-known context for the page agent.
   *
   * Two template variables read straight from config (NOT a browser draft, so
   * they need no frontend round-trip): `site_identity` (the brand brief —
   * name/tagline/about/voice) and `site_nav` (the header + footer link
   * inventory). Injecting these means a content creator never re-states the
   * site name/voice or pastes links the site already has. Resolved LAZILY by
   * service id — aincient_chat does NOT depend on aincient_pages (same posture
   * as {@see \Drupal\aincient_chat\EventSubscriber\BrandSliceStreamSubscriber}),
   * so the feature simply no-ops if the pages module isn't installed. Only
   * non-empty vars are returned, so an unconfigured site injects nothing.
   *
   * @return array<string, string>
   *   The template variables (possibly empty).
   */
  private function siteContextVariables(): array {
    $vars = [];

    if (\Drupal::hasService('aincient_pages.site_identity')) {
      $brief = trim((string) \Drupal::service('aincient_pages.site_identity')->promptBrief());
      // Fold the Foundations VISUAL brief (lead palette, corner language, border
      // weight) into the same block, so the SITE BRAND section's "match its
      // palette" promise is real — the image agent gets concrete colours + shape,
      // and the page agent a fuller picture. Read lazily by service id (same
      // posture as the identity brief); no-ops if the pages module is absent.
      if (\Drupal::hasService('aincient_pages.brand')) {
        $visual = trim((string) \Drupal::service('aincient_pages.brand')->visualBrief());
        if ($visual !== '') {
          $brief = $brief === '' ? $visual : $brief . "\n" . $visual;
        }
      }
      if ($brief !== '') {
        $vars['site_identity'] = $brief;
      }
    }

    if (\Drupal::hasService('aincient_pages.chrome')) {
      $chrome = \Drupal::service('aincient_pages.chrome');
      $lines = [];
      foreach (['main' => 'Header', 'footer' => 'Footer'] as $menu => $label) {
        $links = $this->renderNavLinks($chrome->nav($menu));
        if ($links !== []) {
          $lines[] = $label . ' nav:';
          $lines = array_merge($lines, $links);
        }
      }
      if ($lines !== []) {
        $vars['site_nav'] = implode("\n", $lines);
      }
    }

    return $vars;
  }

  /**
   * Flatten a {@see \Drupal\aincient_pages\SiteChrome::nav()} tree into readable
   * `- Label → /url` lines, indented by nesting depth.
   *
   * @param array<mixed> $links
   *   The nested [{label, url, below}] link tree.
   * @param int $depth
   *   The current nesting depth (drives indentation).
   *
   * @return string[]
   *   One line per link, deepest last.
   */
  private function renderNavLinks(array $links, int $depth = 0): array {
    $out = [];
    foreach ($links as $link) {
      if (!is_array($link)) {
        continue;
      }
      $label = trim((string) ($link['label'] ?? ''));
      if ($label === '') {
        continue;
      }
      $url = trim((string) ($link['url'] ?? ''));
      $out[] = str_repeat('  ', $depth + 1) . '- ' . $label . ($url !== '' ? ' → ' . $url : '');
      $below = is_array($link['below'] ?? NULL) ? $link['below'] : [];
      if ($below !== []) {
        $out = array_merge($out, $this->renderNavLinks($below, $depth + 1));
      }
    }
    return $out;
  }

  /**
   * POST /aincient/chat/interrupt/{uuid} — answer a paused HITL turn.
   *
   * Body: {"response": <choice>, "thread_id": <thread>}. Streams the resumed
   * flow's continuation (a further interrupt, or the final result) as SSE.
   */
  public function resolveInterrupt(string $uuid, Request $request): StreamedResponse {
    $payload = json_decode($request->getContent() ?: '{}', TRUE) ?: [];
    $answer = $payload['response'] ?? NULL;
    $threadId = (string) ($payload['thread_id'] ?? '');

    $processor = $this->processor;
    $relay = $this->streamRelay;
    $response = new StreamedResponse(static function () use ($processor, $relay, $uuid, $answer, $threadId): void {
      while (ob_get_level() > 0) {
        ob_end_flush();
      }
      if ($answer === NULL || $answer === '' || $answer === []) {
        echo ChatEvent::error('No answer provided.')->toSseFrame();
        echo ChatEvent::done(['thread_id' => $threadId])->toSseFrame();
        flush();
        return;
      }
      // Same side channel as chat(): the resumed workflow's node events
      // stream live while the resolve call blocks.
      $relay->arm(static function (ChatEvent $event): void {
        echo $event->toSseFrame();
        flush();
      });
      try {
        foreach ($processor->resumeInterrupt($uuid, $answer, $threadId) as $event) {
          echo $event->toSseFrame();
          flush();
        }
      }
      finally {
        $relay->disarm();
      }
    });

    $this->applyStreamHeaders($response);
    return $response;
  }

  /**
   * Whether the console should force the first-run onboarding flow.
   *
   * True only on a genuinely unconfigured site: onboarding never completed AND
   * no default chat provider pinned. Cheap, core-only check (no AI / module dep)
   * and provider-neutral — it reads `ai.settings`, never a vendor-specific key.
   */
  private function needsOnboarding(): bool {
    if ((bool) $this->state->get(self::ONBOARDING_COMPLETED, FALSE)) {
      return FALSE;
    }
    $default = $this->config('ai.settings')->get('default_providers.chat');
    return empty($default['provider_id']);
  }

  /**
   * Apply the Server-Sent Events response headers.
   */
  private function applyStreamHeaders(StreamedResponse $response): void {
    $response->headers->set('Content-Type', 'text/event-stream');
    $response->headers->set('Cache-Control', 'no-cache, must-revalidate');
    $response->headers->set('X-Accel-Buffering', 'no');
    $response->headers->set('Connection', 'keep-alive');
  }

  /**
   * GET /aincient/chat/threads — the current user's conversations (newest first).
   *
   * Lightweight list for the console sidebar: one row per thread with a title
   * derived from the first user message and the last-activity timestamp. Message
   * bodies are NOT included — the UI lazy-loads a thread's turns via
   * threadEvents() only when the user opens it. Threads are FlowDrop sessions
   * (single lane); see {@see SessionThreadStore}.
   */
  public function threads(): JsonResponse {
    $threads = $this->threadStore->listThreads((int) $this->currentUser()->id());
    return new JsonResponse(['threads' => $threads]);
  }

  /**
   * GET /aincient/chat/thread/{thread_id}/events — persisted turns for a thread.
   *
   * Returns the thread's turns read from its FlowDrop session, scoped to the
   * current user so a thread id can't be used to read another account's
   * conversation. A still-pending interrupt is surfaced so the console can
   * rebuild the choice widget on reload.
   *
   * Windowed: `?limit=N` returns only the NEWEST N turns (the console opens a
   * thread on its tail and lazy-loads history); `?before=<turn id>` anchors
   * the window on the turns OLDER than that id (the next page up when the
   * user scrolls to the top). `has_more` says whether older turns remain.
   * The slice happens after assembly — query-level windowing is a later
   * optimisation; the win here is payload + DOM, not entity loads.
   */
  public function threadEvents(string $thread_id, Request $request): JsonResponse {
    $turns = $this->threadStore->threadTurns($thread_id, (int) $this->currentUser()->id());

    $before = (string) $request->query->get('before', '');
    if ($before !== '') {
      foreach ($turns as $i => $turn) {
        if ((string) $turn['id'] === $before) {
          $turns = array_slice($turns, 0, $i);
          break;
        }
      }
    }

    $limit = (int) $request->query->get('limit', 0);
    $hasMore = FALSE;
    if ($limit > 0 && count($turns) > $limit) {
      $hasMore = TRUE;
      $turns = array_slice($turns, -$limit);
    }

    return new JsonResponse([
      'thread_id' => $thread_id,
      'turns' => array_values($turns),
      'has_more' => $hasMore,
    ]);
  }

  /**
   * POST /aincient/chat/thread/{thread_id}/archive — (un)archive a thread.
   *
   * Body: {"archived": true|false} (defaults to true). Flips the flag on every
   * turn of the thread, scoped to the current user.
   */
  public function archiveThread(string $thread_id, Request $request): JsonResponse {
    $payload = json_decode($request->getContent() ?: '{}', TRUE) ?: [];
    $archived = (bool) ($payload['archived'] ?? TRUE);

    $touched = $this->threadStore->archive($thread_id, (int) $this->currentUser()->id(), $archived);

    return new JsonResponse(['thread_id' => $thread_id, 'archived' => $archived, 'sessions' => $touched]);
  }

  /**
   * POST /aincient/chat/thread/{thread_id}/lock — (un)lock (wrap up) a thread.
   *
   * Body: {"locked": true|false (default true), "page_url"?, "page_node"?}.
   * Locking wraps the thread read-only (still listed) and remembers the
   * published page for the celebration pane; unlocking reopens it. This is the
   * milestone (publish) face of the one seal primitive — {@see clearThread} is
   * the plain-clear face. Returns the thread's resource homing so a fresh thread
   * on the same resource re-homes to it. Scoped to the current user.
   */
  public function lockThread(string $thread_id, Request $request): JsonResponse {
    $payload = json_decode($request->getContent() ?: '{}', TRUE) ?: [];
    $locked = (bool) ($payload['locked'] ?? TRUE);
    $uid = (int) $this->currentUser()->id();

    $published = NULL;
    if ($locked) {
      $ref = array_filter([
        'url' => trim((string) ($payload['page_url'] ?? '')),
        'node' => trim((string) ($payload['page_node'] ?? '')),
      ], static fn(string $v): bool => $v !== '');
      $published = $ref !== [] ? $ref : NULL;
    }

    $touched = $this->threadStore->lock($thread_id, $uid, $locked, $published);

    return new JsonResponse([
      'thread_id' => $thread_id,
      'locked' => $locked,
      'sessions' => $touched,
      'workingNode' => $this->threadStore->workingNode($thread_id, $uid),
    ]);
  }

  /**
   * POST /aincient/chat/thread/{thread_id}/clear — seal for a fresh start.
   *
   * The plain-clear face of the one seal primitive (studio-navigation.md §4):
   * it wraps the current thread read-only (no publish milestone) and returns the
   * resource it was homed to, so the console can open a fresh buffer on the same
   * Node(nid, lang) — "seal current thread + open fresh buffer on same
   * resource." The actual fresh thread id is minted client-side; the server just
   * seals and hands back the homing. Scoped to the current user.
   */
  public function clearThread(string $thread_id): JsonResponse {
    $uid = (int) $this->currentUser()->id();
    $touched = $this->threadStore->lock($thread_id, $uid, TRUE);

    return new JsonResponse([
      'thread_id' => $thread_id,
      'sealed' => (bool) $touched,
      'workingNode' => $this->threadStore->workingNode($thread_id, $uid),
    ]);
  }

  /**
   * DELETE /aincient/chat/thread/{thread_id} — delete a thread's session.
   */
  public function deleteThread(string $thread_id): JsonResponse {
    $deleted = $this->threadStore->delete($thread_id, (int) $this->currentUser()->id());

    return new JsonResponse(['thread_id' => $thread_id, 'deleted' => $deleted]);
  }

}
