<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Exception;

/**
 * A provider request failed, carrying what the upstream actually said.
 *
 * Thrown by {@see \Drupal\aincient_core\Service\Reasoning\AincientChatReasoner}
 * in place of an opaque transport exception whose message was just
 * "Server error (HTTP 503) occurred." (DECISIONS 0269). The original is always
 * the `previous` exception; this one exists to carry a diagnosable message and
 * to be greppable.
 *
 * Deliberately extends \Exception, NOT \RuntimeException. FlowDrop's
 * NodeRuntimeService routes a \RuntimeException through its inner catch (error
 * Output → error-edge routing, node status COMPLETED), while everything else
 * takes the generic path: node status FAILED, `logNodeFailure()`, and a
 * "Node execution failed for <node>: <message>" rethrow. The latter is what a
 * provider failure does today, and it is the more forensically useful of the
 * two on an appliance where dblog is off and the job trail is the only surface.
 * Retyping is part of wiring the reserved `error` port (plans/current.md), which
 * needs workflow-level authoring and verification — not a side effect of making
 * the message readable.
 */
final class AiProviderFailure extends \Exception {

}
