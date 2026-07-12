<?php

declare(strict_types=1);

namespace Drupal\aincient_chat\Chat;

use Drupal\aincient_core\ModelRoleResolver;
use Drupal\aincient_core\ModelRoles;
use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatInterface;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\Plugin\ProviderProxy;
use Psr\Log\LoggerInterface;

/**
 * Names a console thread after its first exchange (Atelier study 02, Plate 8).
 *
 * The shipped sidebar printed the user's raw first message as the title —
 * lowercase, typos and all, duplicated for repeat asks, reading like a debug
 * log. The studio names the thread instead: THE OUTCOME of the first exchange,
 * five words or fewer, sentence case ("Sharper homepage headline"), minted once
 * and stored in the session metadata ({@see SessionThreadStore::setTitle()}).
 *
 * Resolved through the {@see ModelRoles::FAST} role — a thread name is exactly
 * the "trivial classify/extract" tier that role reserves — which falls back to
 * the default chat model when unbound. Every failure path (no provider, model
 * error, junk output) returns NULL and leaves the raw-first-message fallback in
 * place: naming is a polish, never a gate on the conversation.
 */
final class ThreadNamer {

  /**
   * Longest title we'll store — matches the listing's display budget.
   */
  private const MAX_LENGTH = 60;

  public function __construct(
    private readonly SessionThreadStore $threadStore,
    private readonly ModelRoleResolver $roles,
    private readonly AiProviderPluginManager $providers,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Name the thread if it's nameable and still unnamed.
   *
   * @return string|null
   *   The freshly minted title, or NULL when nothing was (or could be) named —
   *   already titled, no complete first exchange, no provider, or the model
   *   returned junk.
   */
  public function maybeName(string $threadId, int $uid): ?string {
    if ($this->threadStore->title($threadId, $uid) !== '') {
      return NULL;
    }
    $exchange = $this->threadStore->firstExchange($threadId, $uid);
    if ($exchange === NULL) {
      return NULL;
    }

    try {
      $title = $this->generate($exchange['user'], $exchange['assistant']);
    }
    catch (\Throwable $e) {
      $this->logger->notice('Thread naming skipped: @message', ['@message' => $e->getMessage()]);
      return NULL;
    }
    if ($title === NULL) {
      return NULL;
    }

    return $this->threadStore->setTitle($threadId, $uid, $title) ? $title : NULL;
  }

  /**
   * One small chat call: the first exchange in, a ≤5-word outcome out.
   */
  private function generate(string $userText, string $assistantText): ?string {
    $binding = $this->roles->resolve(ModelRoles::FAST);
    if ($binding['provider_id'] === '') {
      return NULL;
    }
    $provider = $this->providers->createInstance($binding['provider_id']);
    $plugin = $provider instanceof ProviderProxy ? $provider->getPlugin() : $provider;
    if (!$plugin instanceof ChatInterface) {
      return NULL;
    }

    $instruction = 'Name this conversation for a sidebar: state the OUTCOME being worked toward '
      . 'in five words or fewer, sentence case (capitalize only the first word and proper nouns). '
      . 'No quotes, no trailing punctuation, no "Re:", never echo the request verbatim. '
      . 'Return only the name.';
    $prompt = $instruction
      . "\n\nUser asked:\n" . mb_strimwidth($userText, 0, 600, '…')
      . "\n\nAssistant replied:\n" . mb_strimwidth($assistantText, 0, 600, '…');

    $input = new ChatInput([new ChatMessage('user', $prompt)]);
    $output = $provider->chat($input, $binding['model_id'], ['aincient_chat_thread_namer']);
    $normalized = $output->getNormalized();
    $text = $normalized instanceof ChatMessage ? trim($normalized->getText()) : '';

    return $this->sanitize($text);
  }

  /**
   * Maître-d' sanitation: accept a plausible name, reject paste accidents.
   */
  private function sanitize(string $text): ?string {
    // First line only, quotes and terminal punctuation shed.
    $text = trim(strtok($text, "\n") ?: '');
    $text = trim($text, " \t\"'“”‘’.…");
    if ($text === '') {
      return NULL;
    }
    // A name, not a paragraph: reject runaway output rather than truncating a
    // sentence mid-thought (the fallback title is better than a mangled one).
    if (mb_strlen($text) > self::MAX_LENGTH || str_word_count($text) > 8) {
      return NULL;
    }
    return $text;
  }

}
