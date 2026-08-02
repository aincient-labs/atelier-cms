<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Inference\Exception;

/**
 * A provider cannot be built from the credential/endpoint it was given.
 *
 * Distinct from an upstream failure: this is a LOCAL configuration fault (no key
 * stored, an endpoint missing for a provider that requires one, an unknown
 * provider id), detectable without a network call. Callers surface it as
 * "connect a provider" rather than "the model is unavailable".
 */
final class ProviderConfigurationException extends \RuntimeException {

}
