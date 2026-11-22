# McNutt Legacy

Private family-office platform for the McNutt family at **https://legacy.mcnutt.cloud**: one place for net worth,
accounts and investments, real estate, debts, payroll and equity compensation, federal + New Jersey tax, retirement
and goal planning, estate and insurance, governance, an encrypted document vault, reports, and a ChatGPT (MCP)
connector — with deny-by-default privacy between family members.

| Layer | Technology |
| --- | --- |
| Web/API | Laravel 12 (PHP 8.3), MySQL 8, session auth via McNutt Cloud SSO + TOTP step-up |
| UI | React 19 + TypeScript + React Router 7 + TanStack Query + Tailwind 4 (Vite) |
| Calculators | Python 3.12 package `legacy_runners` (tax, planning/Monte Carlo, debt, estate, equity, imports, documents) |
| Jobs | Leased `compute_jobs` executed by bounded cron workers (`legacy:work`), no daemons |
| Hosting | Shared Ubuntu server: Apache + dedicated PHP-FPM pool + dedicated system user |

## Quick start (local)

```bash
export PATH="$HOME/.local/bin:$PATH"          # composer wrapper
composer install && npm install
python3.12 -m venv runners/.venv && runners/.venv/bin/pip install -e "runners[test]"
cp .env.example .env    # then: AUTH_DRIVER=dev, DB_CONNECTION=sqlite, STEPUP_REQUIRED=false,
                        # LEGACY_WORKSPACE_CREATORS=*, VAULT_ROOT_KEY=$(openssl rand -base64 32),
                        # RUNNER_PYTHON=$PWD/runners/.venv/bin/python, NOTIFICATIONS_DRIVER=log
php artisan key:generate && touch database/database.sqlite && php artisan migrate
php artisan legacy:packs-sync && php artisan legacy:demo dev-admin   # fictional "Example" family
npm run build && php artisan serve                                     # http://127.0.0.1:8000/auth/dev-login
php artisan legacy:work interactive --once                             # run queued calculations
```

## Checks

```bash
php artisan test                         # PHP feature/unit tests (auth, isolation, idempotency, OAuth/MCP, vault, imports, jobs)
(cd runners && .venv/bin/python -m pytest -q)   # calculator golden tests
npm run typecheck && npm test            # TypeScript + Vitest
php artisan legacy:openapi --check       # API contract + generated TS types are current
```

## Deploy

```bash
./deploy.sh preflight     # read-only server checks: what would be created, collisions, DNS/TLS
./deploy.sh               # build artifact (tests, vendor, Vite, linux wheels) -> upload -> install -> switch
./deploy.sh rollback      # previous release (schema is additive; no down-migrations)
./deploy.sh status
```

See [docs/OPERATIONS.md](docs/OPERATIONS.md) for the runbook, [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) for the
design, [docs/SECURITY.md](docs/SECURITY.md) for the threat model and [AGENTS.md](AGENTS.md) for contributor rules.
