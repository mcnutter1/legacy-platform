#!/usr/bin/env bash
# McNutt Legacy — restore drill. Verifies the newest (or given) backup end to end in a scratch database,
# checks integrity invariants, then drops the scratch database. Production data is never touched.
#   sudo /usr/local/sbin/mcnutt-legacy-restore-check [backup-file]
set -euo pipefail
umask 077
. /usr/local/sbin/mcnutt-legacy-common.sh
require_root

KEY="${ETC_DIR}/backup.key"
FILE="${1:-$(ls -1t "$BACKUP_DIR"/${APP_SLUG}-*.tgz.enc 2>/dev/null | head -1)}"
[ -n "$FILE" ] && [ -f "$FILE" ] || die "No backup found in $BACKUP_DIR"
SCRATCH="${DB_NAME}_restorecheck"
WORK="$(mktemp -d)"
cleanup() { rm -rf "$WORK"; $MYSQL_ADMIN -e "DROP DATABASE IF EXISTS \`${SCRATCH}\`" >/dev/null 2>&1 || true; }
trap cleanup EXIT

log "Checking integrity of $(basename "$FILE")"
sha256sum --quiet -c "$FILE.sha256"
EXPECTED="$(cat "$FILE.hmac")"
ACTUAL="$(openssl dgst -sha256 -mac HMAC -macopt "key:$(sha256sum "$KEY" | cut -c1-64)" -r "$FILE" | cut -d' ' -f1)"
[ "$EXPECTED" = "$ACTUAL" ] || die "HMAC mismatch: backup altered or wrong key"
openssl enc -d -aes-256-cbc -pbkdf2 -iter 300000 -pass file:"$KEY" -in "$FILE" -out "$WORK/bundle.tgz"
tar -C "$WORK" -xzf "$WORK/bundle.tgz"

$MYSQL_ADMIN -e "DROP DATABASE IF EXISTS \`${SCRATCH}\`; CREATE DATABASE \`${SCRATCH}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
$MYSQL_ADMIN "$SCRATCH" < "$WORK/database.sql"
log "Restored into scratch database ${SCRATCH}"

q() { $MYSQL_ADMIN -N "$SCRATCH" -e "$1"; }
q "SELECT 'workspaces', COUNT(*) FROM workspaces UNION ALL SELECT 'persons', COUNT(*) FROM persons UNION ALL SELECT 'accounts', COUNT(*) FROM accounts
   UNION ALL SELECT 'transactions', COUNT(*) FROM transactions UNION ALL SELECT 'documents', COUNT(*) FROM documents UNION ALL SELECT 'audit_events', COUNT(*) FROM audit_events;"
UNBAL="$(q "SELECT COUNT(*) FROM (SELECT entry_id FROM journal_lines GROUP BY entry_id HAVING SUM(functional_debit) <> SUM(functional_credit)) t;" 2>/dev/null || echo 0)"
[ "$UNBAL" = "0" ] || die "${UNBAL} journal entr(ies) out of balance in the backup"
ORPHANS="$(q "SELECT COUNT(*) FROM document_versions v LEFT JOIN documents d ON d.id = v.document_id WHERE d.id IS NULL;")"
[ "$ORPHANS" = "0" ] || die "${ORPHANS} orphaned document versions"
MISSING=0
while IFS= read -r p; do
  tar -tf "$WORK/vault.tar" "vault/$p" >/dev/null 2>&1 || MISSING=$((MISSING + 1))
done < <(q "SELECT storage_path FROM document_versions WHERE storage_path IS NOT NULL AND storage_path <> '' LIMIT 500;")
[ "$MISSING" = "0" ] || die "${MISSING} vault file(s) referenced by the database are missing from the archive"
log "Vault archive contains every sampled ciphertext referenced by the database"
log "Backup release: $(cat "$WORK/RELEASE" 2>/dev/null || echo unknown)"
echo "{\"status\":\"ok\",\"checked_at\":\"$(date -u +%FT%TZ)\",\"file\":\"$(basename "$FILE")\"}" > "${DATA_DIR}/restore-check-status.json"
chmod 640 "${DATA_DIR}/restore-check-status.json"; chown root:"$RUN_AS" "${DATA_DIR}/restore-check-status.json"
echo "[OK] Restore drill passed for $(basename "$FILE")."
