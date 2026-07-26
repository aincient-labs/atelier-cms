<?php

declare(strict_types=1);

namespace Drupal\aincient_onboarding;

use Drupal\aincient_core\ModelPresetResolver;
use Drupal\aincient_core\ModelRoleResolver;
use Drupal\aincient_core\RecommendationSource;

/**
 * Applies a model profile (tier) to the role bindings, and keeps it current.
 *
 * A profile is the operator's standing answer to "how good/expensive should the
 * models be" — best value, balanced, best quality. It is chosen once, in words
 * they understand, and it is the ONLY model decision auto mode ever asks for:
 * which concrete model each role lands on is ours to pick and ours to keep
 * current. That promise is why the profile is stored ({@see
 * ModelRoleResolver::profile()}) rather than being used once and forgotten —
 * without it, "keep me on balanced" is unhonourable, because a refreshed
 * recommendations document has nothing to re-resolve against.
 *
 * Auto mode is all-or-nothing: a profile describes the whole site. Hand-picking
 * a single role means the operator has taken ownership, and everything here
 * stops applying to them (see {@see ModelRoleResolver::clearProfile()}).
 */
final class ProfileApplier {

  public function __construct(
    private readonly ModelPresetResolver $presets,
    private readonly ProviderCatalog $catalog,
    private readonly ModelRoleResolver $resolver,
    private readonly RecommendationSource $source,
  ) {}

  /**
   * Resolve a profile against what is connected and bind every role to it.
   *
   * @param string $profileId
   *   The profile to apply. An unknown id is refused rather than silently
   *   resolving to nothing — that would unbind the site.
   *
   * @return array{ok: bool, bound: array<string, string>, message: string}
   *   Whether it applied, the role => "provider:model" it settled on, and a
   *   message when it did not.
   */
  public function apply(string $profileId): array {
    if (!$this->presets->hasProfile($profileId)) {
      return ['ok' => FALSE, 'bound' => [], 'message' => 'Unknown model profile.'];
    }

    $catalog = $this->catalog->storedCatalog();
    $picks = $this->presets->apply($profileId, $catalog['chat'], $catalog['image']);
    if ($picks === []) {
      // Nothing connected yet, or the profile's candidates match none of it.
      // Leave the bindings alone: a profile that resolves to nothing must not be
      // able to wipe a working configuration.
      return ['ok' => FALSE, 'bound' => [], 'message' => 'No connected model matches that profile.'];
    }

    foreach ($picks as $role => $pick) {
      [$provider, $model] = $this->split((string) $pick);
      if ($provider === '' || $model === '') {
        continue;
      }
      $this->resolver->bind((string) $role, $provider, $model);
    }

    $this->resolver->setProfile($profileId, (string) ($this->source->meta()['updated'] ?? ''));
    $this->resolver->project();

    return ['ok' => TRUE, 'bound' => $picks, 'message' => ''];
  }

  /**
   * Re-resolve the stored profile after the recommendations document changed.
   *
   * The point of storing the intent. Called on an explicit refresh: if the site
   * is in auto mode, its tier is re-resolved against the new document and the
   * bindings move with it — quietly, because the operator chose not to be in the
   * business of picking models. The return value carries what moved so the UI can
   * say so after the fact rather than asking permission beforehand.
   *
   * A Custom site is left strictly alone: those bindings are deliberate, and
   * overwriting them would be the opposite of respecting the choice.
   *
   * @return array{applied: bool, profile: string, changed: array<string, array{from: string, to: string}>}
   *   Whether anything was re-applied, the profile in force, and the per-role
   *   moves (empty when the new document resolves to the same models).
   */
  public function reapplyStored(): array {
    $profile = $this->resolver->profile();
    if ($profile === '') {
      return ['applied' => FALSE, 'profile' => '', 'changed' => []];
    }

    $before = $this->currentBindings();
    $result = $this->apply($profile);
    if (!$result['ok']) {
      // Could not resolve — the old bindings still stand, which is the safe
      // outcome. Report nothing changed rather than claiming a failed update.
      return ['applied' => FALSE, 'profile' => $profile, 'changed' => []];
    }

    $after = $this->currentBindings();
    $changed = [];
    foreach ($after as $role => $now) {
      $was = $before[$role] ?? '';
      if ($was !== $now) {
        $changed[$role] = ['from' => $was, 'to' => $now];
      }
    }

    return ['applied' => TRUE, 'profile' => $profile, 'changed' => $changed];
  }

  /**
   * The bound roles as role => "provider:model", skipping unbound ones.
   *
   * @return array<string, string>
   */
  private function currentBindings(): array {
    $out = [];
    foreach ($this->resolver->roles() as $role => $row) {
      $provider = (string) ($row['provider_id'] ?? '');
      $model = (string) ($row['model_id'] ?? '');
      if ($provider === '' || $model === '') {
        continue;
      }
      $out[(string) $role] = $provider . ':' . $model;
    }
    return $out;
  }

  /**
   * Split a "provider:model" pick. Model ids may themselves contain colons.
   *
   * @return array{0: string, 1: string}
   */
  private function split(string $pick): array {
    if (!str_contains($pick, ':')) {
      return ['', ''];
    }
    [$provider, $model] = explode(':', $pick, 2);
    return [trim($provider), trim($model)];
  }

}
