"""Household cash-flow projection engine, vectorized over simulation paths.

Annual order of operations (documented contract, stable tie-breakers):
  1. opening balances and debts
  2. scheduled external flows by date: income, contributions, RMDs, conversions, expenses, debt service, events
  3. investment return accrual on post-flow invested balances
  4. withdrawals in strategy order and tax settlement (iterated gross-up) back to the reserve floor
  5. closing balances, shortfall and legacy values
Deterministic runs use one path at expected returns; Monte Carlo and stress runs reuse this exact engine.
"""
from __future__ import annotations

import hashlib
import json
from typing import Any, Literal, Optional

import numpy as np
from pydantic import BaseModel, ConfigDict, Field

from .. import ENGINE_VERSION
from ..tax.packs import Pack
from . import ss as ssmod

BUCKETS = ("cash", "taxable", "pretax", "roth", "hsa", "education", "illiquid")
DEFAULT_ORDER = ["cash", "taxable", "pretax", "roth", "hsa"]
MAX_YEARS = 80
MAX_PATHS = 10000


class P(BaseModel):
    model_config = ConfigDict(extra="ignore")


class Pension(P):
    annual: float = 0.0
    start_age: int = 65
    cola: float = 0.0
    survivor_pct: float = 0.0


class Person(P):
    id: str
    name: str = ""
    birth_year: int
    retire_year: int
    death_age: int = 92
    salary: float = 0.0
    salary_growth: float = 0.03
    bonus: float = 0.0
    ss_pia: float = 0.0
    ss_claim_age: float = 67.0
    pension: Optional[Pension] = None


class Account(P):
    id: str
    name: str = ""
    bucket: Literal["cash", "taxable", "pretax", "roth", "hsa", "education", "illiquid"]
    owner: Optional[str] = None
    balance: float = 0.0
    basis: Optional[float] = None
    allocation: dict[str, float] = Field(default_factory=lambda: {"cash": 1.0})
    annual_contribution: float = 0.0
    employer_match: float = 0.0
    contribution_end_year: Optional[int] = None
    liquidity_year: Optional[int] = None


class Liability(P):
    id: str
    name: str = ""
    balance: float
    rate: float
    payment: float
    extra_payment: float = 0.0
    interest_deductible: bool = False


class Property(P):
    id: str
    name: str = ""
    value: float
    appreciation: float = 0.03
    sell_year: Optional[int] = None
    selling_cost_pct: float = 0.06
    basis: float = 0.0
    primary_residence: bool = False
    annual_cost: float = 0.0
    net_rent: float = 0.0
    mortgage_id: Optional[str] = None
    uninsured_loss_year: Optional[int] = None
    uninsured_loss_pct: float = 0.0


class Stream(P):
    label: str
    amount: float
    start_year: int
    end_year: int
    growth: float = 0.0
    tax: Literal["ordinary", "qualified", "none"] = "ordinary"
    owner: Optional[str] = None


class Event(P):
    year: int
    kind: Literal["windfall", "expense", "equity_exit", "inheritance", "property_sale", "education", "gift", "income_loss"]
    amount: float
    tax: Literal["ordinary", "qualified", "none"] = "none"
    label: str = ""
    owner: Optional[str] = None


class Phase(P):
    from_year: int
    multiplier: float


class Expenses(P):
    essential: float
    discretionary: float = 0.0
    healthcare_pre65: float = 0.0
    healthcare_65: float = 0.0
    healthcare_growth: float = 0.05
    phases: list[Phase] = []
    survivor_multiplier: float = 0.75


class AssetClass(P):
    mean: float
    sd: float


class Returns(P):
    classes: dict[str, AssetClass] = Field(default_factory=lambda: {
        "us_equity": AssetClass(mean=0.07, sd=0.16), "intl_equity": AssetClass(mean=0.065, sd=0.18),
        "bonds": AssetClass(mean=0.04, sd=0.06), "cash": AssetClass(mean=0.03, sd=0.01),
        "real_estate": AssetClass(mean=0.055, sd=0.14), "alternatives": AssetClass(mean=0.06, sd=0.12),
    })
    correlation: dict[str, float] = Field(default_factory=lambda: {"us_equity|intl_equity": 0.85, "us_equity|bonds": 0.1,
                                                                   "intl_equity|bonds": 0.1, "us_equity|real_estate": 0.6,
                                                                   "us_equity|alternatives": 0.5})
    history: Optional[dict[str, list[float]]] = None


class Guardrails(P):
    enabled: bool = False
    upper_rate: float = 0.06
    cut: float = 0.10


class Strategy(P):
    withdrawal_order: list[str] = Field(default_factory=lambda: list(DEFAULT_ORDER))
    min_cash: float = 25000.0
    roth_conversion_annual: float = 0.0
    roth_conversion_start: Optional[int] = None
    roth_conversion_end: Optional[int] = None
    guardrails: Guardrails = Guardrails()


class MC(P):
    paths: int = 1000
    seed: int = 20261121
    method: Literal["normal", "bootstrap"] = "normal"
    block: int = 3
    inflation_sd: float = 0.01


class PlanInput(P):
    start_year: int
    end_year: int
    inflation: float = 0.025
    state: Literal["NJ", "NONE"] = "NJ"
    people: list[Person]
    accounts: list[Account] = []
    liabilities: list[Liability] = []
    properties: list[Property] = []
    streams: list[Stream] = []
    events: list[Event] = []
    expenses: Expenses
    returns: Returns = Returns()
    strategy: Strategy = Strategy()
    mc: MC = MC()
    tolerance: float = 1.0
    max_iterations: int = 12


def input_hash(inp: dict[str, Any]) -> str:
    return hashlib.sha256(json.dumps(inp, sort_keys=True, separators=(",", ":"), default=str).encode()).hexdigest()


# ---------------------------------------------------------------- tax approximations (vectorized)

def _progressive(x: np.ndarray, uppers: list[float | None], rates: list[float], scale: np.ndarray) -> np.ndarray:
    tax = np.zeros_like(x)
    lo = np.zeros_like(x)
    for up, r in zip(uppers, rates):
        hi = np.full_like(x, np.inf) if up is None else up * scale
        tax += np.clip(np.minimum(x, hi) - lo, 0, None) * r
        lo = hi
    return tax


class TaxModel:
    """Annual federal + NJ approximation using the rule pack nearest the start year, indexed by inflation."""

    def __init__(self, year: int, state: str):
        py = 2026 if year >= 2026 else 2025
        self.us = Pack("US", py)
        self.nj = Pack("NJ", py) if state == "NJ" else None
        self.pack_year = py
        self.state = state

    def federal(self, ordinary: np.ndarray, qualified: np.ndarray, ss_benefits: np.ndarray, joint: np.ndarray, idx: np.ndarray,
                seniors: np.ndarray) -> tuple[np.ndarray, np.ndarray]:
        out = np.zeros_like(ordinary)
        taxable_ss_all = np.zeros_like(ordinary)
        for is_joint in (True, False):
            m = joint if is_joint else ~joint
            if not m.any():
                continue
            st = "mfj" if is_joint else "single"
            b = self.us.v("brackets", st)
            ups = [None if u is None else float(u) for u, _ in b]
            rates = [float(r) for _, r in b]
            std = float(self.us.by_status("std_deduction", st)) + float(self.us.v("additional_std", "married" if is_joint else "unmarried")) * seniors[m]
            base1 = 32000.0 if is_joint else 25000.0
            base2 = 44000.0 if is_joint else 34000.0
            o, q, s, sc = ordinary[m], qualified[m], ss_benefits[m], idx[m]
            prov = o + q + 0.5 * s
            l9 = np.clip(prov - base1, 0, None)
            l11 = np.clip(l9 - (base2 - base1), 0, None)
            tss = np.minimum(np.minimum(s / 2, np.minimum(l9, base2 - base1) / 2) + 0.85 * l11, 0.85 * s)
            taxable_ss_all[m] = tss
            ti = np.clip(o + tss + q - std * sc, 0, None)
            ord_ti = np.clip(o + tss - std * sc, 0, None)
            qual = np.clip(ti - ord_ti, 0, None)
            t0, t15 = (float(x) for x in self.us.by_status("cg_thresholds", st))
            tax = _progressive(ord_ti, ups, rates, sc)
            z_end = np.clip(t0 * sc - ord_ti, 0, None)
            zero = np.minimum(qual, z_end)
            fif = np.minimum(qual - zero, np.clip(t15 * sc - np.maximum(ord_ti, t0 * sc), 0, None))
            twe = qual - zero - fif
            tax += fif * 0.15 + twe * 0.20
            agi = o + tss + q
            niit_thr = 250000.0 if is_joint else 200000.0
            tax += 0.038 * np.minimum(q, np.clip(agi - niit_thr, 0, None))
            out[m] = tax
        return out, taxable_ss_all

    def state_tax(self, income: np.ndarray, retirement_income: np.ndarray, joint: np.ndarray, age62: np.ndarray, n_people: np.ndarray) -> np.ndarray:
        if self.nj is None:
            return np.zeros_like(income)
        out = np.zeros_like(income)
        for is_joint in (True, False):
            m = joint if is_joint else ~joint
            if not m.any():
                continue
            st = "mfj" if is_joint else "single"
            b = self.nj.v("brackets", st)
            ups = [None if u is None else float(u) for u, _ in b]
            rates = [float(r) for _, r in b]
            inc = income[m]
            max_ex = 100000.0 if is_joint else 75000.0
            pct = np.where(inc <= 100000, 1.0, np.where(inc <= 125000, 0.5, np.where(inc <= 150000, 0.25, 0.0)))
            excl = np.where(age62[m], np.minimum(retirement_income[m], max_ex * pct), 0.0)
            gross = np.clip(inc - excl, 0, None)
            ti = np.clip(gross - 1000.0 * n_people[m], 0, None)
            thr = 20000.0 if is_joint else 10000.0
            out[m] = np.where(gross > thr, _progressive(ti, ups, rates, np.ones_like(ti)), 0.0)
        return out


# ---------------------------------------------------------------- engine

class Engine:
    def __init__(self, p: PlanInput):
        if p.end_year < p.start_year or p.end_year - p.start_year + 1 > MAX_YEARS:
            raise ValueError(f"Horizon must be 1-{MAX_YEARS} years")
        self.p = p
        if not any(a.bucket == "cash" for a in p.accounts):
            p.accounts.append(Account(id="_cash", name="Operating cash (implicit)", bucket="cash", balance=0.0, allocation={"cash": 1.0}))
        self.years = list(range(p.start_year, p.end_year + 1))
        self.classes = sorted({c for a in p.accounts for c in a.allocation} | set(p.returns.classes) | {"cash"})
        self.tax = TaxModel(p.start_year, p.state)
        self.issues: list[dict[str, Any]] = []
        if any(d.interest_deductible for d in p.liabilities):
            self.issues.append({"code": "itemized_not_modeled", "message": "Projection taxes assume the standard deduction; deductible interest is not itemized."})
        for a in p.accounts:
            tot = sum(a.allocation.values())
            if abs(tot - 1.0) > 1e-6:
                self.issues.append({"code": "allocation_sum", "message": f"{a.name or a.id}: allocation sums to {tot:.4f}; normalized."})
            for c in a.allocation:
                if c not in p.returns.classes:
                    self.issues.append({"code": "unknown_class", "message": f"{a.name or a.id}: no return assumption for '{c}'; using cash."})

    # Returns arrays: (paths, years, classes) and inflation (paths, years)
    def deterministic_returns(self, overrides: dict[int, dict[str, float]] | None = None, infl_shift: float = 0.0, ret_shift: float = 0.0):
        R = np.zeros((1, len(self.years), len(self.classes)))
        for k, c in enumerate(self.classes):
            ac = self.p.returns.classes.get(c, self.p.returns.classes.get("cash", AssetClass(mean=0.02, sd=0.0)))
            R[0, :, k] = ac.mean + (ret_shift if c != "cash" else 0.0)
        if overrides:
            for yi, y in enumerate(self.years):
                for c, v in (overrides.get(y) or {}).items():
                    if c in self.classes:
                        R[0, yi, self.classes.index(c)] = v
        I = np.full((1, len(self.years)), self.p.inflation + infl_shift)
        return R, I

    def random_returns(self, paths: int, seed: int):
        rng = np.random.Generator(np.random.PCG64(seed))
        K, Y = len(self.classes), len(self.years)
        if self.p.mc.method == "bootstrap" and self.p.returns.history:
            hist = self.p.returns.history
            n = min(len(v) for v in hist.values())
            H = np.array([[hist.get(c, [self.p.returns.classes.get(c, AssetClass(mean=0.02, sd=0)).mean] * n)[t] for c in self.classes] for t in range(n)])
            R = np.zeros((paths, Y, K))
            b = max(1, self.p.mc.block)
            for path in range(paths):
                t = 0
                while t < Y:
                    s = rng.integers(0, max(1, n - b + 1))
                    seg = H[s:s + b]
                    take = min(b, Y - t, len(seg))
                    R[path, t:t + take] = seg[:take]
                    t += take
        else:
            mu = np.array([self.p.returns.classes.get(c, AssetClass(mean=0.02, sd=0.0)).mean for c in self.classes])
            sd = np.array([self.p.returns.classes.get(c, AssetClass(mean=0.02, sd=0.0)).sd for c in self.classes])
            C = np.eye(K)
            for key, rho in self.p.returns.correlation.items():
                a, b2 = key.split("|")
                if a in self.classes and b2 in self.classes:
                    i, j = self.classes.index(a), self.classes.index(b2)
                    C[i, j] = C[j, i] = rho
            cov = np.outer(sd, sd) * C
            w, v = np.linalg.eigh(cov)
            if (w < -1e-12).any():
                self.issues.append({"code": "correlation_not_psd", "message": "Correlation matrix adjusted to nearest positive semi-definite."})
            L = v @ np.diag(np.sqrt(np.clip(w, 0, None)))
            Z = rng.standard_normal((paths, Y, K))
            R = Z @ L.T + mu
        R = np.clip(R, -0.95, None)
        I = self.p.inflation + rng.standard_normal((paths, Y)) * self.p.mc.inflation_sd
        return R, I

    def simulate(self, R: np.ndarray, I: np.ndarray, detail: bool, mods: dict[str, Any] | None = None) -> dict[str, Any]:
        p = self.p
        mods = mods or {}
        Pn = R.shape[0]
        acc = {a.id: np.full(Pn, a.balance, dtype=float) for a in p.accounts}
        basis = {a.id: np.full(Pn, a.basis if a.basis is not None else a.balance, dtype=float) for a in p.accounts}
        debts = {d.id: np.full(Pn, d.balance, dtype=float) for d in p.liabilities}
        props = {h.id: np.full(Pn, h.value, dtype=float) for h in p.properties}
        sold = {h.id: np.zeros(Pn, dtype=bool) for h in p.properties}
        weights = {a.id: np.array([a.allocation.get(c, 0.0) for c in self.classes]) / max(1e-9, sum(a.allocation.values())) for a in p.accounts}
        price = np.ones(Pn)
        hc_index = np.ones(Pn)
        death_age = {pp.id: mods.get("death_ages", {}).get(pp.id, pp.death_age) for pp in p.people}
        order = [b for b in p.strategy.withdrawal_order if b in BUCKETS and b not in ("education", "illiquid")]
        rows: list[dict[str, Any]] = []
        liquid_track = np.zeros((Pn, len(self.years)))
        nw_track = np.zeros((Pn, len(self.years)))
        short_track = np.zeros((Pn, len(self.years)))
        ess_short_track = np.zeros((Pn, len(self.years)))
        max_resid = 0.0
        discretionary_scale = np.ones(Pn)

        for yi, y in enumerate(self.years):
            infl = I[:, yi]
            alive = {pp.id: (y - pp.birth_year) < death_age[pp.id] for pp in p.people}
            n_alive = sum(alive.values())
            if n_alive == 0:
                liquid_track[:, yi:] = liquid_track[:, max(0, yi - 1):max(1, yi)]
                nw_track[:, yi:] = nw_track[:, max(0, yi - 1):max(1, yi)]
                break
            joint = np.full(Pn, n_alive >= 2)
            opening_total = sum(acc.values()) if acc else np.zeros(Pn)
            cash_flow = np.zeros(Pn)
            ordinary = np.zeros(Pn)
            qualified = np.zeros(Pn)
            nj_income = np.zeros(Pn)
            nj_retire = np.zeros(Pn)
            payroll = np.zeros(Pn)
            ss_total = np.zeros(Pn)
            wages_total = np.zeros(Pn)
            seniors = np.full(Pn, float(sum(1 for pp in p.people if alive[pp.id] and y - pp.birth_year >= 65)))
            age62 = np.full(Pn, any(alive[pp.id] and y - pp.birth_year >= 62 for pp in p.people))
            line: dict[str, Any] = {"year": y}

            # ---- earned income, Social Security, pensions
            ss_own: dict[str, np.ndarray] = {}
            for pp in p.people:
                age = y - pp.birth_year
                claimed = age >= pp.ss_claim_age
                cola = price  # benefits indexed by inflation from start year
                factor = ssmod.claim_factor(pp.birth_year, int(round(pp.ss_claim_age * 12)))
                ss_own[pp.id] = np.where(claimed, pp.ss_pia * 12 * factor * cola, 0.0) if pp.ss_pia else np.zeros(Pn)
            for pp in p.people:
                age = y - pp.birth_year
                if not alive[pp.id]:
                    continue
                if y < pp.retire_year and not (mods.get("income_loss_years") and y in mods["income_loss_years"]):
                    w = (pp.salary + pp.bonus) * (1 + pp.salary_growth) ** (y - p.start_year)
                    wages_total += w
                    base = 184500.0 * price
                    payroll += np.minimum(w, base) * 0.062 + w * 0.0145 + np.clip(w - 250000, 0, None) * 0.009
                benefit = ss_own[pp.id].copy()
                others = [o for o in p.people if o.id != pp.id]
                for o in others:
                    if not alive[o.id]:
                        # Survivor: the larger of own benefit or deceased's benefit (floor 82.5% of PIA if claimed early).
                        dec_age_claim = o.ss_claim_age
                        dec = o.ss_pia * 12 * max(ssmod.claim_factor(o.birth_year, int(round(dec_age_claim * 12))), 0.825) * price
                        if age >= 60:
                            benefit = np.maximum(benefit, dec)
                    elif age >= pp.ss_claim_age and (y - o.birth_year) >= o.ss_claim_age:
                        top = o.ss_pia * 12 * ssmod.spousal_factor(pp.birth_year, int(round(pp.ss_claim_age * 12))) * price - pp.ss_pia * 12 * price
                        benefit = benefit + np.clip(top, 0, None)
                ss_total += benefit
                if pp.pension and age >= pp.pension.start_age:
                    pen = pp.pension.annual * (1 + pp.pension.cola) ** max(0, age - pp.pension.start_age)
                    ordinary += pen
                    nj_income += pen
                    nj_retire += pen
                    cash_flow += pen
            for pp in p.people:
                if not alive[pp.id] and pp.pension and pp.pension.survivor_pct and any(alive.values()):
                    age = y - pp.birth_year
                    if age >= pp.pension.start_age:
                        pen = pp.pension.annual * pp.pension.survivor_pct * (1 + pp.pension.cola) ** max(0, age - pp.pension.start_age)
                        ordinary += pen
                        nj_income += pen
                        nj_retire += pen
                        cash_flow += pen
            cash_flow += wages_total + ss_total
            line["wages"] = wages_total
            line["social_security"] = ss_total

            # ---- contributions while working
            contrib_out = np.zeros(Pn)
            pretax_contrib = np.zeros(Pn)
            for a in p.accounts:
                owner_working = a.owner is None or any(pp.id == a.owner and alive[pp.id] and y < pp.retire_year for pp in p.people)
                if a.annual_contribution and owner_working and (a.contribution_end_year is None or y <= a.contribution_end_year):
                    amt = a.annual_contribution * price
                    acc[a.id] += amt + a.employer_match * price
                    if a.bucket == "taxable":
                        basis[a.id] += amt
                    contrib_out += amt
                    if a.bucket in ("pretax", "hsa"):
                        pretax_contrib += amt
            cash_flow -= contrib_out
            ordinary += wages_total - pretax_contrib
            nj_income += wages_total  # NJ taxes elective deferrals
            line["contributions"] = contrib_out

            # ---- RMDs and Roth conversions
            rmd_total = np.zeros(Pn)
            for a in p.accounts:
                if a.bucket != "pretax" or not a.owner:
                    continue
                owner = next((pp for pp in p.people if pp.id == a.owner), None)
                if owner is None:
                    continue
                age = y - owner.birth_year
                if alive[owner.id] and age >= ssmod.rmd_start_age(owner.birth_year):
                    r = acc[a.id] / ssmod.rmd_divisor(age)
                    acc[a.id] -= r
                    rmd_total += r
            ordinary += rmd_total
            nj_income += rmd_total
            nj_retire += rmd_total
            cash_flow += rmd_total
            line["rmd"] = rmd_total
            conv = np.zeros(Pn)
            st = p.strategy
            if st.roth_conversion_annual and (st.roth_conversion_start or p.start_year) <= y <= (st.roth_conversion_end or p.end_year):
                pre = [a for a in p.accounts if a.bucket == "pretax"]
                roth = next((a for a in p.accounts if a.bucket == "roth"), None)
                remaining = np.full(Pn, st.roth_conversion_annual)
                if roth is not None:
                    for a in pre:
                        take = np.minimum(remaining, acc[a.id])
                        acc[a.id] -= take
                        acc[roth.id] += take
                        conv += take
                        remaining -= take
            ordinary += conv
            nj_income += conv
            line["roth_conversion"] = conv

            # ---- streams, properties, events
            for s in p.streams:
                if s.start_year <= y <= s.end_year and (s.owner is None or alive.get(s.owner, True)):
                    amt = s.amount * (1 + s.growth) ** (y - s.start_year)
                    cash_flow += amt
                    if s.tax == "ordinary":
                        ordinary += amt
                        nj_income += amt
                    elif s.tax == "qualified":
                        qualified += amt
                        nj_income += amt
            prop_cost = np.zeros(Pn)
            for h in p.properties:
                live = ~sold[h.id]
                props[h.id] = np.where(live, props[h.id] * (1 + h.appreciation), 0.0)
                if h.uninsured_loss_year == y or mods.get("property_loss_year") == y and h.primary_residence:
                    pct = h.uninsured_loss_pct or mods.get("property_loss_pct", 0.0)
                    loss = props[h.id] * pct
                    props[h.id] -= loss
                    cash_flow -= np.where(live, loss * 0.5, 0.0)  # half rebuilt from cash
                cash_flow += np.where(live, h.net_rent * price, 0.0)
                prop_cost += np.where(live, h.annual_cost * price, 0.0)
                if h.net_rent:
                    ordinary += np.where(live, h.net_rent * price * 0.5, 0.0)
                    nj_income += np.where(live, h.net_rent * price * 0.5, 0.0)
                if h.sell_year == y:
                    gross = props[h.id] * (1 - h.selling_cost_pct)
                    mort = debts.get(h.mortgage_id, np.zeros(Pn)) if h.mortgage_id else np.zeros(Pn)
                    if h.mortgage_id:
                        debts[h.mortgage_id] = np.zeros(Pn)
                    gain = np.clip(gross - h.basis, 0, None)
                    if h.primary_residence:
                        gain = np.clip(gain - (500000 if n_alive >= 2 else 250000), 0, None)
                    qualified += gain
                    nj_income += gain
                    cash_flow += gross - mort
                    sold[h.id] = np.ones(Pn, dtype=bool)
            ev_expense = np.zeros(Pn)
            for e in p.events:
                if e.year != y and not (e.kind == "equity_exit" and mods.get("exit_delay") and e.year + mods["exit_delay"] == y):
                    continue
                if e.kind == "equity_exit" and (mods.get("no_exit") or (mods.get("exit_delay") and e.year == y)):
                    continue
                if e.kind in ("expense", "education", "gift"):
                    ev_expense += e.amount
                else:
                    cash_flow += e.amount
                    if e.tax == "ordinary":
                        ordinary += e.amount
                        nj_income += e.amount
                    elif e.tax == "qualified":
                        qualified += e.amount
                        nj_income += e.amount
            # ---- education accounts fund education events first
            for a in p.accounts:
                if a.bucket == "education":
                    take = np.minimum(acc[a.id], ev_expense)
                    acc[a.id] -= take
                    ev_expense -= take

            # ---- spending
            mult = 1.0
            for ph in sorted(p.expenses.phases, key=lambda x: x.from_year):
                if y >= ph.from_year:
                    mult = ph.multiplier
            surv = p.expenses.survivor_multiplier if (n_alive < len(p.people) and len(p.people) > 1) else 1.0
            essential = p.expenses.essential * price * mult * surv
            discretionary = p.expenses.discretionary * price * mult * surv * discretionary_scale
            hc = np.zeros(Pn)
            for pp in p.people:
                if alive[pp.id]:
                    hc += np.where(y - pp.birth_year >= 65, p.expenses.healthcare_65, p.expenses.healthcare_pre65) * hc_index
            debt_service = np.zeros(Pn)
            deductible_int = np.zeros(Pn)
            for d in p.liabilities:
                bal = debts[d.id]
                interest = bal * d.rate
                pay = np.minimum(d.payment + d.extra_payment, bal + interest)
                debts[d.id] = np.clip(bal + interest - pay, 0, None)
                debt_service += pay
                if d.interest_deductible:
                    deductible_int += interest
            outflow = essential + discretionary + hc + debt_service + prop_cost + ev_expense + payroll
            cash_flow -= outflow
            line.update({"essential_spending": essential, "discretionary_spending": discretionary, "healthcare": hc,
                         "debt_service": debt_service, "payroll_tax": payroll})

            # ---- returns on invested balances
            gains = {}
            for a in p.accounts:
                r = R[:, yi, :] @ weights[a.id]
                gains[a.id] = acc[a.id] * r
                acc[a.id] = np.clip(acc[a.id] + gains[a.id], 0, None)
            line["investment_return"] = sum(gains.values()) if gains else np.zeros(Pn)

            # ---- settle cash, withdrawals and taxes (iterated gross-up)
            cash_acc = next((a for a in p.accounts if a.bucket == "cash"), None)
            if cash_acc is None:
                cash_bal = np.zeros(Pn)
            else:
                cash_bal = acc[cash_acc.id]
            cash_bal = cash_bal + cash_flow
            pack_idx = price * (1 + p.inflation) ** max(0, p.start_year - self.tax.pack_year)
            n_people = np.full(Pn, float(n_alive))
            withdrawals = {b: np.zeros(Pn) for b in BUCKETS}
            tax = np.zeros(Pn)
            prev_need = np.zeros(Pn)
            converged = True
            snapshot = {a.id: acc[a.id].copy() for a in p.accounts}
            snapshot_basis = {a.id: basis[a.id].copy() for a in p.accounts}
            for it in range(p.max_iterations):
                for a in p.accounts:
                    acc[a.id] = snapshot[a.id].copy()
                    basis[a.id] = snapshot_basis[a.id].copy()
                withdrawals = {b: np.zeros(Pn) for b in BUCKETS}
                ord_w = np.zeros(Pn)
                q_w = np.zeros(Pn)
                need = np.clip(p.strategy.min_cash * price - (cash_bal - tax), 0, None)
                for b in order:
                    if b == "cash":
                        continue
                    for a in p.accounts:
                        if a.bucket != b or (a.liquidity_year and y < a.liquidity_year):
                            continue
                        take = np.minimum(need, acc[a.id])
                        if b == "taxable":
                            frac = np.where(acc[a.id] > 0, np.clip(1 - basis[a.id] / np.maximum(acc[a.id], 1e-9), 0, 1), 0)
                            q_w += take * frac
                            basis[a.id] -= np.where(acc[a.id] > 0, basis[a.id] * take / np.maximum(acc[a.id], 1e-9), 0)
                        elif b == "pretax":
                            ord_w += take
                        acc[a.id] -= take
                        withdrawals[b] += take
                        need -= take
                fed, tss = self.tax.federal(ordinary + ord_w, qualified + q_w, ss_total, joint, pack_idx, seniors)
                stt = self.tax.state_tax(nj_income + ord_w + q_w, nj_retire + ord_w, joint, age62, n_people)
                new_tax = fed + stt
                resid = np.abs(new_tax - tax)
                tax = new_tax
                if float(resid.max(initial=0)) <= p.tolerance:
                    break
            else:
                converged = False
            max_resid = max(max_resid, float(resid.max(initial=0)))
            if not converged:
                self.issues.append({"code": "non_convergence", "year": y, "message": f"Tax/withdrawal iteration did not converge in {y} (residual {max_resid:.2f})."})
            total_withdrawn = sum(withdrawals.values())
            cash_after = cash_bal - tax + total_withdrawn
            shortfall = np.clip(-cash_after, 0, None)
            cash_after = np.clip(cash_after, 0, None)
            if cash_acc is not None:
                acc[cash_acc.id] = cash_after
            ess_short = np.clip(shortfall - discretionary, 0, None)

            # ---- guardrails: cut discretionary next year if withdrawal rate is high
            invested = sum((acc[a.id] for a in p.accounts if a.bucket in ("taxable", "pretax", "roth", "hsa")), np.zeros(Pn))
            if p.strategy.guardrails.enabled:
                rate = np.where(invested > 0, total_withdrawn / np.maximum(invested, 1), 1.0)
                discretionary_scale = np.where(rate > p.strategy.guardrails.upper_rate, discretionary_scale * (1 - p.strategy.guardrails.cut), discretionary_scale)

            liquid = sum((acc[a.id] for a in p.accounts if a.bucket in ("cash", "taxable", "pretax", "roth", "hsa")), np.zeros(Pn))
            illiquid = sum((acc[a.id] for a in p.accounts if a.bucket in ("education", "illiquid")), np.zeros(Pn))
            prop_val = sum(props.values()) if props else np.zeros(Pn)
            debt_val = sum(debts.values()) if debts else np.zeros(Pn)
            nw = liquid + illiquid + prop_val - debt_val
            liquid_track[:, yi] = liquid
            nw_track[:, yi] = nw
            short_track[:, yi] = shortfall
            ess_short_track[:, yi] = ess_short
            if detail:
                pretax_bal = sum((acc[a.id] for a in p.accounts if a.bucket == "pretax"), np.zeros(Pn))
                line.update({
                    "ages": {pp.id: (y - pp.birth_year) if alive[pp.id] else None for pp in p.people},
                    "alive": {pp.id: alive[pp.id] for pp in p.people},
                    "opening_assets": opening_total,
                    "federal_tax": fed, "state_tax": stt, "total_tax": tax, "taxable_social_security": tss,
                    "withdrawals": {b: v for b, v in withdrawals.items() if b not in ("education", "illiquid")},
                    "ending_cash": cash_after, "ending_liquid": liquid, "ending_illiquid": illiquid, "property_value": prop_val,
                    "debt": debt_val, "net_worth": nw, "shortfall": shortfall, "essential_shortfall": ess_short,
                    "buckets": {b: sum((acc[a.id] for a in p.accounts if a.bucket == b), np.zeros(Pn)) for b in BUCKETS},
                    "deflator": price,
                    # Legacy approximation: pretax balances net of a 25% heirs' income tax assumption.
                    "legacy_after_tax": nw - pretax_bal * 0.25,
                })
                rows.append(line)
            price = price * (1 + infl)
            hc_index = hc_index * (1 + p.expenses.healthcare_growth)

        return {"rows": rows, "liquid": liquid_track, "nw": nw_track, "short": short_track, "ess_short": ess_short_track}


def _scalarize(rows: list[dict[str, Any]]) -> list[dict[str, Any]]:
    def conv(v):
        if isinstance(v, np.ndarray):
            return round(float(v[0]), 2)
        if isinstance(v, dict):
            return {k: conv(x) for k, x in v.items()}
        if isinstance(v, (np.floating, float)):
            return round(float(v), 2)
        if isinstance(v, (np.bool_,)):
            return bool(v)
        return v
    return [{k: conv(v) for k, v in r.items()} for r in rows]


def run_deterministic(inp: dict[str, Any]) -> dict[str, Any]:
    p = PlanInput.model_validate(inp)
    eng = Engine(p)
    R, I = eng.deterministic_returns()
    sim = eng.simulate(R, I, detail=True)
    years = _scalarize(sim["rows"])
    # Conservation check: every year's ending liquid + illiquid equals the bucket sum.
    for r in years:
        bsum = round(sum(r["buckets"].values()), 2)
        if abs(bsum - round(r["ending_liquid"] + r["ending_illiquid"], 2)) > 1.0:
            eng.issues.append({"code": "conservation", "year": r["year"], "message": "Bucket totals do not reconcile."})
    first_short = next((r["year"] for r in years if r["shortfall"] > 0.5), None)
    sens = sensitivity(eng)
    last = years[-1] if years else {}
    return {
        "kind": "deterministic", "engine_version": ENGINE_VERSION, "input_hash": input_hash(inp),
        "summary": {
            "ending_net_worth": last.get("net_worth"), "ending_liquid": last.get("ending_liquid"),
            "ending_net_worth_real": round(last.get("net_worth", 0) / last.get("deflator", 1), 2) if last else None,
            "first_shortfall_year": first_short, "total_shortfall": round(sum(r["shortfall"] for r in years), 2),
            "total_taxes": round(sum(r["total_tax"] for r in years), 2), "legacy_after_tax": last.get("legacy_after_tax"),
            "sensitivity": sens,
        },
        "years": years, "issues": eng.issues,
    }


def sensitivity(eng: Engine) -> list[dict[str, Any]]:
    base_R, base_I = eng.deterministic_returns()
    base = eng.simulate(base_R, base_I, detail=False)["nw"][0, -1]
    out = []
    for label, kwargs in (("returns -1%", {"ret_shift": -0.01}), ("returns +1%", {"ret_shift": 0.01}),
                          ("inflation +1%", {"infl_shift": 0.01}), ("inflation -1%", {"infl_shift": -0.01})):
        R, I = eng.deterministic_returns(**kwargs)
        v = eng.simulate(R, I, detail=False)["nw"][0, -1]
        out.append({"driver": label, "ending_net_worth_delta": round(float(v - base), 2)})
    return out


def run_montecarlo(inp: dict[str, Any]) -> dict[str, Any]:
    p = PlanInput.model_validate(inp)
    paths = max(10, min(MAX_PATHS, p.mc.paths))
    eng = Engine(p)
    R, I = eng.random_returns(paths, p.mc.seed)
    sim = eng.simulate(R, I, detail=False)
    pct = [10, 25, 50, 75, 90]
    liquid_p = np.percentile(sim["liquid"], pct, axis=0)
    nw_p = np.percentile(sim["nw"], pct, axis=0)
    any_short = (sim["short"] > 0.5).any(axis=1)
    ess_fail = (sim["ess_short"] > 0.5).any(axis=1)
    first = np.where((sim["short"] > 0.5).any(axis=1), (sim["short"] > 0.5).argmax(axis=1), -1)
    short_years = [eng.years[i] for i in first if i >= 0]
    years = [{"year": y, "liquid": {f"p{q}": round(float(liquid_p[k, i]), 2) for k, q in enumerate(pct)},
              "net_worth": {f"p{q}": round(float(nw_p[k, i]), 2) for k, q in enumerate(pct)},
              "prob_shortfall_by_year": round(float((sim["short"][:, : i + 1] > 0.5).any(axis=1).mean()), 4)}
             for i, y in enumerate(eng.years)]
    return {
        "kind": "montecarlo", "engine_version": ENGINE_VERSION, "input_hash": input_hash(inp),
        "generator": "numpy.PCG64", "seed": p.mc.seed, "paths": paths, "method": p.mc.method,
        "summary": {
            "success_definition": "No year in which liquid resources fail to cover spending, taxes and the cash floor (discretionary spending counted).",
            "probability_success": round(float(1 - any_short.mean()), 4),
            "probability_essential_covered": round(float(1 - ess_fail.mean()), 4),
            "median_first_shortfall_year": int(np.median(short_years)) if short_years else None,
            "median_shortfall_when_failing": round(float(np.median(sim["short"].sum(axis=1)[any_short])), 2) if any_short.any() else 0.0,
            "ending_net_worth": {f"p{q}": round(float(nw_p[k, -1]), 2) for k, q in enumerate(pct)},
            "note": "Probabilities are conditional on the stated return, inflation and correlation assumptions.",
        },
        "years": years, "issues": eng.issues,
    }


STRESS = {
    "early_losses": {"label": "Equity losses of -25% and -10% in the first two years"},
    "high_inflation": {"label": "Inflation +3% for ten years"},
    "income_loss": {"label": "Two years of lost earned income starting next year"},
    "early_death": {"label": "Earlier death of the first participant (age 60 or next year if older)"},
    "late_exit": {"label": "Private equity exit delayed three years"},
    "no_exit": {"label": "No private equity exit"},
    "property_loss": {"label": "Uninsured loss of 30% of the primary residence in year three"},
}


def run_stress(inp: dict[str, Any]) -> dict[str, Any]:
    p = PlanInput.model_validate(inp)
    eng = Engine(p)
    base_R, base_I = eng.deterministic_returns()
    base = eng.simulate(base_R, base_I, detail=False)
    results = []
    for key, meta in STRESS.items():
        R, I, mods = base_R.copy(), base_I.copy(), {}
        if key == "early_losses":
            for c in ("us_equity", "intl_equity", "alternatives"):
                if c in eng.classes:
                    R[0, 0, eng.classes.index(c)] = -0.25
                    if R.shape[1] > 1:
                        R[0, 1, eng.classes.index(c)] = -0.10
        elif key == "high_inflation":
            I[0, :10] += 0.03
        elif key == "income_loss":
            mods["income_loss_years"] = {p.start_year + 1, p.start_year + 2}
        elif key == "early_death" and p.people:
            pp = p.people[0]
            mods["death_ages"] = {pp.id: max(60, p.start_year + 1 - pp.birth_year)}
        elif key == "late_exit":
            if not any(e.kind == "equity_exit" for e in p.events):
                continue
            mods["exit_delay"] = 3
        elif key == "no_exit":
            if not any(e.kind == "equity_exit" for e in p.events):
                continue
            mods["no_exit"] = True
        elif key == "property_loss":
            if not any(h.primary_residence for h in p.properties):
                continue
            mods["property_loss_year"] = p.start_year + 2
            mods["property_loss_pct"] = 0.30
        sim = eng.simulate(R, I, detail=False, mods=mods)
        short = sim["short"][0]
        first = next((eng.years[i] for i, v in enumerate(short) if v > 0.5), None)
        results.append({
            "scenario": key, "label": meta["label"],
            "ending_net_worth": round(float(sim["nw"][0, -1]), 2),
            "delta_vs_base": round(float(sim["nw"][0, -1] - base["nw"][0, -1]), 2),
            "first_shortfall_year": first, "total_shortfall": round(float(short.sum()), 2),
            "min_liquid": round(float(sim["liquid"][0].min()), 2),
        })
    return {"kind": "stress", "engine_version": ENGINE_VERSION, "input_hash": input_hash(inp),
            "summary": {"base_ending_net_worth": round(float(base["nw"][0, -1]), 2), "scenarios": results}, "issues": eng.issues}
