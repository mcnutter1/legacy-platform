# Beach House PMS — Family Office Data API

Read-only HTTP API exposed by the beach house property-management system (beachdaysalways.com) for the
`property_manager` source connection. It exports bookings, cancellations, refunds, fees, taxes collected,
payments, expenses and IRC 280A day counts for the shore rental. It never returns guest PII (names, emails,
phones, addresses) and cannot modify PMS data.

- **Base URL:** `https://beachdaysalways.com/api/family-office.php` (use the apex domain — `www.` redirects drop headers)
- **Method:** `GET` only
- **API version:** `1` (echoed as `api_version` in every response)
- **Source code:** `api/family-office.php` in the beach-house-pms repo

## 1. Enabling access (PMS side)

1. Run migration `database/migrations/037_family_office_api.sql` on the PMS database.
2. PMS admin → **Settings → Family Office API**:
   - Generate a key. It is shown **once** (only a SHA-256 hash is stored); regenerating invalidates the old key.
   - Add the Legacy server's egress IP to the **IP allowlist** (exact IP or CIDR, matched on `REMOTE_ADDR`).
   - Tick **Enable** and save.
3. The same tab shows recent requests (resource, params, row count, latency, rejections).

## 2. Connecting (Legacy side)

`IntegrationService::PROVIDERS` currently registers only `monarch` and `snowball`. A `property_manager`
provider class (API-key auth, `server_url` = base URL below) must be added before this API can be synced; until
then use the endpoints manually or via the import workbench.

Once the provider exists, create a source connection with `provider = property_manager`, `mode = api`, then set
the credential through the existing key endpoint so it is stored encrypted:

```
POST /api/v1/workspaces/{workspace}/integrations/{connection}/key
{ "key": "fo_…", "server_url": "https://beachdaysalways.com/api/family-office.php" }
```

Never log the key, put it in a URL, or store it outside `credential_encrypted`.

## 3. Authentication

Send the key in a header — either works:

```
Authorization: Bearer fo_<64 hex chars>
X-API-Key: fo_<64 hex chars>
```

A key passed as a query parameter (`api_key`, `token`, `key`) is rejected with `400` and not logged.

| Status | Meaning |
| --- | --- |
| `400` | Key sent in the URL |
| `401` | Missing / invalid key (responds after a ~300 ms delay) |
| `403` | API disabled, or caller IP not allowlisted |
| `404` | Unknown resource, or property missing |
| `405` | Non-GET method |
| `422` | Bad parameters (dates, year, range) |
| `500` | Internal error (details only in PMS logs) |

Errors: `{ "ok": false, "error": "<message>" }`

## 4. Conventions

- Money is a decimal **string** with 2 places (`"1125.00"`), currency `USD`. Negative = money out (refunds).
- Dates are `YYYY-MM-DD`; timestamps are `YYYY-MM-DD HH:MM:SS` (server local time, America/New_York).
- Stays use hotel semantics: `check_in` inclusive, `check_out` exclusive; `nights = check_out − check_in`.
- Every row has a stable `external_id` (`booking:37`, `block:12`, `expense:5`, `payment:3`,
  `submission:9`, `refund:37`). Use it as the idempotency key for upserts / `external_ref`.
- Date windows: pass `year=2026` **or** `from=2026-01-01&to=2026-12-31` (inclusive, max 1100 days).
  Default is the current calendar year.

Success envelope:

```json
{
  "ok": true,
  "api_version": "1",
  "source": "beach_house_pms",
  "resource": "stays",
  "property_id": "property:1",
  "currency": "USD",
  "generated_at": "2026-11-22T10:15:00-05:00",
  "from": "2026-01-01",
  "to": "2026-12-31",
  "count": 4,
  "data": [ … ]
}
```

`from`/`to` appear only for windowed resources; `count` only when `data` is a list.

## 5. Resources

All requests: `GET {base}?resource=<name>&…`

### 5.1 `ping`

Health check. `data: { "pong": true }`. Use it to validate a newly entered key.

### 5.2 `property`

| Field | Type | Legacy `properties` field |
| --- | --- | --- |
| `external_id` | string | `external_ref` |
| `name` | string | `name` |
| `kind` | `"rental_str"` | `kind` |
| `address_line`, `city`, `state`, `postal_code`, `country` | string | same names |
| `living_area_sqft` | int \| null | `living_area_sqft` |
| `bedrooms`, `bathrooms`, `max_guests` | number | — (informational) |
| `base_nightly_rate`, `cleaning_fee`, `security_deposit` | money | — (informational) |
| `status` | `active` \| `inactive` \| `maintenance` | — |
| `updated_at` | timestamp | — |

### 5.3 `stays`

Parameters: `year` | `from`+`to`, optional `updated_since` (ISO-8601), optional `include_pending=1`.

Returns guest bookings overlapping the window plus owner calendar blocks, sorted by `check_in`.

- Default booking statuses: `approved`, `confirmed`, `completed`, `cancelled`.
  `include_pending=1` adds `pending` and `declined`.
- `updated_since` returns only bookings with `updated_at >= value` and **omits calendar blocks**
  (they have no change timestamp) — run a full window pull at least daily to pick up block changes/deletions.
- Rows absent from a full window pull have been deleted in the PMS; treat as removed.

**Guest booking rows** (`kind = "guest"`) → `property_stays`:

| Field | Type | Notes / Legacy mapping |
| --- | --- | --- |
| `external_id` | `booking:<id>` | `source` / dedupe key |
| `reference` | string | PMS booking reference |
| `kind` | `"guest"` | `kind` |
| `check_in`, `check_out` | date | `check_in`, `check_out` |
| `nights` | int | total nights |
| `nights_in_range` | int | nights inside the requested window (use for year splits) |
| `num_guests` | int | |
| `status` | string | PMS status |
| `cancelled` | bool | `cancelled` (status `cancelled`/`declined`) |
| `cancelled_at` | timestamp \| null | |
| `revenue_recognized` | bool | `true` only for `confirmed`/`completed` — matches the PMS P&L |
| `channel` | string | `channel` (`website`, `admin`, …) |
| `gross_rent` | money | `gross_rent` — nightly rent **after** promo discount |
| `promo_discount` | money | informational |
| `cleaning_fee` | money | `cleaning_fee` |
| `taxes_collected` | money | `taxes_collected` (NJ occupancy/sales tax; pass-through, not income) |
| `platform_fee` | money | `platform_fee` — processor/merchant fees linked to the booking |
| `security_deposit` | money | refundable; not income |
| `deposit_kept` | money | deposit retained for damages (income; see `summary.income.damage_recovery`) |
| `deposit_refunded` | money | |
| `refund` | money | `refund` — processed cancellation refund |
| `refund_type` | `none`\|`full`\|`partial`\|`credit`\|`pending`\|null | |
| `credit_issued` | money | future-stay credit instead of cash |
| `total_amount`, `amount_paid` | money | |
| `payout` | money | `payout` = `amount_paid − deposit_refunded − refund − platform_fee` |
| `payout_includes_unsettled_deposit` | bool | `true` → `payout` still contains a deposit that may be returned |
| `completed_at`, `created_at`, `updated_at` | timestamp | |

**Calendar block rows** → `property_stays` (and/or `property_use_logs`):

| Field | Notes |
| --- | --- |
| `external_id` | `block:<id>` |
| `kind` | `personal` (owner/family use), `owner_maintenance`, or `blocked` (other unavailability) |
| `check_in`, `check_out`, `nights`, `nights_in_range` | `check_out` is the day after the last blocked day |
| `cancelled` | always `false` |
| `channel` | `"owner"` |
| `note` | admin-entered reason (free text) |
| `created_at` | timestamp |

The PMS cannot tell family-at-fair-rent or donated stays apart from regular guest bookings. Reclassify those
in Legacy (`family_free`, `family_fair_rent`, `donated`) — Legacy-side overrides should survive re-sync.

### 5.4 `expenses`

Parameters: `year` | `from`+`to` (filters on `incurred_on`). → `property_expenses`.

| Field | Type | Notes / Legacy mapping |
| --- | --- | --- |
| `external_id` | `expense:<id>` | dedupe key |
| `incurred_on` | date | `incurred_on` |
| `amount` | money | `amount` (positive) |
| `category` | string | raw PMS category |
| `suggested_category` | enum | `category` — a `property_expenses` value |
| `treatment` | `expense` \| `undetermined` | `treatment` — `undetermined` needs review |
| `description`, `vendor`, `payment_method` | string | `notes` |
| `is_recurring`, `recurring_frequency` | bool, string | |
| `has_receipt` | bool | receipt image exists in the PMS (not exported) |
| `booking_external_id` | `booking:<id>` \| null | expense tied to a stay (e.g. merchant fee) |
| `import_source`, `import_reference` | string \| null | e.g. `monarch` + Monarch transaction id — **use to dedupe against Monarch imports** |
| `created_at` | timestamp | |

Category mapping (case-insensitive):

| PMS category | `suggested_category` | `treatment` |
| --- | --- | --- |
| Repairs, Plumbing, Electrical, HVAC, Damage / Repairs | `repairs` | expense |
| Maintenance, Landscaping, Home Maintenance & Services | `maintenance` | expense |
| Cleaning | `cleaning` | expense |
| Supplies | `supplies` | expense |
| Insurance | `insurance` | expense |
| Utilities, Gas & Electric, Water, Internet, Phone | `utilities` | expense |
| Marketing, Advertising | `advertising` | expense |
| Merchant Fees | `platform_fees` | expense |
| Software, Management | `management` | expense |
| Property Tax | `property_tax` | expense |
| HOA | `hoa` | expense |
| Travel | `travel` | expense |
| Legal, Accounting | `legal_professional` | expense |
| **Home Improvement** | `other` | **undetermined** — may be a capital improvement (`property_improvements`) |
| **Mortgage** | `other` | **undetermined** — full payment; only interest is deductible (split via `liability_payments`) |
| Security, Other, anything else | `other` | undetermined |

Send `undetermined` rows to the review inbox rather than auto-posting.

### 5.5 `payments`

Parameters: `year` | `from`+`to` (filters on `received_on`). Cash-basis receipts and refunds.

| Field | Notes |
| --- | --- |
| `external_id` | `payment:<id>` (card/processor), `submission:<id>` (Venmo/Zelle/cash/check confirmed by admin), `refund:<booking id>` (cancellation refund) |
| `booking_external_id` | `booking:<id>` |
| `received_on` | date |
| `amount` | money; negative for refunds |
| `currency` | `USD` |
| `method` | `credit_card`, `venmo`, `zelle`, `cash`, `check`, … |
| `type` | `deposit`, `balance`, `final`, `full`, `partial`, `refund`, `security_deposit`, `cancellation_refund` |
| `status` | `completed`, `refunded`, `confirmed` |

Payments include refundable security deposits; reconcile against `stays` rather than treating every payment as rent.
Use these rows to match bank deposits imported from Monarch.

### 5.6 `summary`

Parameter: `year` (default current). One object, not a list. Totals use the same rules as the PMS
Profit & Loss page (revenue by check-in year, `confirmed`/`completed` only).

```json
{
  "year": 2026,
  "basis": "check_in_date",
  "currency": "USD",
  "income": {
    "booking_count": 4,
    "gross_rents": "…", "cleaning_fees": "…", "damage_recovery": "…",
    "gross_revenue": "…",
    "taxes_collected": "…",
    "refunds_paid": "…"
  },
  "expenses": {
    "total": "…", "platform_fees": "…",
    "by_category": [ { "category": "Utilities", "suggested_category": "utilities", "amount": "…", "count": 33 } ]
  },
  "net_operating_income": "…",
  "days": {
    "days_in_year": 365,
    "rental": 0, "personal": 0, "owner_maintenance": 0, "blocked": 0,
    "available_unrented": 0
  }
}
```

- `gross_revenue = gross_rents + cleaning_fees + damage_recovery` (excludes taxes collected and deposits).
- `days.rental` = nights of revenue-recognized guest stays falling in the year (clipped at year boundaries).
- `available_unrented` counts neither as rental nor personal use (Pub. 527), matching `tax/rental.py`.
- Use `summary` for the year-close reconciliation check; post line items from `stays`/`expenses`.

## 6. Recommended sync

| When | Call |
| --- | --- |
| Connect / preview | `ping`, `property`, then `summary` for current and prior year |
| Hourly–daily | `stays?updated_since=<last_success_at minus 1h>` |
| Daily | full `stays`, `expenses`, `payments` for current year (detects deletions + calendar blocks) |
| Year close | full pulls for the closed year + compare to `summary` |

Upsert on `external_id`. Stamp imported rows with `source = "beach_house_pms"`. Treat values as untrusted
input: validate enums, decimals and dates against the Registry before writing.

## 7. Examples

```bash
KEY=fo_…   # from your secret store, never hard-coded
BASE=https://beachdaysalways.com/api/family-office.php

curl -s "$BASE?resource=ping" -H "Authorization: Bearer $KEY"
curl -s "$BASE?resource=summary&year=2026" -H "Authorization: Bearer $KEY"
curl -s "$BASE?resource=stays&from=2026-06-01&to=2026-09-30" -H "X-API-Key: $KEY"
curl -s "$BASE?resource=stays&updated_since=2026-11-01T00:00:00Z" -H "Authorization: Bearer $KEY"
curl -s "$BASE?resource=expenses&year=2026" -H "Authorization: Bearer $KEY"
```

```php
$res = Http::withToken($key)->timeout(15)
    ->get($connection->server_url, ['resource' => 'stays', 'year' => 2026])
    ->throw()->json();
foreach ($res['data'] as $row) { /* upsert by $row['external_id'] */ }
```

## 8. Known limitations

- One property per PMS install (`property:1`); no multi-property pagination.
- No server-side pagination; volumes are small (tens of bookings, low hundreds of expenses per year).
- Until PMS migration 015 is applied in production, cancellation/refund fields (`cancelled_at`,
  `refund`, `refund_type`, `credit_issued`, `cancellation_refund` payments) return `null`/`"0.00"`.
- Receipt images and signed contracts are not exported.
- Deleted calendar blocks and expenses are only detectable by absence from a full window pull.
