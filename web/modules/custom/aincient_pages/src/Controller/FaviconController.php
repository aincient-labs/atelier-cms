<?php

declare(strict_types=1);

namespace Drupal\aincient_pages\Controller;

use Drupal\aincient_pages\SiteIdentity;
use Drupal\Core\Cache\CacheableResponse;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\file\FileInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves the literal `GET /favicon.ico` browsers fire unconditionally.
 *
 * Without this route the request 404s (a console error on every page + a
 * Lighthouse Best Practices hit): the operator-uploaded favicon lives under
 * /sites/default/files/… and the no-upload case has no file at all. Serve the
 * operator's icon when one is set in the Globals studio, else the module's
 * bundled NEUTRAL default (images/favicon.ico — a generic dot-in-square mark,
 * NOT the Atelier brand mark: a client site must not silently wear our brand).
 *
 * CACHEABLE both ways (mirrors CollectionDataController): the response is a
 * CacheableResponse tagged with the identity config so internal page cache can
 * hold it and a favicon change invalidates it, plus an explicit long-lived
 * Cache-Control for the browser. While system.performance cache.page.max_age
 * is 0 (the current shipped config) core's FinishResponseSubscriber strips ANY
 * custom Cache-Control site-wide — the header takes effect the moment the
 * appliance cache-header work raises max_age, with no change here.
 *
 * NOTE the webserver seam: DDEV's nginx falls through to Drupal for a missing
 * .ico (`try_files $uri @rewrite`), but Drupal's stock .htaccess EXEMPTS
 * /favicon.ico from the index.php rewrite — the appliance Dockerfile drops that
 * exemption line so Apache reaches this route too.
 */
final class FaviconController implements ContainerInjectionInterface {

  /** Browser cache lifetime — a day: long enough to matter, short enough that an operator's new upload propagates. */
  private const MAX_AGE = 86400;

  public function __construct(
    private readonly SiteIdentity $identity,
    private readonly ModuleExtensionList $moduleList,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('aincient_pages.site_identity'),
      $container->get('extension.list.module'),
    );
  }

  /** GET /favicon.ico — the operator-uploaded favicon, or the bundled default. */
  public function icon(): CacheableResponse {
    $file = $this->identity->faviconFile();
    if ($file instanceof FileInterface) {
      $bytes = @file_get_contents($file->getFileUri());
      $type = $file->getMimeType() ?: 'image/png';
    }
    if (!isset($bytes) || $bytes === FALSE) {
      $default = $this->moduleList->getPath('aincient_pages') . '/images/favicon.ico';
      $bytes = @file_get_contents($default);
      $type = 'image/x-icon';
      if ($bytes === FALSE) {
        throw new NotFoundHttpException();
      }
    }

    $response = new CacheableResponse($bytes, 200, [
      'Content-Type' => $type,
      'Cache-Control' => 'public, max-age=' . self::MAX_AGE,
    ]);
    // Invalidate when the identity (favicon token) changes; vary nothing.
    $response->getCacheableMetadata()->addCacheTags(['config:' . SiteIdentity::CONFIG]);
    if (isset($file) && $file instanceof FileInterface) {
      $response->addCacheableDependency($file);
    }
    return $response;
  }

}
