#!/usr/bin/env bash
# Switch the server back to the previous (or named) release. Schema changes are additive and stay in place.
set -euo pipefail
cd "$(dirname "$0")/.."
exec ./deploy.sh rollback "${1:-}"
