#!/usr/bin/env bash
# =============================================================================
# McNutt Legacy — server-side install/upgrade of a prebuilt release artifact.
#
#   sudo bash install.sh /tmp/mcnutt-legacy-deploy/mcnutt-legacy-<release>.tar.gz
#   sudo SKIP_LETSENCRYPT=1 bash install.sh <artifact>
#
# Idempotent. Shared-server safety:
#   * Writes only mcnutt-legacy paths: /opt, /etc, /var/lib, /var/log, /var/backups /mcnutt-legacy,
#     its own FPM pool, vhost, log-format conf, cron.d, logrotate.d and /usr/local/sbin/mcnutt-legacy-* files.
#   * Database statements are scoped to one database and one user.
#   * apache2ctl configtest / php-fpm -t run before every reload (never restart); our file is rolled back
#     on failure so other sites keep serving.
#   * Never installs or changes PHP versions or system defaults. Missing packages abort with a list.
# =============================================================================
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=common.sh
. "$HERE/common.sh"
require_root

ARTIFACT="${1:?usage: install.sh <artifact.tar.gz>}"
[ -f "$ARTIFACT" ] || die "Artifact not found: $ARTIFACT"
SKIP_LETSENCRYPT="${SKIP_LETSENCRYPT:-0}"
APP_TAG="${APP_SLUG//-/_}"
PHP="/usr/bin/php${PHP_VERSION}"

# --- 1. Platform prerequisites (read-only checks) ------------------------------
check_platform() {
  local missing=()
  command -v apache2ctl >/dev/null || missing+=("apache2")
  [ -x "$PHP" ] || missing+=("php${PHP_VERSION}-cli")
  [ -x "/usr/sbin/php-fpm${PHP_VERSION}" ] || missing+=("php${PHP_VERSION}-fpm")
  [ -x "$PYTHON_BIN" ] || missing+=("python3.12")
  "$PYTHON_BIN" -c "import venv, ensurepip" 2>/dev/null || missing+=("python3.12-venv")
  command -v mysql >/dev/null || missing+=("mysql-client")
  command -v certbot >/dev/null || missing+=("certbot")
  local mods; mods="$("$PHP" -m | tr '[:upper:]' '[:lower:]')"
  for ext in pdo_mysql mbstring dom curl zip gd intl bcmath sodium fileinfo openssl; do
    echo "$mods" | grep -qx "$ext" || missing+=("php${PHP_VERSION}-ext:${ext}")
  done
  [ "${#missing[@]}" -eq 0 ] || die "Missing prerequisites (install deliberately, then re-run): ${missing[*]}"
  systemctl is-active --quiet "php${PHP_VERSION}-fpm" || die "php${PHP_VERSION}-fpm is not running"
  systemctl is-active --quiet apache2 || die "apache2 is not running"
}

# --- 2. Service account and directories -----------------------------------------
ensure_account() {
  if ! id "$RUN_AS" >/dev/null 2>&1; then
    log "Creating system user ${RUN_AS} (no login shell)"
    useradd --system --user-group --home-dir "$DATA_DIR" --no-create-home --shell /usr/sbin/nologin "$RUN_AS"
  fi
}

ensure_dirs() {
  mkdir -p "$RELEASES_DIR" "$ETC_DIR" "$DATA_DIR" "$STORAGE_DIR" "$VAULT_DIR" "$JOB_DIR" "$ACME_ROOT/.well-known/acme-challenge" "$LOG_DIR" "$BACKUP_DIR"
  mkdir -p "$STORAGE_DIR"/{app,framework/{cache/data,sessions,views},logs,fonts}
  chown root:root "$OPT_DIR" "$RELEASES_DIR"; chmod 755 "$OPT_DIR" "$RELEASES_DIR"
  chown root:"$RUN_AS" "$ETC_DIR"; chmod 750 "$ETC_DIR"
  chown root:"$RUN_AS" "$DATA_DIR"; chmod 750 "$DATA_DIR"
  chown -R "$RUN_AS:$RUN_AS" "$STORAGE_DIR" "$VAULT_DIR" "$JOB_DIR"
  chmod 700 "$VAULT_DIR" "$JOB_DIR"; chmod 750 "$STORAGE_DIR"
  chown root:root "$ACME_ROOT"; chmod 755 "$ACME_ROOT" "$ACME_ROOT/.well-known" "$ACME_ROOT/.well-known/acme-challenge"
  chown "$RUN_AS:$RUN_AS" "$LOG_DIR"; chmod 750 "$LOG_DIR"
  chown root:root "$BACKUP_DIR"; chmod 700 "$BACKUP_DIR"
}

# --- 3. Environment & secrets (never overwritten once set) -----------------------
rand_alnum() { openssl rand -base64 64 | tr -dc 'A-Za-z0-9' | cut -c1-"${1:-32}"; }

ensure_env() {
  if [ ! -f "$ENV_FILE" ]; then
    log "Creating ${ENV_FILE} from the release template (first deploy)"
    install -m 640 -o root -g "$RUN_AS" "$NEW_RELEASE/.env.example" "$ENV_FILE"
  fi
  [ -n "$(env_get APP_KEY)" ] || { env_set APP_KEY "base64:$(openssl rand -base64 32)"; log "Generated APP_KEY."; }
  [ -n "$(env_get VAULT_ROOT_KEY)" ] || { env_set VAULT_ROOT_KEY "$(openssl rand -base64 32)"; log "Generated VAULT_ROOT_KEY — escrow it offline; it is NOT in database backups."; }
  [ -n "$(env_get DB_PASSWORD)" ] || env_set DB_PASSWORD "$(rand_alnum 32)"
  env_set APP_URL "https://${DOMAIN}"
  env_set LEGACY_RELEASE "$RELEASE"
  env_set LEGACY_PRIVATE_STORAGE "$VAULT_DIR"
  env_set LEGACY_JOB_DIR "$JOB_DIR"
  env_set RUNNER_PYTHON "${CURRENT_LINK}/runners/.venv/bin/python"
  env_set LEGACY_BACKUP_STATUS_FILE "${DATA_DIR}/backup-status.json"
  grep -q '^LOG_STACK_PATH=' "$ENV_FILE" || true
  chown root:"$RUN_AS" "$ENV_FILE"; chmod 640 "$ENV_FILE"
}

# --- 4. Database (one DB + one user) ----------------------------------------------
ensure_database() {
  local pass; pass="$(env_get DB_PASSWORD)"
  [[ "$pass" =~ ^[A-Za-z0-9]{24,}$ ]] || die "DB_PASSWORD must be alphanumeric (>=24 chars) for safe provisioning."
  $MYSQL_ADMIN -e "SELECT 1" >/dev/null 2>&1 || die "Cannot connect to MySQL as admin (set MYSQL_ADMIN)."
  log "Ensuring database ${DB_NAME} and user ${DB_USER}@localhost"
  $MYSQL_ADMIN <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${pass}';
ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${pass}';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES, CREATE TEMPORARY TABLES, LOCK TABLES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL
}

# --- 5. Unpack and verify the release ---------------------------------------------
unpack_release() {
  local tmp; tmp="$(mktemp -d "${RELEASES_DIR}/.incoming.XXXXXX")"
  tar -xzf "$ARTIFACT" -C "$tmp" --no-same-owner
  [ -f "$tmp/RELEASE" ] || { rm -rf "$tmp"; die "Artifact has no RELEASE file"; }
  RELEASE="$(tr -dc 'A-Za-z0-9-' < "$tmp/RELEASE")"
  (cd "$tmp" && sha256sum --quiet -c SHA256SUMS) || { rm -rf "$tmp"; die "Release file checksums failed"; }
  NEW_RELEASE="${RELEASES_DIR}/${RELEASE}"
  if [ -d "$NEW_RELEASE" ]; then
    warn "Release ${RELEASE} already present; reinstalling it"
    rm -rf "$NEW_RELEASE"
  fi
  mv "$tmp" "$NEW_RELEASE"
  log "Unpacked release ${RELEASE}"
}

wire_release() {
  rm -rf "$NEW_RELEASE/storage"
  ln -s "$STORAGE_DIR" "$NEW_RELEASE/storage"
  ln -sfn "$ENV_FILE" "$NEW_RELEASE/.env"
  rm -f "$NEW_RELEASE/public/hot" "$NEW_RELEASE/public/storage"
  chown -R root:root "$NEW_RELEASE"
  find "$NEW_RELEASE" -type d -exec chmod 755 {} +
  find "$NEW_RELEASE" -type f -exec chmod 644 {} +
  chmod 755 "$NEW_RELEASE/artisan"
  mkdir -p "$NEW_RELEASE/bootstrap/cache"
  chown -R "$RUN_AS:$RUN_AS" "$NEW_RELEASE/bootstrap/cache"
  chmod 750 "$NEW_RELEASE/bootstrap/cache"
}

build_runner_env() {
  local venv="$NEW_RELEASE/runners/.venv" wh="$NEW_RELEASE/runners/wheelhouse"
  log "Creating Python ${PYTHON_BIN##*/} virtualenv from the offline wheelhouse"
  "$PYTHON_BIN" -m venv "$venv"
  "$venv/bin/python" -m pip install --quiet --no-index --find-links "$wh" -r "$NEW_RELEASE/runners/requirements.lock"
  "$venv/bin/python" -m pip install --quiet --no-index --no-deps --find-links "$wh" legacy_runners
  "$venv/bin/python" -I -c "import legacy_runners, numpy, pydantic, pypdf; print('runner ok', legacy_runners.ENGINE_VERSION)" \
    || die "Runner environment failed to import"
  chown -R root:root "$venv"
  find "$venv" -type d -exec chmod 755 {} +
}

artisan() { (cd "$NEW_RELEASE" && as_app env RUNNER_PYTHON="$NEW_RELEASE/runners/.venv/bin/python" "$PHP" artisan "$@"); }

migrate_and_cache() {
  artisan config:clear >/dev/null
  log "Running migrations (additive; old release keeps working during the switch)"
  artisan migrate --force --no-interaction
  artisan legacy:packs-sync
  artisan config:cache >/dev/null
  artisan route:cache >/dev/null
  artisan view:cache >/dev/null
  artisan event:cache >/dev/null || true
  artisan legacy:preflight --no-network || warn "Preflight reported issues (see above)."
}

switch_current() {
  PREV_RELEASE=""
  [ -L "$CURRENT_LINK" ] && PREV_RELEASE="$(readlink -f "$CURRENT_LINK")"
  ln -sfn "$NEW_RELEASE" "${CURRENT_LINK}.next"
  mv -T "${CURRENT_LINK}.next" "$CURRENT_LINK"
  [ -n "$PREV_RELEASE" ] && echo "$PREV_RELEASE" > "${OPT_DIR}/previous-release"
  log "current -> ${NEW_RELEASE}"
}

revert_current() {
  [ -n "${PREV_RELEASE:-}" ] && [ -d "$PREV_RELEASE" ] || { warn "No previous release to revert to"; return; }
  ln -sfn "$PREV_RELEASE" "${CURRENT_LINK}.next"
  mv -T "${CURRENT_LINK}.next" "$CURRENT_LINK"
  env_set LEGACY_RELEASE "$(basename "$PREV_RELEASE")"
  systemctl reload "php${PHP_VERSION}-fpm" || true
  warn "Reverted current -> $(basename "$PREV_RELEASE")"
}

predeploy_backup() {
  [ -L "$CURRENT_LINK" ] && [ -x "$BACKUP_SCRIPT" ] || return 0
  if [ "${SKIP_PREDEPLOY_BACKUP:-0}" = "1" ]; then warn "Skipping pre-deploy backup (SKIP_PREDEPLOY_BACKUP=1)"; return 0; fi
  log "Taking a pre-deploy encrypted backup"
  "$BACKUP_SCRIPT" >> "${LOG_DIR}/backup.log" 2>&1 || die "Pre-deploy backup failed; nothing was changed. See ${LOG_DIR}/backup.log"
}

# --- 6. PHP-FPM pool ---------------------------------------------------------------
render() { # template -> stdout with @@VAR@@ substitution
  sed -e "s|@@APP_SLUG@@|${APP_SLUG}|g" -e "s|@@RUN_AS@@|${RUN_AS}|g" -e "s|@@WEB_GROUP@@|${WEB_GROUP}|g" \
      -e "s|@@FPM_SOCK@@|${FPM_SOCK}|g" -e "s|@@LOG_DIR@@|${LOG_DIR}|g" -e "s|@@OPT_DIR@@|${OPT_DIR}|g" \
      -e "s|@@DATA_DIR@@|${DATA_DIR}|g" -e "s|@@ETC_DIR@@|${ETC_DIR}|g" -e "s|@@DOMAIN@@|${DOMAIN}|g" \
      -e "s|@@ACME_ROOT@@|${ACME_ROOT}|g" -e "s|@@CERT_DIR@@|${CERT_DIR}|g" -e "s|@@APP_TAG@@|${APP_TAG}|g" "$1"
}

ensure_fpm_pool() {
  local backup=""
  [ -f "$FPM_POOL_FILE" ] && backup="$(mktemp)" && cp -a "$FPM_POOL_FILE" "$backup"
  render "$HERE/fpm-pool.conf.tpl" > "$FPM_POOL_FILE"
  if ! "/usr/sbin/php-fpm${PHP_VERSION}" -t >/dev/null 2>&1; then
    if [ -n "$backup" ]; then mv "$backup" "$FPM_POOL_FILE"; else rm -f "$FPM_POOL_FILE"; fi
    "/usr/sbin/php-fpm${PHP_VERSION}" -t || true
    die "PHP-FPM config test failed; our pool file was rolled back."
  fi
  rm -f "$backup"
  systemctl reload "php${PHP_VERSION}-fpm"
  for _ in 1 2 3 4 5 6 7 8; do [ -S "$FPM_SOCK" ] && break; sleep 1; done
  [ -S "$FPM_SOCK" ] || die "FPM socket ${FPM_SOCK} did not appear"
}

# --- 7. Apache vhost & TLS ---------------------------------------------------------
ensure_apache_modules() {
  local m
  for m in proxy proxy_fcgi setenvif headers ssl rewrite; do
    a2query -q -m "$m" 2>/dev/null || { a2enmod -q "$m" >/dev/null; log "Enabled Apache module ${m} (additive)"; }
  done
}

write_vhost() {
  local with_ssl="$1" http_body https_block=""
  if [ "$with_ssl" = "1" ]; then
    http_body="    RewriteEngine On
    RewriteCond %{REQUEST_URI} !^/\\.well-known/acme-challenge/
    RewriteRule ^ https://${DOMAIN}%{REQUEST_URI} [R=301,L]"
    https_block="$(render "$HERE/vhost-https.conf.tpl")"
  else
    http_body="    # TLS not issued yet: the application is not served over plain HTTP.
    <Directory ${OPT_DIR}/current/public>
        Require all denied
    </Directory>"
  fi
  local tpl; tpl="$(render "$HERE/vhost.conf.tpl")"
  tpl="${tpl/@@HTTP_BODY@@/$http_body}"
  tpl="${tpl/@@HTTPS_BLOCK@@/$https_block}"
  printf '%s\n' "$tpl" > "${VHOST_FILE}.new"
  cat > "$LOGFORMAT_CONF" <<EOF
# Managed by McNutt Legacy deploy — query strings are never logged.
LogFormat "%h %l %u %t \"%m %U %H\" %>s %b \"-\" \"%{User-Agent}i\" %D" ${APP_TAG}_safe
LogFormat "%h %l %u %t \"%m [redacted-link] %H\" %>s %b \"-\" \"%{User-Agent}i\" %D" ${APP_TAG}_redacted
EOF
}

apply_vhost() {
  local with_ssl="$1" backup=""
  write_vhost "$with_ssl"
  [ -f "$VHOST_FILE" ] && backup="$(mktemp)" && cp -a "$VHOST_FILE" "$backup"
  mv "${VHOST_FILE}.new" "$VHOST_FILE"
  a2enconf -q "${APP_SLUG}-logformat" >/dev/null
  a2ensite -q "${DOMAIN}" >/dev/null
  if ! apache2ctl configtest >/dev/null 2>&1; then
    if [ -n "$backup" ]; then mv "$backup" "$VHOST_FILE"; else a2dissite -q "${DOMAIN}" >/dev/null || true; rm -f "$VHOST_FILE"; fi
    apache2ctl configtest || true
    die "Apache configtest failed; our vhost was rolled back and Apache was not reloaded."
  fi
  rm -f "$backup"
  systemctl reload apache2
  log "Apache vhost active for ${DOMAIN} (TLS: $([ "$with_ssl" = 1 ] && echo yes || echo pending))"
}

write_letsencrypt_script() {
  cat > "$LE_SCRIPT" <<EOF
#!/usr/bin/env bash
# Managed by McNutt Legacy deploy — obtain/renew the certificate for ${DOMAIN} via webroot (never edits Apache).
set -uo pipefail
DOMAIN="${DOMAIN}"; EMAIL="${LE_EMAIL}"; WEBROOT="${ACME_ROOT}"; CH="\$WEBROOT/.well-known/acme-challenge"
[ "\$(id -u)" -eq 0 ] || { echo "Run as root"; exit 1; }
PUBLIC_IP="\$(curl -fsS4 --max-time 5 https://api.ipify.org || true)"
DNS_IPS="\$(getent ahostsv4 "\$DOMAIN" | awk '{print \$1}' | sort -u | tr '\n' ' ')"
[ -n "\$DNS_IPS" ] || { echo "[ERROR] \$DOMAIN does not resolve. Create an A record -> \${PUBLIC_IP:-this server}."; exit 1; }
if [ -n "\$PUBLIC_IP" ] && ! echo " \$DNS_IPS" | grep -q " \$PUBLIC_IP "; then
  echo "[WARN] \$DOMAIN resolves to \$DNS_IPS; this server is \$PUBLIC_IP (proxied record? set DNS-only until issued)."
fi
TOKEN="selftest-\$(openssl rand -hex 8)"; echo "\$TOKEN" > "\$CH/\$TOKEN"; chmod 644 "\$CH/\$TOKEN"
GOT="\$(curl -s --max-time 10 --resolve "\$DOMAIN:80:127.0.0.1" "http://\$DOMAIN/.well-known/acme-challenge/\$TOKEN" || true)"
rm -f "\$CH/\$TOKEN"
[ "\$GOT" = "\$TOKEN" ] || { echo "[ERROR] Apache did not serve the ACME challenge for \$DOMAIN on :80"; apache2ctl -S 2>/dev/null | grep -E "port 80|\$DOMAIN" | sed 's/^/   /'; exit 1; }
certbot certonly --webroot -w "\$WEBROOT" -d "\$DOMAIN" --email "\$EMAIL" --agree-tos --no-eff-email --non-interactive \\
  --keep-until-expiring --cert-name "\$DOMAIN" --deploy-hook "apache2ctl configtest && systemctl reload apache2"
EOF
  chmod 750 "$LE_SCRIPT"
}

ensure_tls() {
  write_letsencrypt_script
  [ -f "$CERT_DIR/fullchain.pem" ] && return 0
  if [ "$SKIP_LETSENCRYPT" = "1" ]; then
    warn "SKIP_LETSENCRYPT=1 — run ${LE_SCRIPT} once DNS points here, then redeploy."
    return 1
  fi
  log "Requesting Let's Encrypt certificate"
  "$LE_SCRIPT" && return 0
  warn "Certificate request failed (DNS/port 80). Fix, then: sudo ${LE_SCRIPT} && ./deploy.sh"
  return 1
}

# --- 8. Cron, logrotate, backups -----------------------------------------------------
ensure_cron_and_rotation() {
  cat > "$CRON_FILE" <<EOF
# Managed by McNutt Legacy deploy.
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
# Scheduler: bounded job workers (interactive/bulk), outbox, notifications, alerts, pruning. No daemons.
* * * * * ${RUN_AS} cd ${CURRENT_LINK} && /usr/bin/php${PHP_VERSION} artisan schedule:run >> ${LOG_DIR}/scheduler.log 2>&1
# Encrypted daily backup (database + vault ciphertext); the vault key and APP_KEY are NOT included.
45 2 * * * root ${BACKUP_SCRIPT} >> ${LOG_DIR}/backup.log 2>&1
# Weekly restore drill into a scratch database (production untouched).
15 4 * * 0 root ${RESTORE_CHECK_SCRIPT} >> ${LOG_DIR}/restore-check.log 2>&1
EOF
  chmod 644 "$CRON_FILE"
  cat > "$LOGROTATE_FILE" <<EOF
${LOG_DIR}/*.log ${STORAGE_DIR}/logs/*.log {
    weekly
    rotate 12
    compress
    delaycompress
    missingok
    notifempty
    copytruncate
    su ${RUN_AS} ${RUN_AS}
}
EOF
  chmod 644 "$LOGROTATE_FILE"
}

install_backup_scripts() {
  install -m 750 -o root -g root "$HERE/backup.sh" "$BACKUP_SCRIPT"
  install -m 750 -o root -g root "$HERE/restore-check.sh" "$RESTORE_CHECK_SCRIPT"
  install -m 644 -o root -g root "$HERE/common.sh" "/usr/local/sbin/${APP_SLUG}-common.sh"
  if [ ! -f "$ETC_DIR/backup.key" ]; then
    openssl rand -base64 48 > "$ETC_DIR/backup.key"
    log "Generated ${ETC_DIR}/backup.key — copy it offsite with legacy.env."
  fi
  chown root:root "$ETC_DIR/backup.key"; chmod 600 "$ETC_DIR/backup.key"
}

# --- 9. Verify, prune, summarize -------------------------------------------------------
health_check() {
  [ -f "$CERT_DIR/fullchain.pem" ] || { warn "No TLS yet — skipping HTTPS health check."; return 0; }
  local code body
  body="$(mktemp)"
  code="$(curl -s -o "$body" -w '%{http_code}' --resolve "${DOMAIN}:443:127.0.0.1" "https://${DOMAIN}/health" || true)"
  log "Health HTTP ${code}: $(head -c 400 "$body")"
  rm -f "$body"
  if [ "$code" != "200" ] && [ "$code" != "503" ]; then
    revert_current
    die "Release failed its health check (HTTP ${code}); previous release restored. See ${LOG_DIR}/php-fpm.log and ${STORAGE_DIR}/logs."
  fi
}

prune_releases() {
  local keep cur
  cur="$(readlink -f "$CURRENT_LINK")"
  keep="$(ls -1dt "$RELEASES_DIR"/*/ 2>/dev/null | head -n "$KEEP_RELEASES" | sed 's:/*$::')"
  for d in "$RELEASES_DIR"/*/; do
    d="${d%/}"
    [ "$d" = "$cur" ] && continue
    echo "$keep" | grep -qx "$d" || { rm -rf "$d"; log "Pruned old release $(basename "$d")"; }
  done
}

summary() {
  local missing=()
  for k in AUTH_APP_ID AUTH_APP_SECRET NOTIFICATIONS_API_TOKEN; do [ -n "$(env_get "$k")" ] || missing+=("$k"); done
  echo
  echo "=================================================================="
  echo " McNutt Legacy release ${RELEASE}"
  echo "   URL:       https://${DOMAIN}"
  echo "   Code:      ${CURRENT_LINK} -> $(readlink -f "$CURRENT_LINK")"
  echo "   Env:       ${ENV_FILE}  (escrow with ${ETC_DIR}/backup.key; VAULT_ROOT_KEY is not in backups)"
  echo "   Data:      ${DATA_DIR}   Logs: ${LOG_DIR}"
  echo "   Backups:   ${BACKUP_SCRIPT} (02:45 daily) · drill ${RESTORE_CHECK_SCRIPT} (Sun 04:15)"
  echo "   Rollback:  ./deploy.sh rollback"
  [ -f "$CERT_DIR/fullchain.pem" ] || echo "   ACTION: no TLS certificate yet — point DNS A ${DOMAIN} here, run ${LE_SCRIPT}, redeploy."
  if [ "${#missing[@]}" -gt 0 ]; then
    echo "   ACTION: set in ${ENV_FILE} then redeploy (or run: php artisan config:cache as ${RUN_AS}):"
    for k in "${missing[@]}"; do echo "     - ${k}"; done
  fi
  echo "=================================================================="
}

main() {
  exec 9>"/run/lock/${APP_SLUG}-deploy.lock"
  flock -n 9 || die "Another deploy is running."
  check_platform
  ensure_account
  ensure_dirs
  unpack_release
  ensure_env
  ensure_database
  wire_release
  build_runner_env
  predeploy_backup
  migrate_and_cache
  switch_current
  ensure_fpm_pool
  ensure_apache_modules
  if [ -f "$CERT_DIR/fullchain.pem" ]; then
    apply_vhost 1; write_letsencrypt_script
  else
    apply_vhost 0
    if ensure_tls; then apply_vhost 1; fi
  fi
  install_backup_scripts
  ensure_cron_and_rotation
  health_check
  prune_releases
  summary
}

main "$@"
