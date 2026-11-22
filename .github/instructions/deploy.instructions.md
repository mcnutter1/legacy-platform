---
applyTo: "deploy.sh,deploy/**,scripts/**,docs/OPERATIONS.md,.github/workflows/**"
---
# Deployment rules (shared McNutt Cloud server)

- The server hosts sibling apps (invoices, journey, finance…). Only touch mcnutt-legacy-owned names:
  user `mcnutt-legacy`, `/opt|/etc|/var/lib|/var/log|/var/backups/mcnutt-legacy`, FPM pool `mcnutt-legacy`,
  vhost `legacy.mcnutt.cloud.conf`, `mcnutt-legacy-logformat.conf`, `/etc/cron.d/mcnutt-legacy`,
  `/etc/logrotate.d/mcnutt-legacy`, `/usr/local/sbin/mcnutt-legacy-*`, database/user `mcnutt_legacy`.
- Never change PHP versions/defaults, global php.ini, other vhosts/pools, or install packages implicitly. Missing
  prerequisites abort with a list.
- Always `apache2ctl configtest` / `php-fpm8.3 -t` before **reload** (never restart); roll back our file on failure.
- Releases are immutable, root-owned, built off-server (`deploy/build-artifact.sh`), checksummed (SHA256SUMS) and
  switched atomically via the `current` symlink. Python deps install offline from the release wheelhouse.
- Secrets are generated on the server into `/etc/mcnutt-legacy/legacy.env` (0640 root:mcnutt-legacy) and never
  overwritten, printed, or passed as arguments. `VAULT_ROOT_KEY` and `APP_KEY` are not in backups.
- Run `./deploy.sh preflight` (read-only) before any change to deploy scripts and include its output in the PR.
- Lint with `shellcheck -S warning -x -P deploy/server deploy.sh deploy/**/*.sh scripts/*.sh`.
