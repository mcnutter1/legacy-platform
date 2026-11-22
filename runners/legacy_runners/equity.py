"""Equity compensation modeling: vesting state, exercise economics and private-company payout waterfall ranges."""
from __future__ import annotations

from datetime import date
from decimal import ROUND_HALF_UP, Decimal
from typing import Any, Literal, Optional

from pydantic import BaseModel, ConfigDict

ZERO = Decimal(0)


def q(v: Decimal) -> Decimal:
    return v.quantize(Decimal("0.01"), rounding=ROUND_HALF_UP)


class Tranche(BaseModel):
    model_config = ConfigDict(extra="ignore")
    vest_date: date
    units: Decimal
    status: str = "scheduled"
    units_exercised: Decimal = ZERO
    condition: str = "time"


class Grant(BaseModel):
    model_config = ConfigDict(extra="ignore")
    id: str
    company: str
    grant_type: str
    units: Decimal
    strike: Decimal = ZERO
    expiration: Optional[date] = None
    tranches: list[Tranche] = []
    participation_threshold: Decimal = ZERO


class Pref(BaseModel):
    model_config = ConfigDict(extra="ignore")
    name: str
    shares: Decimal
    preference: Decimal
    multiple: Decimal = Decimal(1)
    participating: bool = False
    cap_multiple: Optional[Decimal] = None
    seniority: int = 1


class Waterfall(BaseModel):
    model_config = ConfigDict(extra="ignore")
    common_shares: Decimal
    options_outstanding: Decimal = ZERO
    weighted_strike: Decimal = ZERO
    preferred: list[Pref] = []
    debt: Decimal = ZERO
    fees_pct: Decimal = Decimal("0.02")


def state(g: Grant, as_of: date) -> dict[str, str]:
    vested = sum((t.units for t in g.tranches if t.vest_date <= as_of and t.status != "forfeited" and t.condition == "time"), ZERO)
    pending_liq = sum((t.units for t in g.tranches if t.condition in ("liquidity", "double_trigger") and t.status != "forfeited" and t.vest_date <= as_of), ZERO)
    forfeited = sum((t.units for t in g.tranches if t.status == "forfeited"), ZERO)
    exercised = sum((t.units_exercised for t in g.tranches), ZERO)
    expired = g.expiration is not None and g.expiration < as_of
    exercisable = ZERO if expired or g.grant_type not in ("iso", "nso", "sar") else max(ZERO, vested - exercised)
    return {"granted": str(g.units), "vested": str(vested), "vested_pending_liquidity": str(pending_liq), "unvested": str(max(ZERO, g.units - vested - forfeited - pending_liq)),
            "exercisable": str(exercisable), "exercised": str(exercised), "forfeited": str(forfeited), "expired": str(expired).lower()}


def waterfall(w: Waterfall, exit_value: Decimal) -> dict[str, Any]:
    """Pay debt and fees, then preferences by seniority (non-participating convert when better), then common pro rata
    including in-the-money options (treasury-free: strike proceeds added to distributable)."""
    remaining = exit_value * (1 - w.fees_pct) - w.debt
    remaining = max(ZERO, remaining)
    prefs = sorted(w.preferred, key=lambda p: p.seniority)
    payouts: dict[str, Decimal] = {}
    converting: list[Pref] = []
    for _ in range(3):  # iterate conversion decisions to a fixed point
        rem = remaining
        payouts = {}
        for p in prefs:
            if p in converting:
                continue
            pay = min(rem, p.preference * p.multiple)
            payouts[p.name] = pay
            rem -= pay
        fd = w.common_shares + w.options_outstanding + sum((p.shares for p in prefs if p in converting or p.participating), ZERO)
        strike_cash = w.options_outstanding * w.weighted_strike
        per_share = (rem + strike_cash) / fd if fd > 0 else ZERO
        changed = False
        for p in prefs:
            if p.participating or p in converting:
                continue
            if per_share * p.shares > payouts.get(p.name, ZERO):
                converting.append(p)
                changed = True
        if not changed:
            break
    for p in prefs:
        if p.participating:
            extra = per_share * p.shares
            if p.cap_multiple is not None:
                extra = min(extra, max(ZERO, p.preference * p.cap_multiple - payouts.get(p.name, ZERO)))
            payouts[p.name] = payouts.get(p.name, ZERO) + extra
        elif p in converting:
            payouts[p.name] = per_share * p.shares
    return {"exit_value": str(exit_value), "common_per_share": str(q(per_share)), "preferred_payouts": {k: str(q(v)) for k, v in payouts.items()},
            "converting": [p.name for p in converting]}


def run(inp: dict[str, Any]) -> dict[str, Any]:
    as_of = date.fromisoformat(inp.get("as_of") or date.today().isoformat())
    grants = [Grant.model_validate(g) for g in inp.get("grants", [])]
    prices = [Decimal(str(p)) for p in inp.get("price_scenarios", [])]
    ordinary_rate = Decimal(str(inp.get("ordinary_rate", "0.37")))
    cg_rate = Decimal(str(inp.get("ltcg_rate", "0.238")))
    amt_rate = Decimal(str(inp.get("amt_rate", "0.28")))
    wf = Waterfall.model_validate(inp["waterfall"]) if inp.get("waterfall") else None
    exit_values = [Decimal(str(v)) for v in inp.get("exit_values", [])]
    if wf and exit_values:
        prices = [Decimal(waterfall(wf, v)["common_per_share"]) for v in exit_values]
    out = []
    for g in grants:
        st = state(g, as_of)
        vested = Decimal(st["vested"])
        exercisable = Decimal(st["exercisable"])
        scen = []
        for px in prices:
            spread = max(ZERO, px - g.strike)
            if g.grant_type in ("rsu", "psu", "rsa"):
                gross = vested * px
                tax = gross * ordinary_rate
                scen.append({"price": str(px), "gross_value": str(q(gross)), "estimated_tax_at_vest": str(q(tax)), "net_after_tax": str(q(gross - tax))})
            elif g.grant_type == "nso":
                cost = exercisable * g.strike
                ordinary = exercisable * spread
                tax = ordinary * ordinary_rate
                scen.append({"price": str(px), "exercise_cost": str(q(cost)), "ordinary_income": str(q(ordinary)), "estimated_tax": str(q(tax)),
                             "net_cashless": str(q(ordinary - tax)), "sell_to_cover_shares": str(q((cost + tax) / px)) if px > 0 else None})
            elif g.grant_type == "iso":
                cost = exercisable * g.strike
                pref = exercisable * spread
                scen.append({"price": str(px), "exercise_cost": str(q(cost)), "amt_preference": str(q(pref)),
                             "max_amt_exposure": str(q(pref * amt_rate)), "qualifying_sale_tax": str(q(pref * cg_rate)),
                             "disqualifying_sale_tax": str(q(pref * ordinary_rate)),
                             "note": "Qualifying disposition requires holding 2 years from grant and 1 year from exercise; AMT exposure depends on the full return."})
            elif g.grant_type in ("miu", "profits_interest", "carry"):
                value = max(ZERO, px - g.participation_threshold) * vested
                scen.append({"price": str(px), "gross_value": str(q(value)), "estimated_tax": str(q(value * cg_rate)),
                             "note": "Profits-interest treatment requires agreement and classification review."})
            else:
                scen.append({"price": str(px), "gross_value": str(q(vested * spread))})
        out.append({"grant_id": g.id, "company": g.company, "type": g.grant_type, "state": st, "scenarios": scen,
                    "expiring_within_90_days": bool(g.expiration and 0 <= (g.expiration - as_of).days <= 90)})
    result: dict[str, Any] = {"as_of": as_of.isoformat(), "grants": out}
    if wf and exit_values:
        result["waterfall"] = [waterfall(wf, v) for v in exit_values]
    return result
