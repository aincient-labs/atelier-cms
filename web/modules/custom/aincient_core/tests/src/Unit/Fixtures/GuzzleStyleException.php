<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_core\Unit\Fixtures;

use Psr\Http\Message\ResponseInterface;

/**
 * Reproduces Guzzle's BadResponseException shape: response via an accessor.
 *
 * @see \GuzzleHttp\Exception\BadResponseException
 */
final class GuzzleStyleException extends \Exception {

  public function __construct(
    string $message,
    private readonly ?ResponseInterface $psrResponse,
  ) {
    parent::__construct($message);
  }

  /**
   * The response, mirroring Guzzle's accessor.
   *
   * @return \Psr\Http\Message\ResponseInterface|null
   *   The response, or NULL when the request never got one.
   */
  public function getResponse(): ?ResponseInterface {
    return $this->psrResponse;
  }

}
