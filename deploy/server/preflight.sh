#!/usr/bin/env bash
# McNutt Legacy — read-only preflight. Reports exactly what install.sh would create or change.
# Makes no changes. Safe to run at any time:  ./deploy.sh preflight
set -uo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
. "$HERE/common.sh"
require_root

ok=0; warnings=0; problems=0
pass() { echo "  [ok]   $*"; ok=$((ok + 1)); }
note() { echo "  [warn] $*"; warnings=$((warnings + 1)); }
bad()  { echo "  [FAIL] $*"; problems=$((problems + 1)); }
will() { echo "  [new]  $*"; }

echo "== Platform"
. /etc/os-release 2>/dev/null && pass "OS ${PRETTY_NAME:-unknown} ($(uname -m))"
command -v apache2ctl >/dev/null && pass "Apache $(apache2ctl -v 2>/dev/null | head -1 | awk '{print $3}')" || bad "apache2 missing"
systemctl is-active --quiet apache2 && pass "apache2 running" || bad "apache2 not running"
apache2ctl configtest >/dev/null 2>&1 && pass "apache2ctl configtest passes today (baseline)" || bad "apache2ctl configtest ALREADY failing — fix before deploying"
[ -x "/usr/bin/php${PHP_VERSION}" ] && pass "PHP $(/usr/bin/php${PHP_VERSION} -r 'echo PHP_VERSION;')" || bad "php${PHP_VERSION} missing"
systemctl is-active --quiet "php${PHP_VERSION}-fpm" && pass "php${PHP_VERSION}-fpm running" || bad "php${PHP_VERSION}-fpm not running"
"/usr/sbin/php-fpm${PHP_VERSION}" -t >/dev/null 2>&1 && pass "php-fpm -t passes today (baseline)" || bad "php-fpm config test ALREADY failing"
mods="$(/usr/bin/php${PHP_VERSION} -m 2>/dev/null | tr '[:upper:]' '[:lower:]')"
for ext in pdo_mysql mbstring dom curl zip gd intl bcmath sodium fileinfo openssl; do
  echo "$mods" | grep -qx "$ext" && pass "php ext ${ext}" || bad "php ext ${ext} missing (php${PHP_VERSION}-${ext})"
done
[ -x "$PYTHON_BIN" ] && pass "$("$PYTHON_BIN" --version)" || bad "${PYTHON_BIN} missing"
"$PYTHON_BIN" -c "import venv, ensurepip" 2>/dev/null && pass "python venv/ensurepip available" || bad "python3.12-venv missing"
command -v mysql >/dev/null && pass "mysql client" || bad "mysql client missing"
$MYSQL_ADMIN -e "SELECT 1" >/dev/null 2>&1 && pass "MySQL admin access ($($MYSQL_ADMIN -N -e 'SELECT VERSION()'))" || bad "Cannot connect as MySQL admin (MYSQL_ADMIN=${MYSQL_ADMIN})"
command -v certbot >/dev/null && pass "certbot" || bad "certbot missing"
command -v clamdscan >/dev/null || command -v clamscan >/dev/null && pass "ClamAV available" || note "ClamAV not installed: uploads are marked 'unscanned' (VAULT_REQUIRE_SCAN=false). Consider: apt install clamav-daemon"
free -m | awk '/Mem:/{ if ($7 < 1024) print "  [warn] available memory " $7 " MB"; else print "  [ok]   available memory " $7 " MB" }'
df -Pm /var/lib | awk 'NR==2{ if ($4 < 5120) print "  [warn] /var/lib free " $4 " MB"; else print "  [ok]   /var/lib free " $4 " MB" }'

echo "== Names this deploy will own (must not collide with other apps)"
id "$RUN_AS" >/dev/null 2>&1 && pass "user ${RUN_AS} exists" || will "system user ${RUN_AS} (nologin)"
for d in "$OPT_DIR" "$ETC_DIR" "$DATA_DIR" "$LOG_DIR" "$BACKUP_DIR"; do [ -e "$d" ] && pass "$d exists" || will "$d"; done
[ -f "$ENV_FILE" ] && pass "${ENV_FILE} exists (secrets preserved)" || will "${ENV_FILE} with generated APP_KEY, VAULT_ROOT_KEY, DB_PASSWORD"
if [ -f "$FPM_POOL_FILE" ]; then
  grep -q "McNutt Legacy deploy" "$FPM_POOL_FILE" && pass "FPM pool ${FPM_POOL_FILE} (ours)" || bad "${FPM_POOL_FILE} exists and is NOT ours"
else will "FPM pool ${FPM_POOL_FILE} (socket ${FPM_SOCK})"; fi
grep -lr "^\[${APP_SLUG}\]" /etc/php/*/fpm/pool.d/ 2>/dev/null | grep -v "$FPM_POOL_FILE" | while read -r f; do bad "pool name [${APP_SLUG}] already used in $f"; done
if [ -f "$VHOST_FILE" ]; then
  grep -q "McNutt Legacy deploy" "$VHOST_FILE" && pass "vhost ${VHOST_FILE} (ours)" || bad "${VHOST_FILE} exists and is NOT ours"
else will "vhost ${VHOST_FILE}"; fi
other="$(apache2ctl -S 2>/dev/null | grep -i "${DOMAIN}" | grep -v "${VHOST_FILE}" || true)"
[ -z "$other" ] && pass "no other vhost answers for ${DOMAIN}" || bad "another vhost references ${DOMAIN}: ${other}"
wild="$(apache2ctl -S 2>/dev/null | grep -E "alias \*\.${DOMAIN#*.}" || true)"
[ -z "$wild" ] || note "wildcard alias for *.${DOMAIN#*.} exists: ${wild} (a specific ServerName for ${DOMAIN} still wins)"
for f in "$CRON_FILE" "$LOGROTATE_FILE" "$LOGFORMAT_CONF" "$BACKUP_SCRIPT" "$RESTORE_CHECK_SCRIPT" "$LE_SCRIPT"; do [ -e "$f" ] && pass "$f" || will "$f"; done
$MYSQL_ADMIN -N -e "SHOW DATABASES LIKE '${DB_NAME}'" 2>/dev/null | grep -q . && pass "database ${DB_NAME} exists" || will "database ${DB_NAME} + user ${DB_USER}@localhost (grants on this DB only)"
for m in proxy proxy_fcgi setenvif headers ssl rewrite; do a2query -q -m "$m" 2>/dev/null && pass "apache mod ${m}" || will "enable apache mod ${m} (additive)"; done

echo "== DNS & TLS"
PUBLIC_IP="$(curl -fsS4 --max-time 5 https://api.ipify.org 2>/dev/null || true)"
DNS_IPS="$(getent ahostsv4 "$DOMAIN" | awk '{print $1}' | sort -u | tr '\n' ' ')"
if [ -z "$DNS_IPS" ]; then bad "${DOMAIN} does not resolve — create A record -> ${PUBLIC_IP:-this server}"
elif echo " $DNS_IPS" | grep -q " ${PUBLIC_IP} "; then pass "${DOMAIN} -> ${DNS_IPS}"
else note "${DOMAIN} -> ${DNS_IPS} but server public IP is ${PUBLIC_IP}"; fi
[ -f "$CERT_DIR/fullchain.pem" ] && pass "certificate present" || will "Let's Encrypt certificate via webroot (needs DNS + port 80)"

echo "== Configuration"
if [ -f "$ENV_FILE" ]; then
  for k in AUTH_APP_ID AUTH_APP_SECRET NOTIFICATIONS_API_TOKEN; do [ -n "$(env_get "$k")" ] && pass "$k set" || note "$k not set"; done
fi
if [ -L "$CURRENT_LINK" ]; then pass "current release $(basename "$(readlink -f "$CURRENT_LINK")")"; else will "first release"; fi

echo
echo "Summary: ${ok} ok, ${warnings} warnings, ${problems} problems. (No changes were made.)"
[ "$problems" -eq 0 ]
