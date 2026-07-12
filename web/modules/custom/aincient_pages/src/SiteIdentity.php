<?php

declare(strict_types=1);

namespace Drupal\aincient_pages;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;

/**
 * The site's BRAND IDENTITY — name, tagline, voice, logo, footer note.
 *
 * This is the "Brand" layer of the studio taxonomy (a Globals item): the
 * concrete, site-wide content that says who the site IS. It is deliberately
 * distinct from the design-token layer (Foundations / {@see BrandRepository}),
 * which holds the abstract visual language. Identity is the single source of
 * truth consumed by the site header/footer ({@see SiteChrome}) and the page
 * agent's brief — never duplicated.
 *
 * The header/footer NAV does NOT live here; it comes from Drupal's core
 * `main`/`footer` menus (see {@see SiteChrome::nav()}).
 */
final class SiteIdentity {

  public const CONFIG = 'aincient_pages.identity';

  public const GUIDELINE_KEYS = ['name', 'tagline', 'description', 'tone', 'imagery_style', 'imagery_avoid'];

  /**
   * Image style for rendering the logo at display size. Core's `large` is a
   * no-crop `image_scale` to 480×480 (no upscale) → any logo aspect ratio is
   * preserved, and even a huge upload decodes as a ≤480px webp rather than its
   * full-resolution original (which, rendered in the rail + both preview
   * iframes at once, stalled the tab on big files).
   */
  public const LOGO_STYLE = 'large';

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
    private readonly EntityEmbedResolver $embed,
  ) {}

  /** The brand-voice guidelines map (name/tagline/description/tone + imagery_style/imagery_avoid). */
  public function guidelines(): array {
    return $this->configFactory->get(self::CONFIG)->get('guidelines') ?: [];
  }

  public function name(): string {
    return (string) ($this->guidelines()['name'] ?? '');
  }

  public function tagline(): string {
    return (string) ($this->guidelines()['tagline'] ?? '');
  }

  public function footerNote(): string {
    return (string) ($this->configFactory->get(self::CONFIG)->get('footer_note') ?? '');
  }

  /** The brand logo as a `media:<id>` token (or '' when none is set). */
  public function logo(): string {
    return (string) ($this->configFactory->get(self::CONFIG)->get('logo') ?? '');
  }

  /** Public URL of the brand logo at display size, or '' if none. */
  public function logoUrl(): string {
    return $this->logoUrlForToken($this->logo());
  }

  /**
   * Root-relative URL of a `media:<id>` logo token at display size: a small
   * {@see self::LOGO_STYLE} derivative for raster formats, the original for
   * vector/unsupported sources the image toolkit can't process (SVG). '' for an
   * empty / dangling token. Shared with the studio preview so a freshly-picked
   * logo renders from the same cheap derivative as the saved one.
   */
  public function logoUrlForToken(string $token): string {
    $token = trim($token);
    if ($token === '') {
      return '';
    }
    return $this->embed->url($token, self::LOGO_STYLE) ?? '';
  }

  /** A compact brand brief for the AI's page-designer prompt (or ''). */
  public function promptBrief(): string {
    $g = $this->guidelines();
    $lines = [];
    foreach ([
      'name' => 'Brand',
      'tagline' => 'Tagline',
      'description' => 'About',
      'tone' => 'Tone of voice',
      // Imagery direction — the art-direction the image agent should follow when
      // generating/selecting pictures (and the page agent when it picks imagery).
      'imagery_style' => 'Imagery style',
      'imagery_avoid' => 'Imagery to avoid',
    ] as $k => $label) {
      $v = trim((string) ($g[$k] ?? ''));
      if ($v !== '') {
        $lines[] = "$label: $v";
      }
    }
    return $lines ? implode("\n", $lines) : '';
  }

  /**
   * Merge + persist identity changes. Guideline keys are whitelisted; the footer
   * note is set only when provided. Returns a human-readable list of what changed.
   *
   * @return string[]
   */
  public function update(array $guidelines = [], ?string $footerNote = NULL): array {
    $config = $this->configFactory->getEditable(self::CONFIG);
    $applied = [];

    $g = $config->get('guidelines') ?: [];
    foreach ($guidelines as $key => $value) {
      if (in_array($key, self::GUIDELINE_KEYS, TRUE) && is_string($value)) {
        $g[$key] = trim($value);
        $applied[] = $key;
      }
    }
    $config->set('guidelines', $g);

    if ($footerNote !== NULL) {
      $config->set('footer_note', trim($footerNote));
      $applied[] = 'footer note';
    }

    $config->save();
    return $applied;
  }

  /** Persist the brand logo as a `media:<id>` token ('' clears it). */
  public function setLogo(string $token): void {
    $this->configFactory->getEditable(self::CONFIG)->set('logo', trim($token))->save();
  }

  /** The favicon as a `media:<id>` token (or '' when none is set). */
  public function favicon(): string {
    return (string) ($this->configFactory->get(self::CONFIG)->get('favicon') ?? '');
  }

  /**
   * The favicon as an `html_head_link` value (`href` + `type`), or NULL when none
   * is set. Unlike the logo the favicon is served RAW — no image style: browsers
   * want the small icon exactly as uploaded. The MIME comes off the source file so
   * the `<link>` carries the right `type`.
   */
  public function faviconLink(): ?array {
    $file = $this->embed->mediaFile($this->favicon());
    if ($file === NULL) {
      return NULL;
    }
    return [
      'href' => $this->fileUrlGenerator->generateString($file->getFileUri()),
      'type' => $file->getMimeType() ?: 'image/png',
    ];
  }

  /** Public URL of the favicon (the raw source file), or '' if none. */
  public function faviconUrl(): string {
    $link = $this->faviconLink();
    return $link['href'] ?? '';
  }

  /** Persist the favicon as a `media:<id>` token ('' clears it). */
  public function setFavicon(string $token): void {
    $this->configFactory->getEditable(self::CONFIG)->set('favicon', trim($token))->save();
  }

}
