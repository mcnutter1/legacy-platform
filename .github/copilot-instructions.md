# Copilot instructions — McNutt Legacy

Follow [AGENTS.md](../AGENTS.md). Summary of what matters most when generating code here:

- Laravel 12 / PHP 8.3 strict types in spirit; services are `final` and receive an `AccessContext`. Controllers stay thin.
- New record types are added to `app/Resources/Registry.php` (field DSL: `s` string, `t` text, `m` money, `d` date,
  `e:a|b` enum, `ref:table`, `j` json, `~` read-only, `!` required) plus a migration using the schema macros
  (`$t->root()`, `$t->wsRef()`, `$t->tracking()`). Then run `php artisan legacy:openapi`.
- All API errors use `App\Support\ApiError` → `{ "error": { "code", "message", "details", "correlation_id" } }`.
- Mutations require `Idempotency-Key`; updates require `If-Match`. Privileged actions call `$this->privileged()` (fresh SSO + TOTP step-up).
- Heavy or untrusted work → enqueue a job type in `JobService::HANDLERS` with a handler implementing `JobHandler`
  (`authorization`, `runnerType`, `prepare`, `commit`). Python output is validated before commit inside a transaction.
- Python runners: pydantic models with `extra="forbid"`, `Decimal` money, no network, no DB, no filesystem outside the job dir.
- React: use hooks in `resources/js/api/hooks.ts` (`useWsQuery`, `useResourceList`, `useCommand`, `useJob`) and components in
  `resources/js/components`. Charts always have a table alternative (`ChartFrame`). Keep pages accessible (labels, roles, keyboard).
- Never use real names, SSNs, account numbers or addresses in examples or tests.
- Tests: PHP feature tests use `Tests\TestCase` helpers (`makeUser`, `makeWorkspace`, `signIn`, `send`, `wsUrl`).
