#!/usr/bin/env bash
# =============================================================================
# McNutt Legacy — deploy from a workstation to the shared McNutt Cloud server.
#
#   ./deploy.sh preflight                 # read-only server checks; changes nothing
#   ./deploy.sh                           # build artifact, upload, install, switch, health-check
#   ./deploy.sh --artifact dist/x.tar.gz  # deploy a previously built artifact
#   ./deploy.sh rollback                  # switch back to the previous release (no DB rollback)
#   ./deploy.sh status                    # releases, health, timers
#
# Environment:
#   DEPLOY_HOST (default ubuntu@login.mcnutt.cloud)   DEPLOY_KEY (default ~/Downloads/new-shared-server.pem)
#   SKIP_LETSENCRYPT=1  (install without requesting a certificate)
#
# Shared-server safety: everything remote runs through deploy/server/*.sh, which only touches
# mcnutt-legacy-owned paths and validates Apache/PHP-FPM config before any reload (never restart).
# =============================================================================
set -euo pipefail
cd "$(dirname "$0")"

: "${DEPLOY_HOST:=ubuntu@login.mcnutt.cloud}"
: "${DEPLOY_KEY:=$HOME/Downloads/new-shared-server.pem}"
SSH_OPTS=(-i "$DEPLOY_KEY" -o IdentitiesOnly=yes -o ServerAliveInterval=30 -o StrictHostKeyChecking=accept-new)
REMOTE_TMP="/tmp/mcnutt-legacy-deploy"

log() { echo "[deploy] $*"; }
die() { echo "[deploy][ERROR] $*" >&2; exit 1; }
[ -r "$DEPLOY_KEY" ] || die "SSH key not readable: $DEPLOY_KEY (set DEPLOY_KEY)"

remote() { ssh "${SSH_OPTS[@]}" "$DEPLOY_HOST" "$@"; }

push_server_scripts() {
  remote "rm -rf $REMOTE_TMP && mkdir -p $REMOTE_TMP && chmod 700 $REMOTE_TMP"
  scp -q "${SSH_OPTS[@]}" deploy/server/*.sh deploy/server/*.conf.tpl "$DEPLOY_HOST:$REMOTE_TMP/"
}

cmd="${1:-deploy}"
case "$cmd" in
  preflight)
    push_server_scripts
    remote "sudo bash $REMOTE_TMP/preflight.sh; rc=\$?; rm -rf $REMOTE_TMP; exit \$rc"
    ;;
  rollback)
    push_server_scripts
    remote "sudo bash $REMOTE_TMP/rollback.sh ${2:-}; rc=\$?; rm -rf $REMOTE_TMP; exit \$rc"
    ;;
  status)
    push_server_scripts
    remote "sudo bash $REMOTE_TMP/status.sh; rc=\$?; rm -rf $REMOTE_TMP; exit \$rc"
    ;;
  deploy|--artifact)
    ARTIFACT=""
    if [ "$cmd" = "--artifact" ]; then ARTIFACT="${2:?artifact path}"; fi
    if [ -z "$ARTIFACT" ]; then
      ARTIFACT="$(deploy/build-artifact.sh | tail -1)"
    fi
    [ -f "$ARTIFACT" ] && [ -f "$ARTIFACT.sha256" ] || die "Artifact or checksum missing: $ARTIFACT"
    (cd "$(dirname "$ARTIFACT")" && shasum -a 256 -c "$(basename "$ARTIFACT").sha256" >/dev/null) || die "Local checksum mismatch"
    push_server_scripts
    log "Uploading $(basename "$ARTIFACT")"
    scp -q "${SSH_OPTS[@]}" "$ARTIFACT" "$ARTIFACT.sha256" "$DEPLOY_HOST:$REMOTE_TMP/"
    log "Installing on server"
    remote "cd $REMOTE_TMP && sha256sum -c $(basename "$ARTIFACT").sha256 >/dev/null && sudo SKIP_LETSENCRYPT=${SKIP_LETSENCRYPT:-0} bash $REMOTE_TMP/install.sh $REMOTE_TMP/$(basename "$ARTIFACT")"
    remote "rm -rf $REMOTE_TMP"
    ;;
  *) die "Unknown command: $cmd" ;;
esac
