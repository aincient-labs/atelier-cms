<?php

declare(strict_types=1);

namespace Drupal\aincient_studio_components;

use Drupal\aincient_pages\Catalog\ComponentCatalogInterface;
use Drupal\aincient_pages\ComponentCatalog;

/**
 * Builds a validated `components_proposal` widget envelope (DECISIONS 0455, P2).
 *
 * The Components agent's one verb stages a change in the studio — it never
 * publishes and never writes a pack (0368). This is the single place its raw
 * arguments are checked against the DISCOVERED vocabulary (every component,
 * variant and tone the site could offer) and the kind registry, so only names
 * the rail knows reach the client. The widget merges the payload into the
 * staged draft of the named scope; Publish (with its impact list) stays the
 * human's.
 *
 * Changes are explicit on/off operations, not a whole constraint: the agent
 * never sees the browser's unsaved draft, and an op list merges onto whatever
 * the user has staged without clobbering it.
 */
final class ComponentProposalApplier {

  /**
   * The op keys per scope. Kind-only keys are refused in the site scope.
   */
  private const SITE_KEYS = ['turn_off', 'turn_on', 'variants_off', 'variants_on', 'tones_off', 'tones_on'];
  private const KIND_KEYS = ['include_new', 'opener', 'limits'];

  public function __construct(
    private readonly ComponentCatalogInterface $catalog,
  ) {}

  /**
   * Build the envelope.
   *
   * @param array{scope?: mixed, changes_json?: mixed, reason?: mixed} $args
   *   Raw tool args: `scope` ("site" or a kind id), `changes_json` (a JSON
   *   object of ops) and an optional one-line `reason` shown on the card.
   *
   * @return array
   *   `['__widget__' => 'components_proposal', 'payload' => […], 'summary' =>
   *   '…']`, or a single-key `['error' => '…']`.
   */
  public function apply(array $args): array {
    $scope = trim((string) ($args['scope'] ?? 'site')) ?: 'site';
    $kinds = $this->catalog->kinds(TRUE);
    if ($scope !== 'site' && !isset($kinds[$scope])) {
      return ['error' => sprintf('Error: unknown scope "%s". Use "site" or one of: %s.', $scope, implode(', ', array_keys($kinds)))];
    }
    if ($scope !== 'site' && ($kinds[$scope]['mode'] ?? '') === 'recipe') {
      return ['error' => sprintf('Error: "%s" has a fixed layout — there is nothing to place, so nothing to propose.', $scope)];
    }

    $raw = trim((string) ($args['changes_json'] ?? ''));
    $changes = $raw === '' ? NULL : json_decode($raw, TRUE);
    if (!is_array($changes) || $changes === [] || array_is_list($changes)) {
      return ['error' => 'Error: changes must be a JSON object, e.g. {"turn_off":["pricing"],"tones_off":{"stats":["inverted"]}}.'];
    }

    $discovered = $this->catalog->discovered();
    $known = $discovered->placeableNames();
    $allowed = $scope === 'site' ? self::SITE_KEYS : array_merge(self::SITE_KEYS, self::KIND_KEYS);
    $payload = ['scope' => $scope];
    $rejected = [];

    foreach ($changes as $key => $value) {
      if (!in_array($key, $allowed, TRUE)) {
        $rejected[] = (string) $key . (in_array($key, self::KIND_KEYS, TRUE) ? ' (page types only)' : '');
        continue;
      }
      switch ($key) {
        case 'turn_off':
        case 'turn_on':
          $names = [];
          foreach (is_array($value) ? $value : [] as $name) {
            if (is_string($name) && in_array($name, $known, TRUE)) {
              $names[] = $name;
            }
            else {
              $rejected[] = "$key." . (is_scalar($name) ? (string) $name : '?');
            }
          }
          if ($names !== []) {
            $payload[$key] = array_values(array_unique($names));
          }
          break;

        case 'variants_off':
        case 'variants_on':
        case 'tones_off':
        case 'tones_on':
          $isTone = str_starts_with($key, 'tones');
          $map = [];
          foreach (is_array($value) ? $value : [] as $name => $list) {
            // `*` = every component — the site-wide tone list (site scope only).
            $everywhere = $isTone && $name === '*' && $scope === 'site';
            if (!$everywhere && !in_array($name, $known, TRUE)) {
              $rejected[] = "$key.$name";
              continue;
            }
            $vocab = $isTone ? ComponentCatalog::TONES : ($discovered->variantsFor((string) $name) ?? []);
            $values = array_values(array_intersect($vocab, array_map('strval', is_array($list) ? $list : [])));
            foreach (array_diff(array_map('strval', is_array($list) ? $list : []), $vocab) as $bad) {
              $rejected[] = "$key.$name.$bad";
            }
            if ($values !== []) {
              $map[(string) $name] = $values;
            }
          }
          if ($map !== []) {
            $payload[$key] = $map;
          }
          break;

        case 'include_new':
          $payload['include_new'] = (bool) $value;
          break;

        case 'opener':
          $opener = is_string($value) ? trim($value) : '';
          if ($opener === '' || in_array($opener, $known, TRUE)) {
            $payload['opener'] = $opener;
          }
          else {
            $rejected[] = "opener.$opener";
          }
          break;

        case 'limits':
          $limits = [];
          foreach (is_array($value) ? $value : [] as $name => $max) {
            if (in_array($name, $known, TRUE) && is_numeric($max) && (int) $max >= 0) {
              // 0 clears the limit.
              $limits[(string) $name] = (int) $max;
            }
            else {
              $rejected[] = "limits.$name";
            }
          }
          if ($limits !== []) {
            $payload['limits'] = $limits;
          }
          break;
      }
    }

    if (count($payload) === 1) {
      return ['error' => 'Error: nothing valid to propose' . ($rejected !== [] ? ' (not recognised: ' . implode(', ', $rejected) . ')' : '') . '. Use the component, variant and tone names from COMPONENTS STATE.'];
    }

    $reason = trim((string) ($args['reason'] ?? ''));
    if ($reason !== '') {
      $payload['reason'] = mb_substr($reason, 0, 280);
    }
    $envelope = [
      '__widget__' => 'components_proposal',
      'payload' => $payload,
      'summary' => $this->summary($payload, $scope === 'site' ? 'everywhere' : (string) ($kinds[$scope]['label'] ?? $scope)),
    ];
    if ($rejected !== []) {
      $envelope['rejected'] = $rejected;
    }
    return $envelope;
  }

  /**
   * One line for the agent's transcript: what was staged, where.
   */
  private function summary(array $payload, string $where): string {
    $parts = [];
    foreach (['turn_off' => 'turn off', 'turn_on' => 'turn on'] as $key => $verb) {
      if (!empty($payload[$key])) {
        $parts[] = $verb . ' ' . implode(', ', $payload[$key]);
      }
    }
    foreach (['variants_off', 'variants_on', 'tones_off', 'tones_on'] as $key) {
      if (!empty($payload[$key])) {
        $parts[] = str_replace('_', ' ', $key) . ' for ' . implode(', ', array_keys($payload[$key]));
      }
    }
    foreach (['include_new', 'opener', 'limits'] as $key) {
      if (array_key_exists($key, $payload)) {
        $parts[] = 'set ' . str_replace('_', ' ', $key);
      }
    }
    return sprintf('Staged for %s: %s. Nothing is live until the user publishes.', $where, implode('; ', $parts));
  }

}
