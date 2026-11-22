"""CSV import normalization for Monarch, Snowball and generic profiles. Pure transformation: no database access.
Rows are validated, normalized and fingerprinted; Laravel performs dedupe/matching and the canonical commit."""
from __future__ import annotations

import csv
import hashlib
import io
import os
import re
from collections import Counter, defaultdict
from datetime import date, datetime
from decimal import Decimal, InvalidOperation
from typing import Any

MAX_ROWS = 50000
MAX_BYTES = 30 * 1024 * 1024

MONARCH = {"date": "Date", "merchant": "Merchant", "category": "Category", "account": "Account", "original": "Original Statement",
           "notes": "Notes", "amount": "Amount", "tags": "Tags"}
SNOWBALL = {"event": "Event", "date": "Date", "symbol": "Symbol", "price": "Price", "quantity": "Quantity", "currency": "Currency",
            "fee": "FeeTax", "exchange": "Exchange", "accrued": "NKD", "fee_currency": "FeeCurrency", "no_cash": "DoNotAdjustCash",
            "note": "Note", "portfolio": "Portfolio"}
SNOWBALL_EVENTS = {"BUY": "buy", "SELL": "sell", "DIVIDEND": "dividend", "CASH_IN": "contribution", "CASH_OUT": "withdrawal",
                   "CASH_GAIN": "interest", "CASH_EXPENSE": "fee", "SPLIT": "split", "FEE": "fee", "TAX": "tax", "INTEREST": "interest",
                   "REINVEST": "reinvest", "CORPORATE_ACTION": "corporate_action"}
DATE_FORMATS = ["%Y-%m-%d", "%m/%d/%Y", "%m/%d/%y", "%d.%m.%Y", "%Y/%m/%d", "%d/%m/%Y", "%b %d, %Y"]


def parse_decimal(raw: str | None, decimal_sep: str = ".") -> Decimal | None:
    if raw is None:
        return None
    s = str(raw).strip()
    if s == "":
        return None
    neg = s.startswith("(") and s.endswith(")")
    s = s.strip("()").replace("$", "").replace("€", "").replace("£", "").replace(" ", "")
    if decimal_sep == ",":
        s = s.replace(".", "").replace(",", ".")
    else:
        s = s.replace(",", "")
    if s.endswith("-"):
        neg, s = True, s[:-1]
    try:
        v = Decimal(s)
    except InvalidOperation:
        return None
    return -v if neg else v


def parse_date(raw: str | None, fmt: str | None = None) -> date | None:
    if not raw:
        return None
    s = str(raw).strip()[:32]
    for f in ([fmt] if fmt else []) + DATE_FORMATS:
        try:
            return datetime.strptime(s, f).date()
        except (ValueError, TypeError):
            continue
    try:
        return datetime.fromisoformat(s.replace("Z", "+00:00")).date()
    except ValueError:
        return None


def norm_text(s: str | None) -> str:
    return re.sub(r"\s+", " ", (s or "").strip().lower())


def fingerprint(*parts: Any) -> str:
    return hashlib.sha256("|".join(str(p) for p in parts).encode()).hexdigest()


def _read(inp: dict[str, Any]) -> str:
    path = inp.get("file_path")
    if path:
        jobdir = os.environ.get("LEGACY_JOB_DIR", "")
        real = os.path.realpath(path)
        if not jobdir or not real.startswith(os.path.realpath(jobdir) + os.sep):
            raise ValueError("file path outside job directory")
        if os.path.getsize(real) > MAX_BYTES:
            raise ValueError("file too large")
        with open(real, "rb") as fh:
            data = fh.read()
    else:
        data = (inp.get("content") or "").encode()
    for enc in ("utf-8-sig", "utf-16", "cp1252", "latin-1"):
        try:
            return data.decode(enc)
        except UnicodeDecodeError:
            continue
    raise ValueError("unsupported encoding")


def run(inp: dict[str, Any]) -> dict[str, Any]:
    provider = inp.get("provider", "generic_csv")
    profile = inp.get("profile") or {}
    text = _read(inp)
    sample = text[:4096]
    try:
        dialect = csv.Sniffer().sniff(sample, delimiters=",;\t|")
    except csv.Error:
        dialect = csv.excel
    reader = csv.DictReader(io.StringIO(text), dialect=dialect)
    headers = [h.strip() for h in (reader.fieldnames or [])]
    reader.fieldnames = headers
    mapping = profile.get("mapping") or (MONARCH if provider == "monarch" else SNOWBALL if provider == "snowball" else {})
    expected = set(v for v in mapping.values() if v)
    missing = sorted(c for c in expected if c not in headers and c not in ("Portfolio", "Tags", "Notes", "Original Statement", "NKD", "FeeCurrency", "DoNotAdjustCash", "Exchange", "Note"))
    extra = sorted(h for h in headers if h not in expected)
    drift = {"missing_columns": missing, "unexpected_columns": extra}
    if missing:
        return {"ok_schema": False, "schema_drift": drift, "headers": headers, "rows": [], "stats": {"rows": 0},
                "suggested_mapping": suggest_mapping(headers, provider)}

    invert = bool(profile.get("invert_sign", False))
    date_fmt = profile.get("date_format")
    dec_sep = profile.get("decimal_separator", ".")
    default_currency = profile.get("currency", "USD")
    account_map = profile.get("account_map") or {}
    category_map = profile.get("category_map") or {}
    include_accounts = set(profile.get("include_accounts") or [])
    rows: list[dict[str, Any]] = []
    seen: Counter = Counter()
    totals: dict[str, Decimal] = defaultdict(Decimal)
    dates: list[date] = []
    for i, raw in enumerate(reader, start=1):
        if i > MAX_ROWS:
            return {"ok_schema": True, "error": f"more than {MAX_ROWS} rows; split the file", "rows": [], "stats": {"rows": i}}
        raw = {k: (v.strip() if isinstance(v, str) else v) for k, v in raw.items() if k}
        if not any(raw.values()):
            continue
        issues: list[str] = []
        get = lambda key: raw.get(mapping.get(key, ""), None)  # noqa: E731
        d = parse_date(get("date"), date_fmt)
        if d is None:
            issues.append("unparseable_date")
        elif d > date.today().replace(year=date.today().year + 1):
            issues.append("future_date")
        if provider == "snowball":
            event = (get("event") or "").upper().strip()
            kind = SNOWBALL_EVENTS.get(event)
            if not kind:
                issues.append(f"unknown_event:{event[:20]}")
            qty = parse_decimal(get("quantity"), dec_sep)
            price = parse_decimal(get("price"), dec_sep)
            fee = parse_decimal(get("fee"), dec_sep) or Decimal(0)
            symbol = (get("symbol") or "").upper()[:32]
            currency = (get("currency") or default_currency).upper()[:3]
            amount = None
            if kind in ("buy", "sell", "reinvest") and qty is not None and price is not None:
                amount = (qty * price) * (-1 if kind in ("buy", "reinvest") else 1) - fee
            elif kind in ("dividend", "interest", "contribution"):
                amount = (price if qty in (None, Decimal(0)) else (qty * price if price is not None else qty)) if price is not None or qty is not None else None
                if amount is not None:
                    amount = abs(amount) - fee
            elif kind in ("withdrawal", "fee", "tax"):
                v = price if price is not None else qty
                amount = -abs(v) if v is not None else None
            elif kind == "split":
                amount = Decimal(0)
            if amount is None and kind not in ("split", "corporate_action"):
                issues.append("missing_amount")
            if kind in ("buy", "sell") and (qty is None or qty <= 0):
                issues.append("invalid_quantity")
            account_label = get("portfolio") or profile.get("default_account_label") or "Snowball"
            norm = {"posted_on": d.isoformat() if d else None, "amount": str(amount) if amount is not None else None, "currency": currency,
                    "kind": kind or "other", "symbol": symbol or None, "quantity": str(qty) if qty is not None else None,
                    "price": str(price) if price is not None else None, "fees": str(fee), "description": f"{event} {symbol}".strip(),
                    "notes": (get("note") or "")[:500] or None, "account_label": account_label,
                    "adjust_cash": (get("no_cash") or "").lower() not in ("true", "1", "yes")}
        else:
            amount = parse_decimal(get("amount"), dec_sep)
            if amount is None:
                issues.append("missing_amount")
            elif invert:
                amount = -amount
            category = (get("category") or "").strip()
            mapped_cat = category_map.get(category, category)
            account_label = (get("account") or profile.get("default_account_label") or "").strip()
            tags = [t.strip() for t in re.split(r"[,;]", get("tags") or "") if t.strip()]
            kind = "transfer" if norm_text(category) in ("transfer", "credit card payment", "transfers") else ("income" if amount is not None and amount > 0 else "spend")
            norm = {"posted_on": d.isoformat() if d else None, "amount": str(amount) if amount is not None else None,
                    "currency": (get("currency") or default_currency).upper()[:3], "kind": kind,
                    "description": (get("original") or get("merchant") or get("description") or "")[:255],
                    "merchant": (get("merchant") or "")[:255] or None, "category": mapped_cat[:96] or None, "tags": tags,
                    "notes": (get("notes") or "")[:2000] or None, "account_label": account_label}
        if include_accounts and norm.get("account_label") not in include_accounts:
            continue
        acct = account_map.get(norm.get("account_label") or "", None)
        norm["account_id"] = acct
        if not acct:
            issues.append("unmapped_account")
        base = fingerprint(norm.get("account_label"), norm.get("posted_on"), norm.get("amount"), norm.get("currency"),
                           norm_text(norm.get("description")), norm.get("symbol"), norm.get("quantity"))
        seen[base] += 1
        # Identical rows in one file are distinct events; the occurrence index keeps their fingerprints stable across re-imports.
        fp = fingerprint(base, seen[base])
        if d:
            dates.append(d)
        if norm.get("amount") and acct:
            totals[acct] += Decimal(norm["amount"])
        rows.append({"row_no": i, "external_id": None, "fingerprint": fp, "normalized": norm, "issues": issues,
                     "state": "needs_review" if issues else "mapped"})

    coverage = {"from": min(dates).isoformat() if dates else None, "to": max(dates).isoformat() if dates else None,
                "accounts": sorted({r["normalized"].get("account_label") or "" for r in rows}),
                "totals_by_account": {k: str(v) for k, v in totals.items()}}
    stats = {"rows": len(rows), "needs_review": sum(1 for r in rows if r["issues"]), "mapped": sum(1 for r in rows if not r["issues"]),
             "issue_counts": dict(Counter(i.split(":")[0] for r in rows for i in r["issues"]))}
    return {"ok_schema": True, "schema_drift": drift, "headers": headers, "rows": rows, "stats": stats, "coverage": coverage}


KEYWORDS = {
    "date": ["date", "posted", "transaction date", "trade date"], "amount": ["amount", "value", "net"], "description": ["description", "memo", "payee", "original"],
    "merchant": ["merchant", "payee", "name"], "category": ["category", "type"], "account": ["account", "portfolio"],
    "symbol": ["symbol", "ticker"], "quantity": ["quantity", "shares", "units"], "price": ["price"], "currency": ["currency", "ccy"],
    "fee": ["fee", "commission"], "notes": ["note", "notes", "comment"], "tags": ["tags", "labels"],
}


def suggest_mapping(headers: list[str], provider: str) -> list[dict[str, Any]]:
    """Header-name heuristics with a reason and confidence. Proposals require approval before unattended use."""
    out = []
    for field, words in KEYWORDS.items():
        best, score = None, 0.0
        for h in headers:
            hn = norm_text(h)
            for w in words:
                s = 1.0 if hn == w else (0.7 if w in hn else 0.0)
                if s > score:
                    best, score = h, s
        if best:
            out.append({"field": field, "column": best, "confidence": score, "reason": f"Header '{best}' matches '{field}'"})
    return out
