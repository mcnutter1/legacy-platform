#!/usr/bin/env bash
# McNutt Legacy — switch `current` back to the previous (or a named) release.
# Migrations are additive and are NOT rolled back; the previous code runs against the newer schema.
#   sudo bash rollback.sh [release-name]
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
. "$HERE/common.sh"
require_root

target="${1:-}"
cur="$(readlink -f "$CURRENT_LINK" 2>/dev/null || true)"
[ -n "$cur" ] || die "No current release"
if [ -n "$target" ]; then
  target="${RELEASES_DIR}/$(basename "$target")"
elif [ -f "${OPT_DIR}/previous-release" ]; then
  target="$(cat "${OPT_DIR}/previous-release")"
else
  target="$(ls -1dt "$RELEASES_DIR"/*/ | sed 's:/*$::' | grep -vx "$cur" | head -1)"
fi
[ -n "$target" ] && [ -d "$target" ] || die "No release to roll back to"
[ "$target" != "$cur" ] || die "Target is already current"
[ -f "$target/vendor/autoload.php" ] && [ -x "$target/runners/.venv/bin/python" ] || die "Target release is incomplete: $target"

ln -sfn "$target" "${CURRENT_LINK}.next"
mv -T "${CURRENT_LINK}.next" "$CURRENT_LINK"
echo "$cur" > "${OPT_DIR}/previous-release"
sed -i "s|^LEGACY_RELEASE=.*|LEGACY_RELEASE=$(basename "$target")|" "$ENV_FILE"
(cd "$target" && sudo -u "$RUN_AS" -H "/usr/bin/php${PHP_VERSION}" artisan config:cache >/dev/null)
"/usr/sbin/php-fpm${PHP_VERSION}" -t >/dev/null 2>&1 && systemctl reload "php${PHP_VERSION}-fpm"
log "Rolled back: current -> $(basename "$target") (was $(basename "$cur"))"
