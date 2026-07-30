<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_core\Unit\Fixtures;

use Psr\Http\Message\ResponseInterface;

/**
 * Reproduces openai-php's ServerException: body discarded, response public.
 *
 * The real class is final, so it cannot be mocked or subclassed; this copies
 * the shape that matters — a message with no cause in it, and the response
 * hanging off a public property.
 *
 * @see \OpenAI\Exceptions\ServerException
 */
final class OpenAiStyleException extends \Exception {

  public function __construct(public ResponseInterface $response) {
    parent::__construct("Server error (HTTP {$response->getStatusCode()}) occurred.");
  }

}
