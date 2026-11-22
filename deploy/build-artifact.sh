#!/usr/bin/env bash
# =============================================================================
# McNutt Legacy — build a self-contained release artifact on a workstation/CI.
#
#   deploy/build-artifact.sh            # -> dist/mcnutt-legacy-<release>.tar.gz (+ .sha256)
#
# The shared server has no Node and should not compile anything, so the artifact contains:
#   * application code from the current git commit (git archive; uncommitted changes are refused)
#   * vendor/ (composer --no-dev, classmap-authoritative, platform php 8.3.6)
#   * public/build/ (Vite production build)
#   * runners/wheelhouse/ (linux x86_64 cp312 binary wheels + the legacy_runners wheel)
#   * RELEASE and SHA256SUMS
# =============================================================================
set -euo pipefail
cd "$(dirname "$0")/.."
ROOT="$PWD"

log() { echo "[build] $*"; }
die() { echo "[build][ERROR] $*" >&2; exit 1; }

command -v git >/dev/null || die "git required"
command -v composer >/dev/null || die "composer required (export PATH=\"\$HOME/.local/bin:\$PATH\")"
command -v npm >/dev/null || die "npm required"
PY="${PYTHON:-runners/.venv/bin/python}"
[ -x "$PY" ] || die "Python with pip required at $PY (create runners/.venv first)"

if [ -z "${ALLOW_DIRTY:-}" ] && [ -n "$(git status --porcelain)" ]; then
  die "Working tree has uncommitted changes. Commit first (or ALLOW_DIRTY=1 for a throwaway test build)."
fi

SHA="$(git rev-parse --short=12 HEAD)"
RELEASE="$(date -u +%Y%m%d%H%M%S)-${SHA}"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
OUT="$ROOT/dist"
mkdir -p "$OUT"

log "Release ${RELEASE}"
log "Running checks (PHP tests, runner tests, typecheck, frontend tests, OpenAPI drift)"
if [ -z "${SKIP_TESTS:-}" ]; then
  php artisan test --compact >/dev/null || die "PHP tests failed"
  (cd runners && .venv/bin/python -m pytest -q >/dev/null) || die "Runner tests failed"
  npx tsc --noEmit -p . || die "TypeScript errors"
  npx vitest run --silent >/dev/null || die "Frontend tests failed"
  php artisan legacy:openapi --check >/dev/null || die "OpenAPI/TS types are stale: run php artisan legacy:openapi and commit"
fi

log "Exporting commit ${SHA}"
mkdir -p "$STAGE/app"
git archive --format=tar HEAD | tar -x -C "$STAGE/app"
rm -f "$STAGE/app/FAMILY_OFFICE_PLATFORM_SPEC"*.md

log "composer install --no-dev"
(cd "$STAGE/app" && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts \
  --optimize-autoloader --classmap-authoritative >/dev/null)
(cd "$STAGE/app" && php artisan package:discover --ansi >/dev/null 2>&1 || true)
rm -f "$STAGE/app/bootstrap/cache/"*.php

log "Vite production build"
npm ci --silent >/dev/null
npm run build --silent >/dev/null
cp -R public/build "$STAGE/app/public/build"
[ -f "$STAGE/app/public/build/manifest.json" ] || die "Vite manifest missing"

log "Python wheelhouse (linux x86_64, CPython 3.12, binary only)"
WH="$STAGE/app/runners/wheelhouse"
mkdir -p "$WH"
"$PY" -m pip download --quiet --only-binary=:all: --platform manylinux2014_x86_64 --platform manylinux_2_17_x86_64 --platform manylinux_2_28_x86_64 \
  --python-version 3.12 --implementation cp --abi cp312 -r runners/requirements.lock -d "$WH" || die "wheel download failed"
"$PY" -m pip wheel --quiet --no-deps ./runners -w "$WH" || die "legacy_runners wheel build failed"
ls "$WH"/legacy_runners-*.whl >/dev/null || die "legacy_runners wheel missing"

echo "$RELEASE" > "$STAGE/app/RELEASE"
(cd "$STAGE/app" && find . -type f ! -name SHA256SUMS -print0 | LC_ALL=C sort -z | xargs -0 shasum -a 256 > SHA256SUMS)

TARBALL="$OUT/mcnutt-legacy-${RELEASE}.tar.gz"
tar -C "$STAGE/app" -czf "$TARBALL" .
(cd "$OUT" && shasum -a 256 "$(basename "$TARBALL")" > "$(basename "$TARBALL").sha256")
log "Artifact: $TARBALL ($(du -h "$TARBALL" | cut -f1))"
echo "$TARBALL"
