# McNutt Finance (invoices.mcnutt.cloud) — Data API v1

Read-only, machine-readable export of one business's books from **McNutt Finance**
(`https://invoices.mcnutt.cloud`) for use by McNutt Legacy. This is the "versioned read-only connector with scoped
credential" required by the spec (§4, *invoices.mcnutt.cloud*). Source of truth for the contract:
`docs/openapi.yaml` and `docs/INTEGRATIONS.md` in the invoice repo.

- Base URL: `https://invoices.mcnutt.cloud/api/v1/data`
- Methods: `GET` only — nothing can be changed through this API.
- Format: JSON. Money is a **decimal string** (`"1234.50"`, negatives `"-12.00"`), dates `YYYY-MM-DD`,
  timestamps ISO-8601 UTC (`2026-11-22T14:05:00Z`). IDs are stable strings.
- Scope: **one key = one business.** Connect several businesses with one key each. No database user is ever shared.

---

## 1. Getting a key

1. Sign in to McNutt Finance, open the business → **Settings → Data API access**
   (requires *Edit business settings*; a fresh sign-in is enforced).
2. Name the key (e.g. `McNutt Legacy`), tick the data it may read, optionally set an expiry date, click **Create API key**.
3. Copy the key (`fin_…`) — it is shown **once**. Finance stores only its SHA-256 and a 12-character prefix.
4. Revoke any time from the same screen; revocation is immediate. Creation/revocation is audited; the screen shows
   last use time and IP.

### Scopes

| Scope | Datasets |
|---|---|
| `ledger.read` | `accounts`, `journal-entries`, `cash-events` |
| `invoices.read` | `clients`, `invoices`, `credit-notes` |
| `payments.read` | `payments` |
| `expenses.read` | `vendors`, `expenses` |
| `reports.read` | `reports/profit-and-loss`, `reports/balance-sheet`, `reports/trial-balance`, `reports/tax-estimate` |
| *(any valid key)* | `/` (discovery), `deletions` |

### Authentication

Send the key in either header (prefer `X-API-Key`, which matches Legacy's `api_key` connections):

```http
GET /api/v1/data HTTP/1.1
Host: invoices.mcnutt.cloud
X-API-Key: fin_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
Accept: application/json
```

`Authorization: Bearer fin_…` is also accepted. Never put the key in a URL. Store it encrypted
(`source_connections.credential_encrypted`) and show only the prefix.

---

## 2. Response envelope

Success:

```json
{
  "data": [ … ] ,
  "meta": {
    "business_id": "01JD7…",          // business ULID — use as the connection's external account/entity ref
    "api_version": "1.0",
    "generated_at": "2026-11-22T14:05:00Z",
    "has_more": true,                  // list endpoints only
    "next_cursor": "eyJ1Ijoi…",        // pass back as ?cursor=… ; null on the last page
    "count": 100
  }
}
```

Error:

```json
{ "error": { "code": "forbidden", "message": "This API key does not have the payments.read scope.", "correlation_id": "…" } }
```

| HTTP | `error.code` | Meaning / action |
|---|---|---|
| 401 | `unauthorized` | Missing, invalid, expired or revoked key → set connection `needs_auth` |
| 403 | `forbidden` | Key lacks the dataset's scope → treat the dataset as unsupported for this connection |
| 400 | `invalid_cursor` | Cursor unreadable → restart the dataset without a cursor |
| 422 | `validation_failed` | Bad parameter (e.g. `limit` > 500, missing `from`/`to`) |
| 429 | — | Rate limit (120 requests/minute per source IP) → back off and retry |

Responses carry `Cache-Control: no-store` and `X-Correlation-Id` (log it with sync errors).

---

## 3. Discovery — `GET /`

Call first (e.g. as the connection **Test** step). Returns the business profile, the key's scopes and which datasets
it can read.

```json
{
  "data": {
    "business": {
      "id": "01JD7…", "name": "Acme Consulting", "legal_name": "Acme Consulting LLC", "entity_type": "llc",
      "reporting_basis": "cash", "fiscal_year_start_month": 1, "timezone": "America/New_York",
      "currency": "USD", "books_closed_through": "2026-09-30"
    },
    "key": { "name": "McNutt Legacy", "prefix": "fin_AbCdEfGh", "scopes": ["ledger.read", "invoices.read"], "expires_at": null },
    "datasets": [
      { "path": "/api/v1/data/accounts", "scope": "ledger.read", "description": "Chart of accounts", "available": true },
      …
    ]
  },
  "meta": { … }
}
```

`entity_type` is one of `llc`, `sole_proprietor`, `s_corp`, `c_corp`, `partnership`, `other` (older businesses may
hold free text). `reporting_basis` is the business's default (`cash` or `accrual`).

---

## 4. Paging and incremental sync

All list endpoints accept `limit` (1–500, default 100) and `cursor` (opaque). Keep requesting with
`cursor = meta.next_cursor` until `meta.has_more` is `false`.

**Mutable datasets** (`accounts`, `clients`, `invoices`, `credit-notes`, `payments`, `vendors`, `expenses`) are
ordered by `(updated_at, id)` and accept `updated_since` (ISO-8601). Recommended loop per dataset:

```
since = checkpoint[dataset]            # null on first sync = full backfill
run_started = now()
cursor = null
do:
    page = GET /{dataset}?updated_since={since - 5 min}&limit=500&cursor={cursor}
    upsert each row by (connection_id, dataset, id)
    cursor = page.meta.next_cursor
while page.meta.has_more
checkpoint[dataset] = run_started      # only after the whole loop succeeded (durable staging first)
```

Timestamps have one-second resolution, so always overlap `updated_since` by a few minutes and upsert by `id`
(replays are no-ops).

**Append-only datasets** (`journal-entries`, `cash-events`, `deletions`) are ordered by `id`. Store the last
`next_cursor` (or the last `id` seen) as the checkpoint and resume from it. Journal entries are never edited:
corrections are new reversing entries linked by `reversal_of_id`.

**Deletions.** Voided records stay in their dataset with `status: "void"`. Records an owner *permanently deleted*
(purged) are listed by `GET /deletions?since=…` → archive the matching Legacy rows.

---

## 5. Datasets

All IDs below are strings. Foreign keys (`client_id`, `account_id`, …) refer to IDs from the same business.

### `GET /accounts` — chart of accounts (`ledger.read`)
Params: `updated_since`, `limit`, `cursor`.

```json
{ "id": "12", "code": "4000", "name": "Service Income", "type": "income", "subtype": null,
  "system_key": "service_income", "active": true, "updated_at": "2026-10-29T12:00:00Z" }
```
`type`: `asset | liability | equity | income | expense`. `subtype` includes `bank`, `undeposited`, `cogs`, ….
`system_key` marks built-in accounts (`ar`, `bank`, `sales_tax`, `merchant_fees`, `service_income`, …).

### `GET /journal-entries` — accrual ledger (`ledger.read`, append-only)
Params: `from`, `to` (entry date, inclusive), `limit`, `cursor`.

```json
{
  "id": "345", "date": "2026-03-02", "memo": "Invoice INV-000042 · Acme Widgets", "kind": "auto",
  "source": { "type": "invoice", "id": "42", "key": "invoice:42:issue" },
  "reversal_of_id": null, "currency": "USD", "posted_at": "2026-03-02T15:12:09Z",
  "lines": [
    { "id": "901", "account_id": "3", "account_code": "1200", "debit": "1200.50", "credit": "0.00",
      "description": "Invoice INV-000042", "client_id": "7", "vendor_id": null, "invoice_id": "42" },
    { "id": "902", "account_id": "12", "account_code": "4000", "debit": "0.00", "credit": "1200.50",
      "description": "Invoice INV-000042", "client_id": "7", "vendor_id": null, "invoice_id": "42" }
  ]
}
```
Every entry balances (Σdebit = Σcredit). `kind`: `auto | manual | opening`. `source.key` is unique per business —
use it as provenance (`source_ref`).

### `GET /cash-events` — cash-basis recognition (`ledger.read`, append-only)
Params: `from`, `to`, `limit`, `cursor`.

```json
{ "id": "77", "date": "2026-03-15", "account_id": "12", "account_code": "4000", "amount": "1200.50",
  "source_key": "payment:9:alloc:15", "description": "Payment · INV-000042",
  "journal_entry_id": "350", "invoice_id": "42", "expense_id": null, "recorded_at": "2026-03-15T18:00:00Z" }
```
`amount` is in the account's natural direction (positive = more income on an income account, more expense on an
expense account; negative = reversal). Summing by account over a period equals the cash-basis P&L.

### `GET /clients` (`invoices.read`)
Params: `updated_since`, `limit`, `cursor`.

```json
{ "id": "7", "type": "company", "display_name": "Acme Widgets", "legal_name": "Acme Widgets Inc", "email": "ap@acme.test",
  "billing_address": { "line1": "1 Main St", "city": "Newark", "state": "NJ", "postal_code": "07102" },
  "tax_exempt": false, "status": "active", "external_id": null, "created_at": "…", "updated_at": "…" }
```

### `GET /invoices` (`invoices.read`)
Params: `updated_since`, `include_drafts` (default `false` — drafts have no number and no ledger effect), `limit`, `cursor`.

```json
{
  "id": "42", "ulid": "01JD8…", "number": "INV-000042", "status": "issued", "settlement_status": "paid",
  "client_id": "7", "client_name": "Acme Widgets",
  "issue_date": "2026-03-02", "due_date": "2026-04-01", "service_start": "2026-03-01", "service_end": "2026-03-31",
  "currency": "USD", "po_number": null,
  "subtotal": "1200.50", "discount": "0.00", "tax": "0.00", "total": "1200.50",
  "paid": "1200.50", "credited": "0.00", "written_off": "0.00", "balance": "0.00",
  "recurring_schedule_id": "3", "contract_id": null, "replaces_invoice_id": null, "issue_journal_entry_id": "345",
  "issued_at": "2026-03-02T15:12:09Z", "voided_at": null, "void_reason": null,
  "lines": [
    { "id": "88", "product_id": null, "sku": null, "name": "Consulting", "description": null,
      "quantity": "2", "unit_price": "600.25", "unit": "hour", "income_account_id": "12",
      "amount": "1200.50", "discount": "0.00", "net": "1200.50", "tax": "0.00", "tax_name": null, "tax_rate": "0" }
  ],
  "tax_lines": [],
  "created_at": "…", "updated_at": "…"
}
```
`status`: `draft | issued | void` (void invoices report `balance: "0.00"`; their ledger entry was reversed).
`settlement_status`: `unpaid | partial | paid | written_off` (fully credited invoices show `paid`; check `credited`). `quantity`/`unit_price`/`tax_rate` are exact
decimals (up to 4 places), totals are cents-exact.

### `GET /credit-notes` (`invoices.read`)
Params: `updated_since`, `limit`, `cursor`.

```json
{ "id": "5", "number": "CN-000005", "kind": "credit", "status": "issued", "client_id": "7", "invoice_id": "42",
  "issue_date": "2026-04-10", "reason": "Price adjustment", "subtotal": "100.00", "tax": "0.00", "total": "100.00",
  "journal_entry_id": "410",
  "lines": [ { "description": "Adjustment", "income_account_id": "12", "amount": "100.00", "tax_rate": "0", "tax": "0.00" } ],
  "created_at": "…", "updated_at": "…" }
```
`kind`: `credit | write_off` (write-off = bad debt).

### `GET /payments` (`payments.read`)
Params: `updated_since`, `limit`, `cursor`.

```json
{
  "id": "9", "ulid": "01JD9…", "client_id": "7", "method": "card", "processor": "stripe", "currency": "USD",
  "amount": "1200.50", "fee": "35.11", "net": "1165.39", "received_date": "2026-03-15",
  "status": "cleared", "deposit_account_id": "2", "reference": "pi_3Q…", "external_id": "stripe:pi_3Q…",
  "deposited_date": "2026-03-17", "cleared_date": "2026-03-18",
  "reversed_at": null, "reversal_kind": null,
  "journal_entry_id": "350", "deposit_journal_entry_id": "352",
  "allocations": [
    { "id": "15", "invoice_id": "42", "amount": "1200.50", "applied_date": "2026-03-15", "status": "active", "reversed_at": null }
  ],
  "created_at": "…", "updated_at": "…"
}
```
`method`: `card | ach | zelle | venmo | check | cash | other`. `status`: `recorded | deposited | cleared | reversed`.
`net` = `amount − fee` (the bank payout); `fee` is booked to the *Bank & Merchant Fees* expense account.
Allocations with `status != "active"` were unapplied — ignore them for settlement.

### `GET /vendors` (`expenses.read`)
```json
{ "id": "4", "name": "AWS", "email": null, "status": "active", "default_account_id": "20", "created_at": "…", "updated_at": "…" }
```

### `GET /expenses` (`expenses.read`)
Params: `updated_since`, `limit`, `cursor`.

```json
{
  "id": "61", "kind": "expense", "status": "paid", "vendor_id": "4", "reference": null,
  "expense_date": "2026-06-01", "due_date": null, "description": "Hosting", "currency": "USD",
  "total": "45.10", "paid": "45.10", "payment_account_id": "2", "payment_method": "card",
  "client_id": null, "project_id": null, "tax_treatment": null, "source": "import", "external_id": "monarch:123",
  "journal_entry_id": "500", "voided_at": null,
  "lines": [ { "id": "70", "account_id": "20", "account_code": "6400", "description": null, "amount": "45.10", "billable": false } ],
  "created_at": "…", "updated_at": "…"
}
```
`kind`: `expense` (paid immediately) or `bill` (A/P). `status`: `paid | unpaid | partial | void`.
`external_id` starting `monarch:` came from a Monarch CSV import — use it to avoid double-counting the same card
transaction that Legacy also receives from Monarch.

### `GET /deletions` (any key, append-only)
Params: `since` (ISO-8601), `limit`, `cursor`.

```json
{ "id": "1033", "object_type": "Invoice", "object_id": "57", "action": "invoice.purged", "deleted_at": "2026-11-20T10:00:00Z" }
```

---

## 6. Reports (`reports.read`)

Report endpoints return a single object (no paging). They are computed live from the books.

### `GET /reports/profit-and-loss?from=YYYY-MM-DD&to=YYYY-MM-DD[&basis=cash|accrual][&monthly=1]`
`basis` defaults to the business's `reporting_basis`.

```json
{
  "basis": "cash",
  "periods": [ { "label": "Total", "from": "2026-01-01", "to": "2026-12-31" } ],
  "sections": {
    "income":  [ { "account_id": "12", "code": "4000", "name": "Service Income", "values": ["1200.50"] } ],
    "cogs":    [],
    "expense": [ { "account_id": "20", "code": "6400", "name": "Meals", "values": ["45.10"] } ]
  },
  "totals": [ { "income": "1200.50", "cogs": "0.00", "gross": "1200.50", "expense": "45.10", "net": "1155.40" } ]
}
```
With `monthly=1`, `periods` lists each month (max 24) followed by `Total`; every `values`/`totals` array is aligned
with `periods`.

### `GET /reports/balance-sheet?as_of=YYYY-MM-DD`
```json
{ "as_of": "2026-12-31", "fiscal_year_start": "2026-01-01",
  "sections": { "asset": [ { "account_id": "2", "code": "1000", "name": "Operating Bank", "amount": "15000.00" } ], "liability": [], "equity": [] },
  "retained_earnings": "0.00", "current_earnings": "15000.00",
  "total_assets": "15000.00", "total_liabilities": "0.00", "total_equity": "15000.00", "balanced": true }
```

### `GET /reports/trial-balance?as_of=YYYY-MM-DD`
```json
{ "as_of": "2026-12-31",
  "rows": [ { "account_id": "2", "code": "1000", "name": "Operating Bank", "debit": "15000.00", "credit": "0.00" } ],
  "total_debit": "15000.00", "total_credit": "15000.00" }
```

### `GET /reports/tax-estimate[?year=2026]`
The owner's tax estimate as configured in Finance (Reports → Tax estimate). It is an **estimate** (`meta.estimate: true`)
— store it as a *projected/provisional* value, never as an actual. Legacy's own tax engine remains authoritative.

```json
{
  "year": 2026, "as_of": "2026-11-22", "projecting": true,
  "entity": "smllc", "form": "schedule_c", "state": "NJ", "filing_status": "mfj",
  "net_business_income": "85000.00",
  "summary": {
    "federal": "21000.00", "state": "4200.00", "payroll": "0.00", "total": "25200.00",
    "business_tax": "18000.00", "business_federal": "15000.00", "business_state": "3000.00",
    "paid": "6000.00", "balance": "12000.00",
    "return_total": "25200.00", "return_balance": "3100.00",
    "net_business_income": "85000.00", "effective_rate": 0.2118, …
  },
  "settlement": {
    "federal": { "business": "15000.00", "other": "6000.00", "total": "21000.00", "withholding": "4000.00", "estimates": "5000.00", "balance": "12000.00" },
    "state":   { "business": "3000.00",  "other": "1200.00", "total": "4200.00",  "withholding": "1100.00", "estimates": "1000.00", "balance": "2100.00" }
  },
  "warnings": []
}
```
`business_*` = extra tax caused by this business (return with vs. without it); `balance` = business tax − estimated
payments entered in Finance; `return_*`/`settlement` = the whole return including W-2 income as entered in Finance's
tax settings (`balance` negative = refund). Rates (`effective_rate`, `set_aside_rate`) are plain numbers.

---

## 7. Suggested Legacy mapping

| Finance | Legacy |
|---|---|
| Business (`meta.business_id`) | Entity (business) + `source_connections` row, `provider = 'invoices'`, `auth_kind = 'api_key'` |
| `accounts` | Book / chart-of-accounts mapping per business; map by `system_key`, else `code` |
| `journal-entries` (accrual) | Business book journal entries; `source_ref = source.key`; reversals via `reversal_of_id` |
| `cash-events` | Cash-basis view for tax units on cash method |
| `invoices`, `payments`, `credit-notes` | Earnings evidence / AR; reconcile `payments.net` with bank deposits (`deposited_date`) |
| `expenses` (+ `external_id`) | Business expense evidence; dedupe against Monarch transactions |
| `reports/profit-and-loss` | Reconciliation target for the year close (cash vs accrual totals) |
| `reports/tax-estimate` | Provisional comparison only |

Provenance to record on every imported fact: `provider = invoices`, `connection_id`, dataset, Finance `id`,
`source.key` (journals), `generated_at`, and `api_version`.

Recommended connection flow (mirrors the Monarch/Snowball UX):
1. **Authorize** — paste the `fin_…` key (encrypted at rest; keep the prefix as `credential_hint`).
2. **Test** — `GET /`; show business name, basis and available datasets; reject if `datasets[].available` is false
   for something the mapping needs.
3. **Preview** — first page of `accounts`, `invoices`, `payments`, `expenses` and a current-year P&L.
4. **Approve & sync** — run the loops in §4 on the connection's `sync_interval_hours`; persist checkpoints only after
   durable staging; on 401 set `needs_auth`; on 429/5xx retry with backoff.

Host allow-list for the provider: `invoices.mcnutt.cloud` (HTTPS only, no port, no credentials in the URL).

---

## 8. Quick test

```bash
KEY=fin_…   # from Finance → Settings → Data API access
curl -s -H "X-API-Key: $KEY" https://invoices.mcnutt.cloud/api/v1/data | jq '.data.business'
curl -s -H "X-API-Key: $KEY" "https://invoices.mcnutt.cloud/api/v1/data/invoices?limit=5" | jq '.data[] | {number, total, balance, status}'
curl -s -H "X-API-Key: $KEY" "https://invoices.mcnutt.cloud/api/v1/data/reports/profit-and-loss?from=2026-01-01&to=2026-12-31&basis=cash" | jq '.data.totals'
```

## 9. Not exposed

Internal notes, bank account/routing numbers, card and Stripe secrets, tax IDs, client contacts, payment
instructions, documents/PDFs, user accounts and audit details. Request new datasets in the invoice repo
(`docs/INTEGRATIONS.md`) before relying on them — unsupported means unsupported.
