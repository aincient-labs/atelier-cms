<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Exception;

use Psr\Http\Message\ResponseInterface;

/**
 * Digs the upstream response body out of a provider exception chain.
 *
 * Transport exceptions routinely throw away the one thing worth knowing.
 * openai-php builds its `ServerException` message as
 * "Server error (HTTP 503) occurred." and drops the body; Drupal AI's LiteLLM
 * provider only string-matches rate-limit and budget wording before rethrowing.
 * So a real upstream complaint reached the job trail, the log and the user as
 * five useless words — DECISIONS 0269 needed browser forensics purely for that.
 *
 * The response object usually survives on the exception even when the message
 * does not: openai-php exposes it as a public `$response`, Guzzle's
 * `BadResponseException` via `getResponse()`. Walk the `previous` chain, take the
 * first body we can read, and cap it.
 *
 * @see \Drupal\aincient_core\Service\Reasoning\AincientChatReasoner::reason()
 */
final class UpstreamDetail {

  /**
   * Cap: long enough for a nested provider error, short enough for a log line.
   */
  private const MAX_LENGTH = 800;

  /**
   * Extracts the upstream body from an exception chain.
   *
   * Reads defensively throughout — this runs while a failure is already being
   * handled, and must never become the error the user sees instead.
   *
   * @param \Throwable $e
   *   The caught failure.
   *
   * @return string|null
   *   A trimmed, length-capped response body, or NULL when the chain carries
   *   nothing worth adding to the message.
   */
  public static function from(\Throwable $e): ?string {
    for ($current = $e; $current !== NULL; $current = $current->getPrevious()) {
      $response = self::responseOf($current);
      if ($response === NULL) {
        continue;
      }
      try {
        $body = $response->getBody();
        if ($body->isSeekable()) {
          $body->rewind();
        }
        $text = trim($body->getContents());
      }
      catch (\Throwable) {
        continue;
      }
      if ($text === '') {
        continue;
      }
      return mb_strlen($text) > self::MAX_LENGTH
        ? mb_substr($text, 0, self::MAX_LENGTH) . '…'
        : $text;
    }
    return NULL;
  }

  /**
   * The PSR-7 response an exception carries, however it exposes it.
   *
   * @param \Throwable $e
   *   One link of the chain.
   *
   * @return \Psr\Http\Message\ResponseInterface|null
   *   Its response, or NULL when it carries none.
   */
  private static function responseOf(\Throwable $e): ?ResponseInterface {
    if (property_exists($e, 'response') && $e->response instanceof ResponseInterface) {
      return $e->response;
    }
    if (method_exists($e, 'getResponse')) {
      try {
        $candidate = $e->getResponse();
      }
      catch (\Throwable) {
        return NULL;
      }
      return $candidate instanceof ResponseInterface ? $candidate : NULL;
    }
    return NULL;
  }

}
