<?php

declare(strict_types=1);

namespace Drupal\aincient_pages\Config;

use Drupal\aincient_pages\SiteIdentity;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryOverrideInterface;
use Drupal\Core\Config\StorageInterface;

/**
 * Layers the brand identity over core's `system.site` at config-read time.
 *
 * Identity ({@see SiteIdentity}, config-ignored, studio-owned) is the ONLY
 * place the operator's site information lives — `system.site` itself stays a
 * plain shipped file in config/sync that upgrades converge freely, and is
 * never written at runtime. This override is what makes the two meet: every
 * consumer of `system.site` (mails, tokens, the front-page matcher, feeds)
 * sees the identity values, while `drush cex` / `cim` see only the pristine
 * shipped defaults (overrides are read-time, never exported).
 *
 * Only NON-EMPTY identity values override — an empty studio field means "no
 * opinion" and the shipped default shows through. Mapped keys:
 *   - `name`   ← guidelines.name, `slogan` ← guidelines.tagline
 *   - `mail`   ← site.mail
 *   - `page.front|403|404` ← site.front|page_403|page_404 (`entity:node:<id>`
 *     tokens → `/node/<id>` internal paths; see SiteIdentity::tokenToPath()).
 *
 * Note the core caveat this design accepts: the raw admin form
 * (/admin/config/system/site-information) shows UNOVERRIDDEN values, so edits
 * there are shadowed — aincient_pages_form_system_site_information_settings_alter()
 * says so on the form. Debug effective values with
 * `drush cget system.site --include-overridden`.
 */
final class SiteInformationOverrider implements ConfigFactoryOverrideInterface {

  /**
   * Identity is read through the RAW config storage (cheap: CachedStorage is
   * memory-backed per request), never the config factory — loading a config
   * from inside an override re-enters the factory's override chain.
   */
  public function __construct(
    private readonly StorageInterface $baseStorage,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function loadOverrides($names): array {
    if (!in_array('system.site', $names, TRUE)) {
      return [];
    }
    $identity = $this->baseStorage->read(SiteIdentity::CONFIG);
    if (!is_array($identity)) {
      return [];
    }

    $overrides = [];
    $name = trim((string) ($identity['guidelines']['name'] ?? ''));
    if ($name !== '') {
      $overrides['name'] = $name;
    }
    $tagline = trim((string) ($identity['guidelines']['tagline'] ?? ''));
    if ($tagline !== '') {
      $overrides['slogan'] = $tagline;
    }

    $site = is_array($identity['site'] ?? NULL) ? $identity['site'] : [];
    $mail = trim((string) ($site['mail'] ?? ''));
    if ($mail !== '') {
      $overrides['mail'] = $mail;
    }
    foreach (['front' => 'front', 'page_403' => '403', 'page_404' => '404'] as $key => $slot) {
      $path = SiteIdentity::tokenToPath((string) ($site[$key] ?? ''));
      if ($path !== '') {
        $overrides['page'][$slot] = $path;
      }
    }

    return $overrides === [] ? [] : ['system.site' => $overrides];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheSuffix(): string {
    return 'aincient_site_information';
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheableMetadata($name): CacheableMetadata {
    $metadata = new CacheableMetadata();
    if ($name === 'system.site') {
      // Anything cached against the overridden system.site must invalidate
      // when the identity it mirrors is (re)published in the studio.
      $metadata->addCacheTags(['config:' . SiteIdentity::CONFIG]);
    }
    return $metadata;
  }

  /**
   * {@inheritdoc}
   */
  public function createConfigObject($name, $collection = StorageInterface::DEFAULT_COLLECTION) {
    return NULL;
  }

}
