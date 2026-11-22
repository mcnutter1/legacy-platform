#!/usr/bin/env bash
# Update the server to the current commit (build -> verify -> upload -> install -> atomic switch). See deploy.sh.
set -euo pipefail
cd "$(dirname "$0")/.."
if [ "${1:-}" = "--dry-run" ]; then exec ./deploy.sh preflight; fi
exec ./deploy.sh "$@"
