"""Tax run entry point: federal + NJ with status, line trace and explicit unsupported gates."""
from __future__ import annotations

from decimal import Decimal
from typing import Any

from .. import ENGINE_VERSION
from . import federal, nj
from .model import TaxInput
from .packs import Pack, PackError

SUPPORTED_YEARS = {2025, 2026}


def _load_packs(t: TaxInput) -> dict[str, Pack]:
    packs = {}
    for j in (["US", "NJ"] if t.state == "NJ" else ["US"]):
        ref = t.packs.get(j)
        packs[j] = Pack(j, t.tax_year, ref.version if ref else None, ref.hash if ref else None, ref.approved if ref else False)
    return packs


def compute(t: TaxInput) -> dict[str, Any]:
    if t.tax_year not in SUPPORTED_YEARS:
        return {"status": "unsupported", "unsupported": [{"code": "year", "message": f"No rule packs for {t.tax_year}."}], "lines": [], "trace": [], "issues": [], "summary": {}}
    try:
        packs = _load_packs(t)
    except PackError as e:
        return {"status": "unsupported", "unsupported": [{"code": "rule_pack", "message": str(e)}], "lines": [], "trace": [], "issues": [], "summary": {}}

    fed = federal.compute(t, packs["US"])
    calcs = [fed["calc"]]
    out_summary: dict[str, Any] = {"federal": fed["summary"]}
    if t.state == "NJ":
        state = nj.compute(t, packs["NJ"], fed)
        calcs.append(state["calc"])
        out_summary["nj"] = state["summary"]

    issues = [i for c in calcs for i in c.issues]
    unsupported = [u for c in calcs for u in c.unsupported]
    for m in t.missing_inputs:
        issues.append({"jurisdiction": "US", "code": "missing_input", "severity": "warning", "message": m})
    errors = [i for i in issues if i.get("severity") == "error"]
    not_approved = [p.jurisdiction for p in packs.values() if not p.approved]
    provisional = [p.jurisdiction for p in packs.values() if p.legislation_status != "enacted"]

    if unsupported:
        status = "unsupported"
    elif t.missing_inputs or errors or not_approved or provisional or t.assumptions:
        status = "estimate"
    else:
        status = "calculated"
    reasons = []
    if not_approved:
        reasons.append("Rule pack(s) pending professional approval: " + ", ".join(not_approved))
    if provisional:
        reasons.append("Provisional legislation in: " + ", ".join(provisional))
    if t.missing_inputs:
        reasons.append(f"{len(t.missing_inputs)} missing input(s)")
    if t.assumptions:
        reasons.append(f"{len(t.assumptions)} assumption(s) in use")
    if errors:
        reasons.append(f"{len(errors)} input error(s)")

    fs = out_summary["federal"]
    combined = {k: str(sum((Decimal(s[k]) for s in out_summary.values()), Decimal(0))) for k in ("total_tax", "balance_due", "refund")}
    return {
        "status": status,
        "status_reasons": reasons,
        "summary": out_summary | {"combined": combined},
        "lines": [ln for c in calcs for ln in c.lines],
        "trace": [dict(tr, jurisdiction=c.jurisdiction) for c in calcs for tr in c.trace],
        "issues": issues,
        "unsupported": unsupported,
        "rentals": fed["rentals"],
        "packs": {k: p.ref() for k, p in packs.items()},
        "engine_version": ENGINE_VERSION,
        "assumptions": t.assumptions,
        "marginal": {"federal": fs["marginal_rate"]},
    }


def run(inp: dict[str, Any]) -> dict[str, Any]:
    return compute(TaxInput.model_validate(inp))
