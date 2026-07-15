<?php

declare(strict_types=1);

namespace Drupal\aincient_export;

/**
 * The result of replaying one path through the kernel.
 */
final class RenderOutcome {

  /**
   * @param string $path
   *   The requested path.
   * @param int $status
   *   The HTTP status code (0 when the request threw).
   * @param string|null $content
   *   Response body for buffered responses, NULL for file responses/errors.
   * @param string|null $file
   *   Real path of the served file for BinaryFileResponse, otherwise NULL.
   * @param string $contentType
   *   The response Content-Type (may be empty).
   * @param string|null $redirectTarget
   *   Target URL when the response was a redirect.
   * @param string|null $error
   *   Exception message when the request threw.
   */
  public function __construct(
    public readonly string $path,
    public readonly int $status,
    public readonly ?string $content = NULL,
    public readonly ?string $file = NULL,
    public readonly string $contentType = '',
    public readonly ?string $redirectTarget = NULL,
    public readonly ?string $error = NULL,
  ) {}

  public function isHtml(): bool {
    return str_starts_with($this->contentType, 'text/html');
  }

  public function isCss(): bool {
    return str_starts_with($this->contentType, 'text/css');
  }

}
