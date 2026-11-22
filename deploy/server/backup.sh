#!/usr/bin/env bash
# McNutt Legacy — encrypted backup of the database and vault ciphertext.
# Installed to /usr/local/sbin/mcnutt-legacy-backup by deploy; runs daily from /etc/cron.d/mcnutt-legacy.
#
# Key custody: legacy.env (APP_KEY, VAULT_ROOT_KEY) is deliberately NOT in the bundle, so a stolen backup plus
# backup.key cannot open vault files or encrypted columns. Escrow legacy.env and backup.key separately offline.
set -euo pipefail
umask 077
. /usr/local/sbin/mcnutt-legacy-common.sh
require_root

DEST="${BACKUP_DEST:-$BACKUP_DIR}"
KEY="${ETC_DIR}/backup.key"
STATUS="${DATA_DIR}/backup-status.json"
RETENTION="${BACKUP_RETENTION_DAYS:-35}"
TS="$(date -u +%Y%m%dT%H%M%SZ)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

write_status() { printf '%s\n' "$1" > "$STATUS"; chown root:"$RUN_AS" "$STATUS"; chmod 640 "$STATUS"; }
fail() { write_status "{\"status\":\"failed\",\"finished_at\":\"$(date -u +%FT%TZ)\",\"error\":\"$1\"}"; echo "[ERROR] $1" >&2; exit 1; }

[ -f "$KEY" ] || fail "backup key missing"
mkdir -p "$DEST"
printf '[client]\nuser=%s\npassword=%s\nhost=%s\n' "$(env_get DB_USERNAME)" "$(env_get DB_PASSWORD)" "$(env_get DB_HOST)" > "$WORK/my.cnf"
mysqldump --defaults-extra-file="$WORK/my.cnf" --single-transaction --quick --routines --no-tablespaces --hex-blob "$(env_get DB_DATABASE)" > "$WORK/database.sql" \
  || fail "mysqldump failed"
tar -C "$DATA_DIR" -cf "$WORK/vault.tar" "$(basename "$VAULT_DIR")" || fail "vault archive failed"
readlink -f "$CURRENT_LINK" | xargs basename > "$WORK/RELEASE" 2>/dev/null || true
tar -C "$WORK" -czf "$WORK/bundle.tgz" database.sql vault.tar RELEASE
OUT="$DEST/${APP_SLUG}-${TS}.tgz.enc"
openssl enc -aes-256-cbc -pbkdf2 -iter 300000 -salt -pass file:"$KEY" -in "$WORK/bundle.tgz" -out "$OUT" || fail "encryption failed"
# Encrypt-then-MAC: openssl enc is not authenticated, so tampering is detected before decryption.
openssl dgst -sha256 -mac HMAC -macopt "key:$(sha256sum "$KEY" | cut -c1-64)" -r "$OUT" | cut -d' ' -f1 > "$OUT.hmac"
sha256sum "$OUT" > "$OUT.sha256"
find "$DEST" -name "${APP_SLUG}-*.tgz.enc*" -mtime +"$RETENTION" -delete
SIZE="$(stat -c %s "$OUT")"
if [ -n "${OFFSITE_RSYNC_TARGET:-}" ]; then
  rsync -a "$OUT" "$OUT.sha256" "$OUT.hmac" "$OFFSITE_RSYNC_TARGET" || fail "offsite copy failed"
fi
write_status "{\"status\":\"ok\",\"finished_at\":\"$(date -u +%FT%TZ)\",\"file\":\"$(basename "$OUT")\",\"bytes\":${SIZE},\"offsite\":$([ -n "${OFFSITE_RSYNC_TARGET:-}" ] && echo true || echo false)}"
echo "[INFO] Backup written: $OUT (${SIZE} bytes)"
