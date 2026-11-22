"""Debt amortization and payoff strategy comparison with Decimal money and per-period cent rounding."""
from __future__ import annotations

import calendar
from datetime import date
from decimal import ROUND_HALF_UP, Decimal
from typing import Any, Literal, Optional

from pydantic import BaseModel, ConfigDict

CENT = Decimal("0.01")
MAX_PERIODS = 600


def q(v: Decimal) -> Decimal:
    return v.quantize(CENT, rounding=ROUND_HALF_UP)


def add_months(d: date, n: int) -> date:
    m = d.month - 1 + n
    y = d.year + m // 12
    m = m % 12 + 1
    return date(y, m, min(d.day, calendar.monthrange(y, m)[1]))


class RatePeriod(BaseModel):
    model_config = ConfigDict(extra="ignore")
    from_date: date
    rate: Decimal


class Loan(BaseModel):
    model_config = ConfigDict(extra="ignore")
    id: str
    name: str = ""
    balance: Decimal
    rate: Decimal
    payment: Optional[Decimal] = None
    term_months: Optional[int] = None
    first_due: date
    compounding: Literal["monthly", "daily_actual_365", "daily_actual_360", "simple"] = "monthly"
    escrow: Decimal = Decimal(0)
    rate_schedule: list[RatePeriod] = []
    interest_only_months: int = 0
    min_payment_pct: Optional[Decimal] = None
    balloon_date: Optional[date] = None
    extra_monthly: Decimal = Decimal(0)
    lump_sums: dict[str, Decimal] = {}
    tax_deductible_rate: Decimal = Decimal(0)


def level_payment(balance: Decimal, monthly_rate: Decimal, n: int) -> Decimal:
    if n <= 0:
        return balance
    if monthly_rate == 0:
        return q(balance / n)
    f = (1 + monthly_rate) ** n
    return q(balance * monthly_rate * f / (f - 1))


def amortize(loan: Loan, extra_monthly: Decimal | None = None, lump_sums: dict[str, Decimal] | None = None,
             payment_override: Decimal | None = None, start_balance: Decimal | None = None) -> dict[str, Any]:
    bal = q(start_balance if start_balance is not None else loan.balance)
    extra = loan.extra_monthly if extra_monthly is None else extra_monthly
    lumps = {**loan.lump_sums, **(lump_sums or {})}
    payment = payment_override or loan.payment
    if payment is None:
        if not loan.term_months:
            raise ValueError("payment or term_months required")
        payment = level_payment(bal, loan.rate / 12, loan.term_months - loan.interest_only_months)
    rows = []
    due = loan.first_due
    prev = add_months(due, -1)
    total_interest = Decimal(0)
    negative_am = False
    rate = loan.rate
    for n in range(1, MAX_PERIODS + 1):
        for rp in loan.rate_schedule:
            if rp.from_date <= due:
                rate = rp.rate
        if loan.compounding == "monthly" or loan.compounding == "simple":
            interest = q(bal * rate / 12)
        else:
            basis = 365 if loan.compounding == "daily_actual_365" else 360
            interest = q(bal * rate * (due - prev).days / basis)
        if n <= loan.interest_only_months:
            sched = interest
        elif loan.min_payment_pct is not None:
            sched = max(q(bal * loan.min_payment_pct), min(payment, bal + interest))
        else:
            sched = payment
        principal = sched - interest
        if principal < 0:
            negative_am = True
        add = extra + lumps.get(due.isoformat()[:7], Decimal(0))
        principal_total = min(bal, principal + add) if principal + add > 0 else principal
        if loan.term_months and n >= loan.term_months and principal_total > 0 and bal - principal_total < sched:
            # Final scheduled payment absorbs the residual created by rounding the level payment to cents.
            principal_total = bal
        pay = interest + principal_total
        bal = q(bal - principal_total)
        balloon = Decimal(0)
        if loan.balloon_date and due >= loan.balloon_date and bal > 0:
            balloon = bal
            pay += bal
            principal_total += bal
            bal = Decimal(0)
        total_interest += interest
        rows.append({"n": n, "due": due.isoformat(), "rate": str(rate), "payment": str(q(pay)), "interest": str(interest),
                     "principal": str(q(principal_total)), "escrow": str(loan.escrow), "balloon": str(balloon), "balance": str(bal)})
        if bal <= 0:
            break
        prev, due = due, add_months(loan.first_due, n)
    else:
        return {"rows": rows, "paid_off": False, "total_interest": str(q(total_interest)), "negative_amortization": negative_am,
                "warning": "Loan does not amortize within 50 years at this payment."}
    return {"rows": rows, "paid_off": True, "payoff_date": rows[-1]["due"], "months": len(rows), "total_interest": str(q(total_interest)),
            "total_paid": str(q(sum(Decimal(r["payment"]) for r in rows))), "negative_amortization": negative_am, "payment": str(payment)}


def run_amortize(inp: dict[str, Any]) -> dict[str, Any]:
    loan = Loan.model_validate(inp["loan"])
    base = amortize(loan, extra_monthly=Decimal(0), lump_sums={})
    out = {"loan_id": loan.id, "baseline": base}
    if loan.extra_monthly or loan.lump_sums:
        acc = amortize(loan)
        out["with_extra"] = acc
        out["interest_saved"] = str(q(Decimal(base["total_interest"]) - Decimal(acc["total_interest"])))
    if inp.get("statement"):
        st = inp["statement"]
        row = next((r for r in base["rows"] if r["due"][:7] == str(st.get("month"))[:7]), None)
        if row:
            diff = q(Decimal(str(st["balance"])) - Decimal(row["balance"]))
            out["reconciliation"] = {"month": st["month"], "statement_balance": str(st["balance"]), "schedule_balance": row["balance"],
                                     "difference": str(diff), "reconciled": abs(diff) <= Decimal("1.00")}
    return out


def _pool(loans: list[Loan], budget_extra: Decimal, order: list[str]) -> dict[str, Any]:
    """Avalanche/snowball/custom: pay minimums, direct extra (plus freed payments) to the first open loan in order."""
    bal = {l.id: q(l.balance) for l in loans}
    pay = {l.id: (l.payment or level_payment(l.balance, l.rate / 12, l.term_months or 360)) for l in loans}
    interest_total = {l.id: Decimal(0) for l in loans}
    payoff = {}
    month = 0
    start = min(l.first_due for l in loans)
    while any(b > 0 for b in bal.values()) and month < MAX_PERIODS:
        month += 1
        avail = budget_extra
        for l in loans:
            if bal[l.id] <= 0:
                avail += pay[l.id]
                continue
            i = q(bal[l.id] * l.rate / 12)
            interest_total[l.id] += i
            p = min(pay[l.id], bal[l.id] + i)
            avail += pay[l.id] - p
            bal[l.id] = q(bal[l.id] + i - p)
            if bal[l.id] <= 0 and l.id not in payoff:
                payoff[l.id] = add_months(start, month - 1).isoformat()
        for lid in order:
            if avail <= 0:
                break
            if bal[lid] > 0:
                take = min(avail, bal[lid])
                bal[lid] = q(bal[lid] - take)
                avail -= take
                if bal[lid] <= 0 and lid not in payoff:
                    payoff[lid] = add_months(start, month - 1).isoformat()
    return {"months": month, "debt_free": add_months(start, month - 1).isoformat(), "payoff_dates": payoff,
            "total_interest": str(q(sum(interest_total.values()))), "interest_by_loan": {k: str(q(v)) for k, v in interest_total.items()}}


def run_compare(inp: dict[str, Any]) -> dict[str, Any]:
    loans = [Loan.model_validate(x) for x in inp["loans"]]
    extra = Decimal(str(inp.get("extra_monthly", "0")))
    opportunity = Decimal(str(inp.get("investment_return", "0.06")))
    tax_rate = Decimal(str(inp.get("marginal_tax_rate", "0")))
    strategies: dict[str, list[str]] = {
        "minimum_only": [],
        "avalanche": [l.id for l in sorted(loans, key=lambda l: (-l.rate, l.balance, l.id))],
        "snowball": [l.id for l in sorted(loans, key=lambda l: (l.balance, -l.rate, l.id))],
    }
    if inp.get("custom_order"):
        strategies["custom"] = [i for i in inp["custom_order"] if any(l.id == i for l in loans)]
    results = {}
    for name, order in strategies.items():
        r = _pool(loans, Decimal(0) if name == "minimum_only" else extra, order)
        after_tax = sum((Decimal(r["interest_by_loan"][l.id]) * (1 - (tax_rate if l.tax_deductible_rate > 0 else 0)) for l in loans), Decimal(0))
        r["after_tax_interest"] = str(q(after_tax))
        results[name] = r
    base_interest = Decimal(results["minimum_only"]["total_interest"])
    for name, r in results.items():
        r["interest_saved_vs_minimum"] = str(q(base_interest - Decimal(r["total_interest"])))
    # Paydown vs invest: future value of investing the extra instead (after-tax return) over the minimum-only horizon.
    months = results["minimum_only"]["months"]
    mr = opportunity * (1 - tax_rate) / 12
    fv = Decimal(0)
    for _ in range(months):
        fv = fv * (1 + mr) + extra
    results["invest_instead"] = {"future_value": str(q(fv)), "assumed_return": str(opportunity), "months": months,
                                 "note": "Compare with interest saved plus the freed cash flow after payoff; returns are not guaranteed, debt savings are."}
    refi = inp.get("refinance")
    if refi:
        loan = next(l for l in loans if l.id == refi["loan_id"])
        cur = amortize(loan, extra_monthly=Decimal(0), lump_sums={})
        new_loan = loan.model_copy(update={"rate": Decimal(str(refi["rate"])), "term_months": int(refi["term_months"]), "payment": None,
                                           "balance": loan.balance + Decimal(str(refi.get("costs_financed", "0")))})
        new = amortize(new_loan, extra_monthly=Decimal(0), lump_sums={})
        costs = Decimal(str(refi.get("closing_costs", "0")))
        monthly_saving = Decimal(cur["rows"][0]["payment"]) - Decimal(new["rows"][0]["payment"])
        breakeven = int((costs / monthly_saving).to_integral_value(rounding="ROUND_CEILING")) if monthly_saving > 0 else None
        results["refinance"] = {"current_total_interest": cur["total_interest"], "new_total_interest": new["total_interest"],
                                "new_payment": new["rows"][0]["payment"], "monthly_saving": str(q(monthly_saving)), "closing_costs": str(costs),
                                "breakeven_months": breakeven, "lifetime_saving": str(q(Decimal(cur["total_interest"]) - Decimal(new["total_interest"]) - costs))}
    return {"strategies": results, "extra_monthly": str(extra)}
