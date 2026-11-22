# Operations runbook

Host: shared McNutt Cloud server (`ubuntu@login.mcnutt.cloud`, 34.234.75.3). App URL: https://legacy.mcnutt.cloud

## Layout
| Path | Owner | Purpose |
| --- | --- | --- |
| `/opt/mcnutt-legacy/releases/<ts>-<sha>` | root (read-only) | Immutable releases (vendor, public/build, runners/.venv) |
| `/opt/mcnutt-legacy/current` | symlink | Active release |
| `/etc/mcnutt-legacy/legacy.env` | root:mcnutt-legacy 0640 | All secrets/config (APP_KEY, VAULT_ROOT_KEY, DB, SSO, notifications) |
| `/etc/mcnutt-legacy/backup.key` | root 0600 | Backup encryption key |
| `/var/lib/mcnutt-legacy/{storage,vault,jobs}` | mcnutt-legacy | Laravel storage, encrypted vault, job scratch |
| `/var/log/mcnutt-legacy` | mcnutt-legacy | php-fpm, scheduler, backup logs |
| `/var/backups/mcnutt-legacy` | root 0700 | Encrypted backups (35 days) |
| `/etc/php/8.3/fpm/pool.d/mcnutt-legacy.conf` | root | Dedicated pool, socket `/run/php/mcnutt-legacy.sock` |
| `/etc/apache2/sites-available/legacy.mcnutt.cloud.conf` | root | Vhost |
| `/etc/cron.d/mcnutt-legacy` | root | `schedule:run` each minute, backup 02:45, restore drill Sun 04:15 |

## Routine
| Task | Command |
| --- | --- |
| Read-only check | `./deploy.sh preflight` |
| Deploy current commit | `./deploy.sh` (or `scripts/update.sh`) |
| Roll back | `./deploy.sh rollback` |
| Status | `./deploy.sh status` |
| Artisan on server | `cd /opt/mcnutt-legacy/current && sudo -u mcnutt-legacy php artisan <cmd>` |
| Run queued jobs now | `… artisan legacy:work interactive --once` |
| Backup now | `sudo /usr/local/sbin/mcnutt-legacy-backup` |
| Test email delivery | `… artisan legacy:notifications-test you@example.com` (generic message, prints provider status/id) |
| Restore drill | `sudo /usr/local/sbin/mcnutt-legacy-restore-check` |
| Renew/issue TLS | `sudo /usr/local/sbin/mcnutt-legacy-letsencrypt` |
| Rotate vault key | add old key to `VAULT_PREVIOUS_KEYS=id:b64`, set new `VAULT_ROOT_KEY`/`VAULT_ROOT_KEY_ID`, `artisan config:cache`, `artisan legacy:vault-rewrap` |

## First production setup (after first deploy)
1. McNutt Cloud app `legacy` is registered (callback `https://legacy.mcnutt.cloud/auth/callback`, MFA required);
   `AUTH_APP_ID` / `AUTH_APP_SECRET` are set in `legacy.env`.
2. Role mapping (`LEGACY_ROLE_MAP`, default `admin:administrator,family:member,ice:emergency_contact`): on each sign-in
   and revalidation, verified McNutt Cloud roles create or update the member's workspace membership. `admin` may also
   create workspaces (`LEGACY_CREATOR_ROLES`). `guest`/`vault` are unmapped. Family members get only their own private
   scope; joint data still needs an explicit grant. Losing all mapped roles suspends the membership (never the last
   administrator). Changing a member's role in Access pins it so McNutt Cloud no longer overrides it.
   Grant McNutt Cloud app access for `legacy` to each person in the login admin (deny by default).
3. `NOTIFICATIONS_API_TOKEN` is the McNutt Cloud system key `legacy-api-key` (role `notification-sender`); it must also have
   app access to `notifications`, otherwise the API answers 401 `invalid_api_key`. Then
   `sudo -u mcnutt-legacy php artisan config:cache` (from `current`), sign in,
   enroll the authenticator (Settings → Security), create the workspace.
4. Property lookup works without keys (Census geocoder + NJOGIS MOD-IV parcels). Optional AVMs: `RENTCAST_API_KEY`
   (RentCast) and `BRIDGE_ZESTIMATE_TOKEN` (Zillow via Bridge Interactive, requires their data agreement). Set
   `PROPERTY_LOOKUP_ENABLED=false` to disable outbound lookups entirely. AVM results arrive as *proposed* valuations
   that a person must accept.
5. Escrow `legacy.env` + `backup.key` offline (two places). Configure `OFFSITE_RSYNC_TARGET` for offsite copies.
6. Have a CPA review and approve tax rule packs (Tax → Rule packs) before relying on "calculated" status.

## SLOs and signals
p95 authenticated read < 500 ms; jobs visible within one cron cycle; backups daily (RPO 24 h), restore ≤ 4 h (RTO).
Signals: `/health` (masked), Settings → Operations (job counts, oldest queued job, outbox, delivery failures, backup status),
`/var/log/mcnutt-legacy/*.log`, `storage/logs/laravel-*.log` (JSON-safe, correlation ids).

## Incident playbooks

**Compromised session or device** — Access → member → Suspend (ends sessions, revokes OAuth tokens immediately).
Ask the member to sign out everywhere at McNutt Cloud. Review Activity → Audit log for the member; rotate any exported links
(Reports → Shared links → Revoke).

**Revoked/failed integration (SSO, notifications, OpenAI)** — SSO outage fails closed (users see "sign-in unavailable"); nothing
to do but wait/escalate. Notifications: deliveries go `failed`/`delivery_unknown`; fix token, then Settings → Operations → Resend.
OpenAI: set `OPENAI_EXTRACTION_ENABLED=false`, `config:cache`; extraction falls back to the PDF text layer.

**Stale tax rules** — New IRS/NJ guidance: update the pack JSON + `docs/TAX_RULES.md` + fixtures, deploy, and have the reviewer
approve. Old runs keep their pack hashes; re-run affected units.

**Bad import** — Imports → batch → Reverse (archives every transaction created by the batch, audited). Fix the profile/mapping,
re-import; fingerprints prevent duplicates.

**Queue saturation / stuck jobs** — Settings → Operations shows oldest queued job. Check `scheduler.log`; run
`legacy:work interactive --once` manually. Expired leases are recovered automatically with backoff. Cancel runaway jobs
(Activity → My jobs). Per-workspace concurrency is `RUNNER_MAX_PER_WORKSPACE`.

**Provider/host outage** — If the shared host is down: restore the newest backup to any Ubuntu 24.04 host with PHP 8.3 +
MySQL 8 + Python 3.12 using `./deploy.sh` (pointed at the new host via `DEPLOY_HOST`) and the escrowed `legacy.env`,
then import `database.sql` and extract `vault.tar` into `/var/lib/mcnutt-legacy/` (see restore steps below).

**Failed restore drill** — `restore-check` exits non-zero and logs the reason (checksum/HMAC, schema import, unbalanced journal,
missing vault ciphertext). Take a fresh backup, re-run the drill, investigate before the next deploy.

## Full restore (incident action; requires write pause)
1. `./deploy.sh` a release matching the backup's `RELEASE` (or newer) to the target host.
2. Stop the scheduler: comment out the line in `/etc/cron.d/mcnutt-legacy`.
3. Decrypt: verify `.sha256` and `.hmac`, then `openssl enc -d -aes-256-cbc -pbkdf2 -iter 300000 -pass file:/etc/mcnutt-legacy/backup.key -in <file> | tar -xz`.
4. `mysql mcnutt_legacy < database.sql`; `tar -xf vault.tar -C /var/lib/mcnutt-legacy/`; `chown -R mcnutt-legacy: /var/lib/mcnutt-legacy/vault`.
5. Ensure the escrowed `legacy.env` (same `APP_KEY`, `VAULT_ROOT_KEY`) is in place; `artisan config:cache`; re-enable cron.
6. Verify: `/health`, sign in, open a vault document, run a tax forecast.

## Shared-host etiquette
Never restart Apache/PHP-FPM (reload only, after configtest). Never edit other apps' files. Keep `pm.max_children` modest (8)
and runner threads at 1. Check `./deploy.sh preflight` output for collisions before changing deploy scripts.
