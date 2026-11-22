# shellcheck shell=bash disable=SC2034
# Shared settings for McNutt Legacy server scripts. Sourced by install/preflight/rollback/status.
APP_SLUG="mcnutt-legacy"
DOMAIN="${DOMAIN:-legacy.mcnutt.cloud}"
LE_EMAIL="${LE_EMAIL:-robert.mcnutt@gmail.com}"
RUN_AS="mcnutt-legacy"
WEB_GROUP="www-data"
PHP_VERSION="${PHP_VERSION:-8.3}"
PYTHON_BIN="${PYTHON_BIN:-/usr/bin/python3.12}"
OPT_DIR="/opt/${APP_SLUG}"
RELEASES_DIR="${OPT_DIR}/releases"
CURRENT_LINK="${OPT_DIR}/current"
ETC_DIR="/etc/${APP_SLUG}"
ENV_FILE="${ETC_DIR}/legacy.env"
DATA_DIR="/var/lib/${APP_SLUG}"
STORAGE_DIR="${DATA_DIR}/storage"
VAULT_DIR="${DATA_DIR}/vault"
JOB_DIR="${DATA_DIR}/jobs"
ACME_ROOT="${DATA_DIR}/acme"
LOG_DIR="/var/log/${APP_SLUG}"
BACKUP_DIR="/var/backups/${APP_SLUG}"
DB_NAME="mcnutt_legacy"
DB_USER="mcnutt_legacy"
MYSQL_ADMIN="${MYSQL_ADMIN:-mysql}"
FPM_POOL_FILE="/etc/php/${PHP_VERSION}/fpm/pool.d/${APP_SLUG}.conf"
FPM_SOCK="/run/php/${APP_SLUG}.sock"
VHOST_FILE="/etc/apache2/sites-available/${DOMAIN}.conf"
LOGFORMAT_CONF="/etc/apache2/conf-available/${APP_SLUG}-logformat.conf"
CRON_FILE="/etc/cron.d/${APP_SLUG}"
LOGROTATE_FILE="/etc/logrotate.d/${APP_SLUG}"
BACKUP_SCRIPT="/usr/local/sbin/${APP_SLUG}-backup"
RESTORE_CHECK_SCRIPT="/usr/local/sbin/${APP_SLUG}-restore-check"
LE_SCRIPT="/usr/local/sbin/${APP_SLUG}-letsencrypt"
CERT_DIR="${CERT_DIR:-/etc/letsencrypt/live/${DOMAIN}}"
KEEP_RELEASES="${KEEP_RELEASES:-5}"

log()  { echo "[legacy] $*"; }
warn() { echo "[legacy][WARN] $*" >&2; }
die()  { echo "[legacy][ERROR] $*" >&2; exit 1; }
require_root() { [ "$(id -u)" -eq 0 ] || die "Run with sudo."; }

env_get() { [ -f "$ENV_FILE" ] && grep -E "^$1=" "$ENV_FILE" | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' || true; }
env_set() {
  local key="$1" val="$2"
  if grep -qE "^${key}=" "$ENV_FILE"; then
    local esc; esc="$(printf '%s' "$val" | sed -e 's/[|&\\]/\\&/g')"
    sed -i "s|^${key}=.*|${key}=${esc}|" "$ENV_FILE"
  else
    echo "${key}=${val}" >> "$ENV_FILE"
  fi
}
as_app() { sudo -u "$RUN_AS" -H "$@"; }
