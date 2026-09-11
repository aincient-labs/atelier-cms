<?php

declare(strict_types=1);

namespace Drupal\aincient_export\PathProcessor;

use Drupal\Core\PathProcessor\InboundPathProcessorInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Folds `/atelier/snapshots/<id>/<file path>` into the snapshot-file route.
 *
 * Drupal's router filters candidate routes by segment count, so a `{path}`
 * slug with a `.*` requirement only ever matches ONE extra segment:
 * `<id>/robots.txt` reaches the controller, `<id>/sites/default/files/x.css`
 * never does. Core solves the same problem for `/system/files/…` with
 * PathProcessorFiles: rewrite the inbound path to the route's fixed shape and
 * carry the remainder in a query parameter. Same trick here.
 */
final class SnapshotPathProcessor implements InboundPathProcessorInterface {

  /**
   * Route prefix; aincient_export.routing.yml carries the same literal.
   */
  public const PREFIX = '/atelier/snapshots/';

  /**
   * Query key the controller reads the file path from.
   */
  public const QUERY_KEY = 'file';

  /**
   * {@inheritdoc}
   */
  public function processInbound($path, Request $request) {
    if (!str_starts_with($path, self::PREFIX) || $request->query->has(self::QUERY_KEY)) {
      return $path;
    }
    if (preg_match('@^' . preg_quote(self::PREFIX, '@') . '([0-9]{8}-[0-9]{4}(?:-[a-z0-9-]+)?)/(.+)$@', $path, $m)) {
      $request->query->set(self::QUERY_KEY, $m[2]);
      return self::PREFIX . $m[1];
    }
    return $path;
  }

}
