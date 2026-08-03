<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Hook;

use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Tells the status report WHICH ATELIER THIS IS.
 *
 * THE LINE A BUG REPORT NEEDS. Atelier ships as a container image and converges
 * itself on every start, so the running code is whatever the image held when it
 * was last pulled — and until now nothing in the product could name that. A
 * report saying "the studio chat is broken" was unanswerable: we could not tell
 * whether the reporter was on last night's rolling build or a tag from three
 * weeks ago, which is the difference between a regression we just shipped and one
 * we fixed and they have not pulled. This row makes the first question of every
 * bug report answerable by the reporter, on a page they already know.
 *
 * ON THE STATUS REPORT, deliberately. It is the page Drupal operators are already
 * trained to screenshot, it is reachable when the console itself is the thing
 * misbehaving, and it needs no new UI.
 *
 * READS THE ENVIRONMENT, not a constant in code. A version compiled into a PHP
 * constant would have to be bumped by a commit BEFORE the tag that ships it — so
 * it is wrong exactly once per release, in the direction that matters, and the
 * mistake is invisible. The image stamps `ATELIER_VERSION` at build time from the
 * ref being built (see the end of `docker/Dockerfile` and the release workflow),
 * which means the string here is produced by the same thing that produced the
 * pullable tag and cannot drift from it.
 *
 * "DEVELOPMENT" IS A REAL ANSWER. A DDEV checkout, a phpunit run and a local
 * `docker build` all have no stamp, and an unstamped site must say so rather than
 * inherit a plausible number. A version that lies is worse than one that is
 * absent: it sends us looking for a regression in a release the reporter was
 * never running.
 *
 * IN A HOOK CLASS, not `aincient_core.install`.
 * `SystemManager::listRequirements()` invokes `runtime_requirements` without
 * loading `.install` files, so a procedural implementation there would silently
 * never run. {@see PricingRequirements} which records the same trap.
 */
final class VersionRequirements {

  use StringTranslationTrait;

  /**
   * The build stamp baked into the appliance image.
   *
   * Set by `ENV ATELIER_VERSION` at the end of `docker/Dockerfile`. Absent
   * everywhere else, on purpose.
   */
  private const ENV_VAR = 'ATELIER_VERSION';

  /**
   * The value an unstamped build carries — the Dockerfile's ARG default.
   */
  private const UNSTAMPED = 'dev';

  /**
   * Implements hook_runtime_requirements().
   *
   * @return array<string, array<string, mixed>>
   *   One requirement, keyed as core expects.
   */
  #[Hook('runtime_requirements')]
  public function runtimeRequirements(): array {
    $stamp = $this->stamp();

    if ($stamp === NULL) {
      return [
        'aincient_core_version' => [
          'title' => $this->t('Atelier version'),
          'value' => $this->t('Development (unreleased build)'),
          'description' => $this->t('This site is not running a released Atelier image, so it has no version to report. That is expected for a local development checkout. If you did install from a released image and see this, the image was built without its build stamp — say so in any bug report, because we cannot otherwise tell which code you are running.'),
          // Info, not a warning. On a developer's machine this is the correct
          // and permanent state, and a warning nobody can clear is one everyone
          // learns to scroll past — which costs us the pricing rows above it.
          'severity' => RequirementSeverity::Info,
        ],
      ];
    }

    return [
      'aincient_core_version' => [
        'title' => $this->t('Atelier version'),
        'value' => $stamp,
        'description' => $this->isRolling($stamp)
          // A rolling build is head-of-development, not a release. Said plainly,
          // because the reporter's expectations should differ and because the
          // `edge+<sha>` form is otherwise cryptic. The sha in it is what makes
          // the report locatable to an exact commit.
          ? $this->t('A rolling <code>edge</code> build, published from the latest development snapshot rather than a release. Include this exact string when reporting a problem.')
          : $this->t('Include this exact version when reporting a problem.'),
        'severity' => RequirementSeverity::Info,
      ],
    ];
  }

  /**
   * The build stamp, or NULL when this is not a stamped release image.
   *
   * Treats the ARG default and an empty value the same as absence: all three mean
   * "nothing told this build what it was", and only the caller's message differs.
   */
  private function stamp(): ?string {
    $raw = getenv(self::ENV_VAR);
    if ($raw === FALSE) {
      return NULL;
    }
    $raw = trim($raw);

    return ($raw === '' || $raw === self::UNSTAMPED) ? NULL : $raw;
  }

  /**
   * Whether this is a rolling build rather than a tagged release.
   *
   * Matches the workflow's own two shapes — `edge+<sha7>` versus a `v*` tag — so
   * this stays true by construction if the tag format changes.
   */
  private function isRolling(string $stamp): bool {
    return str_starts_with($stamp, 'edge');
  }

}
