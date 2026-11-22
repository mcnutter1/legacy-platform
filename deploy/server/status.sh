#!/usr/bin/env bash
# McNutt Legacy — status overview (read-only).
set -uo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
. "$HERE/common.sh"
require_root

echo "Current:  $(basename "$(readlink -f "$CURRENT_LINK" 2>/dev/null || echo none)")"
echo "Releases:"; ls -1t "$RELEASES_DIR" 2>/dev/null | sed 's/^/  /'
echo "Health:   $(curl -s --max-time 5 --resolve "${DOMAIN}:443:127.0.0.1" "https://${DOMAIN}/health" || echo unavailable)"
echo "Backup:   $(cat "${DATA_DIR}/backup-status.json" 2>/dev/null || echo none)"
echo "Drill:    $(cat "${DATA_DIR}/restore-check-status.json" 2>/dev/null || echo none)"
echo "FPM:      $(systemctl is-active "php${PHP_VERSION}-fpm") socket $([ -S "$FPM_SOCK" ] && echo present || echo missing)"
echo "Jobs:"
$MYSQL_ADMIN -N -e "SELECT status, COUNT(*) FROM \`${DB_NAME}\`.compute_jobs GROUP BY status" 2>/dev/null | sed 's/^/  /' || echo "  unavailable"
echo "Recent scheduler log:"; tail -n 5 "${LOG_DIR}/scheduler.log" 2>/dev/null | sed 's/^/  /'
