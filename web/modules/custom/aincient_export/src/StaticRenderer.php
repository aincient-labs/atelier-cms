<?php

declare(strict_types=1);

namespace Drupal\aincient_export;

use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Replays a path through the HTTP kernel as the anonymous user.
 *
 * The request-stack swap follows Tome's StaticGenerator (GPL): the CLI
 * request Drush bootstrapped with is popped off, our synthetic request is
 * handled as a main request, and the original stack is restored — so path
 * processors, access checks, and cache contexts behave exactly as they would
 * for a real anonymous visitor.
 */
final class StaticRenderer {

  public function __construct(
    private readonly HttpKernelInterface $httpKernel,
    private readonly RequestStack $requestStack,
    private readonly AccountSwitcherInterface $accountSwitcher,
    private readonly ?object $pageCacheMiddleware = NULL,
  ) {}

  /**
   * Renders one path anonymously against the given base URL.
   */
  public function renderPath(string $path, string $baseUrl): RenderOutcome {
    $this->resetPageCache();
    $this->accountSwitcher->switchTo(new AnonymousUserSession());
    $request = Request::create(rtrim($baseUrl, '/') . $path);
    $previous_stack = [];
    while ($this->requestStack->getCurrentRequest()) {
      $previous_stack[] = $this->requestStack->pop();
    }
    $this->requestStack->push($request);

    try {
      $response = $this->httpKernel->handle($request, HttpKernelInterface::MAIN_REQUEST);
      // Fills in headers the web server normally finalizes — without this,
      // HTML page responses have NO Content-Type header at all.
      $response->prepare($request);
    }
    catch (\Exception $e) {
      return new RenderOutcome($path, 0, error: get_class($e) . ': ' . $e->getMessage());
    }
    finally {
      while ($this->requestStack->getCurrentRequest()) {
        $this->requestStack->pop();
      }
      foreach (array_reverse($previous_stack) as $stacked) {
        $this->requestStack->push($stacked);
      }
      $this->accountSwitcher->switchBack();
    }

    $content_type = (string) $response->headers->get('Content-Type', '');
    if ($response instanceof BinaryFileResponse) {
      return new RenderOutcome($path, $response->getStatusCode(), file: $response->getFile()->getPathname(), contentType: $content_type);
    }
    if ($response->isRedirection()) {
      return new RenderOutcome($path, $response->getStatusCode(), contentType: $content_type, redirectTarget: (string) $response->headers->get('Location', ''));
    }
    return new RenderOutcome($path, $response->getStatusCode(), content: (string) $response->getContent(), contentType: $content_type);
  }

  /**
   * Clears the page-cache middleware's per-process cache-ID memo.
   *
   * Core's PageCache computes its cache ID once per OBJECT lifetime — one
   * request per process on the web, but our process replays many. Left in
   * place, every replay after the first reads/writes the FIRST URL's cache
   * entry, poisoning the live page cache (observed: one 404 turned every
   * subsequent page into a cached 404). Core's default request policy denies
   * page caching under CLI, but tome_static (while it remains installed)
   * swaps that policy for one that allows it, so we must reset either way.
   */
  private function resetPageCache(): void {
    if ($this->pageCacheMiddleware === NULL) {
      return;
    }
    if (method_exists($this->pageCacheMiddleware, 'resetCache')) {
      $this->pageCacheMiddleware->resetCache();
      return;
    }
    // Core leaves $cid undeclared; unset() clears both the declared and the
    // dynamic-property case so getCacheId() recomputes on the next request.
    (function (): void {
      unset($this->cid);
    })->call($this->pageCacheMiddleware);
  }

}
