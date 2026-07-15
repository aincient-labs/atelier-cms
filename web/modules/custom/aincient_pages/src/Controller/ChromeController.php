<?php

declare(strict_types=1);

namespace Drupal\aincient_pages\Controller;

use Drupal\aincient_pages\BrandRepository;
use Drupal\aincient_pages\ChromeRepository;
use Drupal\aincient_pages\ConsentSettings;
use Drupal\aincient_pages\EntityEmbedResolver;
use Drupal\aincient_pages\MenuRepository;
use Drupal\aincient_pages\SiteChrome;
use Drupal\aincient_pages\SiteIdentity;
use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * JSON/HTML API for the Globals studios (Header / Footer / Brand identity).
 *
 * The chrome parallel of {@see BrandController} / {@see PageController}: the
 * studio edits a preview-only DRAFT (chrome layout + identity + the two chrome
 * menus) client-side, renders it live through {@see preview()}, and persists it
 * only on Publish ({@see save()}). Three stores back it: {@see ChromeRepository}
 * (layout variants), {@see SiteIdentity} (name/tagline/logo/footer note), and
 * {@see MenuRepository} (the editable `main`/`footer` links). Gated like the
 * other console endpoints (`administer aincient pages`).
 */
final class ChromeController implements ContainerInjectionInterface {

  /** Human labels for the chrome layout settings (the rail renders these). */
  private const LABELS = [
    'header' => [
      'logo_position' => 'Logo position',
      'sticky' => 'Stick to top on scroll',
      'nav_alignment' => 'Nav alignment',
    ],
    'footer' => [
      'layout' => 'Footer layout',
      'show_tagline' => 'Show tagline',
      'show_credit' => 'Show "Made with Atelier" credit',
    ],
  ];

  public function __construct(
    private readonly ChromeRepository $chrome,
    private readonly SiteIdentity $identity,
    private readonly MenuRepository $menus,
    private readonly SiteChrome $siteChrome,
    private readonly BrandRepository $brand,
    private readonly ConsentSettings $consent,
    private readonly EntityEmbedResolver $embed,
    private readonly ClassResolverInterface $classResolver,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('aincient_pages.chrome_repository'),
      $container->get('aincient_pages.site_identity'),
      $container->get('aincient_pages.menu_repository'),
      $container->get('aincient_pages.chrome'),
      $container->get('aincient_pages.brand'),
      $container->get('aincient_pages.consent'),
      $container->get('aincient_pages.embed_resolver'),
      $container->get('class_resolver'),
    );
  }

  /**
   * GET /atelier/chrome/manifest — the current chrome state + the editing vocab.
   *
   * The single source the Globals studio renders from: current layout settings,
   * the enum/bool options per setting (so the rail builds selects/toggles), the
   * identity fields (with the resolved logo URL), and the two editable menus.
   */
  public function manifest(): JsonResponse {
    return new JsonResponse([
      'chrome' => $this->chrome->all(),
      'registry' => $this->registry(),
      'identity' => $this->identityState(),
      'privacy' => $this->privacyState(),
      'menus' => [
        'main' => $this->menus->tree('main'),
        'footer' => $this->menus->tree('footer'),
      ],
    ]);
  }

  /**
   * POST /atelier/chrome/preview — render the chrome around a placeholder body,
   * from the studio DRAFT, WITHOUT persisting.
   *
   * Body: `{ chrome?: {header,footer}, identity?: {…, logo (media token)}, menus?: {main,footer} }`.
   * Layout variants are markup/class changes (not pure CSS vars), so unlike the
   * brand preview this renders server-side with the draft props and returns the
   * full HTML document the studio iframe shows via `srcdoc`.
   */
  public function preview(Request $request): Response {
    $draft = json_decode((string) $request->getContent(), TRUE);
    $draft = is_array($draft) ? $draft : [];
    $layout = $this->chrome->applyDraft(is_array($draft['chrome'] ?? NULL) ? $draft['chrome'] : []);
    $identity = is_array($draft['identity'] ?? NULL) ? $draft['identity'] : [];
    $menus = is_array($draft['menus'] ?? NULL) ? $draft['menus'] : [];

    $name = trim((string) ($identity['guidelines']['name'] ?? $this->identity->name()));
    $tagline = trim((string) ($identity['guidelines']['tagline'] ?? $this->identity->tagline()));
    // The draft carries a `media:<id>` logo TOKEN (the unified picker's value);
    // resolve it to a display-size URL here, falling back to the saved logo when
    // the draft hasn't touched it.
    $logoUrl = array_key_exists('logo', $identity)
      ? $this->identity->logoUrlForToken((string) $identity['logo'])
      : $this->identity->logoUrl();
    $note = trim((string) ($identity['footer_note'] ?? $this->identity->footerNote()));
    if ($note === '') {
      $note = '© ' . date('Y') . ' ' . ($name !== '' ? $name : 'AIncient');
    }

    $headerProps = [
      'name' => $name,
      'logo_url' => $logoUrl,
      'nav' => $this->draftNav($menus['main'] ?? NULL, 'main'),
    ] + $layout['header'];
    $footerProps = [
      'name' => $name,
      'tagline' => $tagline,
      'logo_url' => $logoUrl,
      'nav' => $this->draftNav($menus['footer'] ?? NULL, 'footer'),
      'note' => $note,
      'credit' => SiteChrome::credit(),
    ] + $layout['footer'];

    return $this->spike()->renderChrome($headerProps, $footerProps);
  }

  /**
   * POST /atelier/chrome/save — publish the studio's working chrome draft.
   *
   * Body: `{ chrome?: {header,footer}, identity?: {guidelines, footer_note, logo,
   * favicon, site}, menus?: {main:[…], footer:[…]} }` — `logo`/`favicon` are
   * `media:<id>` tokens (or '' to clear); `site` is `{mail, front, page_403,
   * page_404}` where the page slots are `entity:node:<id>` tokens ('' = shipped
   * system.site default). Persists layout (ChromeRepository), identity + site
   * information (SiteIdentity) and reconciles the two menus (MenuRepository).
   * Returns the saved state so the studio re-seeds its baseline (incl.
   * server-assigned menu-link ids).
   */
  public function save(Request $request): JsonResponse {
    $data = json_decode((string) $request->getContent(), TRUE);
    if (!is_array($data)) {
      return new JsonResponse(['error' => 'Expected a JSON object.'], 400);
    }

    $applied = [];
    if (is_array($data['chrome'] ?? NULL)) {
      $applied = array_merge($applied, $this->chrome->update($data['chrome']));
    }

    if (is_array($data['identity'] ?? NULL)) {
      $identity = $data['identity'];
      $guidelines = is_array($identity['guidelines'] ?? NULL) ? $identity['guidelines'] : [];
      $footerNote = array_key_exists('footer_note', $identity) ? (string) $identity['footer_note'] : NULL;
      $applied = array_merge($applied, $this->identity->update($guidelines, $footerNote));
      // Logo/favicon are `media:<id>` TOKENS now (the unified Library picker):
      // an empty string clears; a token points at a Library media entity, which
      // already owns the file's persistence — no upload-staging dance needed.
      if (array_key_exists('logo', $identity)) {
        $this->identity->setLogo((string) $identity['logo']);
        $applied[] = 'logo';
      }
      if (array_key_exists('favicon', $identity)) {
        $this->identity->setFavicon((string) $identity['favicon']);
        $applied[] = 'favicon';
      }
      // Site information (mail + the front/403/404 page slots): stored on the
      // identity, layered over core's system.site at read time by
      // SiteInformationOverrider — system.site itself is never written. The
      // page slots are `entity:node:<id>` tokens ('' = shipped default).
      if (is_array($identity['site'] ?? NULL)) {
        $applied = array_merge($applied, $this->identity->updateSite($identity['site']));
      }
    }

    if (is_array($data['menus'] ?? NULL)) {
      foreach (['main', 'footer'] as $menu) {
        if (is_array($data['menus'][$menu] ?? NULL)) {
          $this->menus->sync($menu, $data['menus'][$menu]);
          $applied[] = $menu . ' menu';
        }
      }
    }

    // Privacy: the font-delivery mode is the one lever that turns the public
    // consent banner on/off. It lives in the Foundations (brand) config, so we
    // write it through BrandRepository — saving fires BrandConfigSubscriber,
    // which vendors the fonts locally when delivery flips to self-host.
    if (is_array($data['privacy'] ?? NULL) && array_key_exists('font_delivery', $data['privacy'])) {
      $delivery = (string) $data['privacy']['font_delivery'];
      $applied = array_merge($applied, $this->brand->update([], NULL, $delivery));
    }

    return new JsonResponse([
      'applied' => $applied,
      'chrome' => $this->chrome->all(),
      'identity' => $this->identityState(),
      'privacy' => $this->privacyState(),
      'menus' => [
        'main' => $this->menus->tree('main'),
        'footer' => $this->menus->tree('footer'),
      ],
    ]);
  }

  /**
   * The identity fields the studio edits.
   *
   * Logo + favicon are `media:<id>` TOKENS now (the unified Library picker's
   * value) — the studio's ReferenceField resolves each token to its own preview,
   * so no server-resolved URL is needed here.
   */
  private function identityState(): array {
    $g = $this->identity->guidelines();
    return [
      'guidelines' => [
        'name' => (string) ($g['name'] ?? ''),
        'tagline' => (string) ($g['tagline'] ?? ''),
        'description' => (string) ($g['description'] ?? ''),
        'tone' => (string) ($g['tone'] ?? ''),
        'imagery_style' => (string) ($g['imagery_style'] ?? ''),
        'imagery_avoid' => (string) ($g['imagery_avoid'] ?? ''),
      ],
      'footer_note' => $this->identity->footerNote(),
      'logo' => $this->identity->logo(),
      'favicon' => $this->identity->favicon(),
      'site' => $this->identity->site(),
    ];
  }

  /**
   * The privacy state the studio's Privacy tab renders from.
   *
   * The font-delivery mode is the one operator-set lever that turns the public
   * GDPR consent banner on (Google) or off (self-host). `banner_active` mirrors
   * {@see ConsentSettings::isActive()} so the rail can show the live consequence
   * of the current choice (a banner appears iff a non-essential category is on).
   */
  private function privacyState(): array {
    return [
      'font_delivery' => $this->brand->fontDelivery(),
      'options' => [
        BrandRepository::DELIVERY_SELFHOST,
        BrandRepository::DELIVERY_GOOGLE,
      ],
      'banner_active' => $this->consent->isActive(),
    ];
  }

  /**
   * The chrome layout vocabulary as a frontend manifest: per section, a list of
   * settings with type (enum|bool), allowed values + default + label.
   */
  private function registry(): array {
    $out = [];
    foreach (ChromeRepository::REGISTRY as $section => $settings) {
      $rows = [];
      foreach ($settings as $key => $def) {
        $rows[] = [
          'key' => $key,
          'label' => self::LABELS[$section][$key] ?? $key,
          'type' => isset($def['enum']) ? 'enum' : 'bool',
          'enum' => $def['enum'] ?? NULL,
          'default' => $def['default'],
        ];
      }
      $out[$section] = $rows;
    }
    return $out;
  }

  /**
   * A draft menu's links as the SDC nav shape `[{label, url, below}]` (enabled
   * only, nested recursively), or the saved live nav when the draft didn't touch
   * this menu.
   */
  private function draftNav(mixed $draft, string $menu): array {
    if (!is_array($draft)) {
      // Reuse the saved live nav (already resolved to {label, url, below}).
      return $this->siteChrome->nav($menu);
    }
    $nav = [];
    foreach ($draft as $link) {
      if (!is_array($link)) {
        continue;
      }
      if (isset($link['enabled']) && !$link['enabled']) {
        continue;
      }
      $title = trim((string) ($link['title'] ?? ''));
      if ($title === '') {
        continue;
      }
      $children = is_array($link['children'] ?? NULL) ? $link['children'] : [];
      $nav[] = [
        'label' => $title,
        'url' => $this->navUrl((string) ($link['url'] ?? '')),
        'below' => $this->draftNav($children, $menu),
      ];
    }
    return $nav;
  }

  /**
   * A draft link's `url` as a browser-followable href for the preview iframe.
   *
   * A page-reference link carries a console TOKEN (`entity:node:5`) — resolve it
   * to the node's canonical URL so the previewed link points somewhere real
   * (mirrors what the live nav does via core's menu-link resolution). A dangling
   * / not-yet-picked reference falls back to `#`; a plain path/URL passes through.
   */
  private function navUrl(string $url): string {
    $url = trim($url);
    if ($url === '') {
      return '#';
    }
    if (EntityEmbedResolver::isWellFormed($url)) {
      return $this->embed->url($url) ?? '#';
    }
    return $url;
  }

  /** The page renderer, resolved lazily (it's a controller, not a service). */
  private function spike(): PageSpikeController {
    return $this->classResolver->getInstanceFromDefinition(PageSpikeController::class);
  }

}
