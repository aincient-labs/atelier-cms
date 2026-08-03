#!/usr/bin/env bash
#
# Guards the appliance image's layer tiering.
#
# composer.json routes the fast-moving upstream modules — flowdrop — to
# web/modules/engine/ instead of web/modules/contrib/, so docker/Dockerfile can
# copy that tier as its own layer. Measured over the deploy ledger, flowdrop
# accounted for 63% of dependency bumps while sharing one 216 MB layer with
# Drupal core and vendor; splitting it drops a flowdrop-only update from 216 MB
# to 22 MB per appliance.
#
# The catch: composer/installers matches installer-paths by EXACT package name
# (BaseInstaller::mapCustomInstallPaths accepts only "<vendor>/<name>", "type:*"
# and "vendor:*" — there is no glob). So a NEW file-bearing flowdrop package
# would silently land in web/modules/contrib and quietly rejoin the layer it was
# split out of. Nothing would break; the image would just get slower to update,
# invisibly, which is exactly the kind of regression nobody notices.
#
# Most flowdrop_* packages are metapackages and install no files at all, so this
# should stay quiet for a long time. When it does fire, the fix is one line: add
# the package to the "web/modules/engine/{$name}" entry in composer.json.
#
# Two modes:
#   (no args)  assert only — exit 1 on any problem. This is the CI gate.
#   --heal     additionally delete UNAMBIGUOUS stale duplicates, and never exit
#              nonzero. This is what composer's post-install/post-update hook
#              runs, so a developer's tree self-repairs.
#
# Why --heal exists: when installer-paths moved flowdrop out of contrib, composer
# installed it to the new path but did NOT remove the directory already sitting at
# the old one — it has no record that it ever owned that path. A tree that existed
# before the move therefore ends up with flowdrop in BOTH tiers, which Drupal sees
# as two modules with the same name. Fresh clones, CI and the appliance image are
# all unaffected (they install into an empty tree); this is purely a workstation
# migration artifact, which is why it is NOT in converge.sh — the appliance never
# has the problem, and converge.sh could not fix it in an immutable image anyway.
#
# Run after `composer install`, from the repo root.
set -euo pipefail

cd "$(dirname "$0")/../.."

heal=0
[ "${1:-}" = "--heal" ] && heal=1

fail=0

# 1. No file-bearing flowdrop package may sit in the contrib tier.
#
# A stray is only safe to DELETE when the same package also exists in the engine
# tier — that makes it a leftover from the path move, and the live copy is the
# engine one. A stray with NO engine counterpart is the opposite problem: a
# package that genuinely routed to contrib because it is missing from the
# installer-paths list. Deleting that would remove a live module and hide the very
# regression this guard exists to catch, so it always fails instead.
shopt -s nullglob
strays=(web/modules/contrib/flowdrop*)
shopt -u nullglob
# ${arr[@]+"${arr[@]}"} not "${arr[@]}": under `set -u`, bash before 4.4 — which
# includes the 3.2 that ships on macOS — treats expanding an EMPTY array as an
# unbound variable and aborts. The clean case (nothing to prune) is exactly the
# common one, and CI runs bash 5 where it works, so the naive form fails only on
# developer machines and stays green in CI.
for stray in ${strays[@]+"${strays[@]}"}; do
  name="$(basename "$stray")"
  if [ -d "web/modules/engine/$name" ]; then
    if [ "$heal" -eq 1 ]; then
      rm -rf "$stray"
      printf 'pruned stale duplicate left by the installer-paths move: %s\n' "$stray"
      printf '  (the live copy is web/modules/engine/%s)\n' "$name"
    else
      printf 'FAIL: %s duplicates web/modules/engine/%s — Drupal will see two modules of the same name.\n' "$stray" "$name"
      printf 'Run: bash docker/tests/assert-engine-tier.sh --heal\n'
      fail=1
    fi
  else
    printf 'FAIL: %s installed into the contrib tier with no engine counterpart.\n' "$stray"
    printf 'Add %s to the "web/modules/engine/{$name}" installer-paths entry in composer.json.\n' \
      "drupal/$name"
    printf 'NOT deleted — it is a live module, not a leftover.\n'
    fail=1
  fi
done

# 2. The engine tier must actually exist and be non-empty — otherwise the
#    Dockerfile's `mv /app/web/modules/engine /split/engine` ships an empty layer
#    and the whole split silently buys nothing.
if [ ! -d web/modules/engine ] || [ -z "$(ls -A web/modules/engine 2>/dev/null)" ]; then
  printf 'FAIL: web/modules/engine is missing or empty — the engine tier resolved to nothing.\n'
  printf 'Expected drupal/flowdrop to install there (check composer.json installer-paths ordering:\n'
  printf 'the specific entry MUST precede "type:drupal-module", since first match wins).\n'
  fail=1
fi

# --heal is a best-effort repair run from a composer hook: it must never fail the
# developer's `composer install`. The CI gate (no args) is what enforces.
if [ "$fail" -ne 0 ] && [ "$heal" -eq 0 ]; then
  exit 1
fi

# Reached in --heal mode even when something is still wrong (heal never exits
# nonzero), so don't claim "intact" unless it actually is — CI will fail on it.
if [ "$fail" -ne 0 ]; then
  printf 'WARNING: engine tier still has unresolved problems (see above) — CI will fail on this.\n'
  exit 0
fi

printf 'OK: engine tier intact (%s)\n' "$(ls web/modules/engine | tr '\n' ' ')"
