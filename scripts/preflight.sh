#!/usr/bin/env bash
# Read-only server preflight: prerequisites, collisions with sibling apps, DNS/TLS, secrets. Changes nothing.
set -euo pipefail
cd "$(dirname "$0")/.."
exec ./deploy.sh preflight
