<?php

declare(strict_types=1);

namespace Drupal\aincient_chat\Chat;

/**
 * The context rule every agent's system prompt carries. DECISIONS 0379.
 *
 * One home for the words: it is injected as the `context_policy` template
 * variable on every turn, and each workflow's PromptTemplate renders
 * `{{ context_policy }}`. Pasted into seven templates instead, it would drift —
 * and the drifted copy is the one that gets believed.
 *
 * It states AUTHORITY, not absence. The tempting version announces the pruning
 * ("detailed tool records have been removed from your history"), which invites
 * exactly the behaviours we do not want: hedging about lost context, re-reading
 * the page to "check", or asking the user to restate what they already said. So
 * the rule says where truth lives instead.
 *
 * The third clause is the one the tier split actually requires. Tool traffic is
 * per-turn (see the workflows' scratchpad appenders) and the transcript keeps no
 * tool receipts — so an agent that treats history as complete can reason "I see
 * no evidence I removed that section, so I will remove it". Live state prevents
 * a wrong VALUE; only this clause prevents a wrong INFERENCE from silence.
 *
 * @see \Drupal\aincient_chat\Controller\ChatController::buildClientContext()
 */
final class ContextPolicy {

  /**
   * The rule, as rendered into the system prompt.
   */
  public const TEXT = <<<'TXT'
CONTEXT RULES
- Any live state given to you above (the page schema, brand tokens, the site's
  chrome) is the ONLY source of current values. Read every value you need from
  it, never from earlier messages in this conversation — the user edits the same
  draft you do, so an older message may describe a state that no longer exists.
- The conversation is for INTENT and CONTINUITY: what the user wants, what you
  agreed, what you already told them.
- Earlier turns' tool calls are not in your context. Do not conclude from their
  absence that an edit did or did not happen — the live state above shows what
  exists. If it is already the way the user asked for, say so instead of redoing
  the work.
TXT;

}
