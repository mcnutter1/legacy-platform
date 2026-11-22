"""Estate distribution modeling: title, contract beneficiaries, trusts and probate per death order, with federal
estate tax (IRC 2001/2010), portability and NJ transfer inheritance tax. Produces counsel workpapers, not legal findings."""
from __future__ import annotations

from collections import defaultdict
from decimal import ROUND_HALF_UP, Decimal
from typing import Any, Literal, Optional

from pydantic import BaseModel, ConfigDict

from . import ENGINE_VERSION

ZERO = Decimal(0)

# IRC 2001(c) rate schedule.
RATE_TABLE = [(Decimal(10000), Decimal("0.18")), (Decimal(20000), Decimal("0.20")), (Decimal(40000), Decimal("0.22")), (Decimal(60000), Decimal("0.24")),
              (Decimal(80000), Decimal("0.26")), (Decimal(100000), Decimal("0.28")), (Decimal(150000), Decimal("0.30")), (Decimal(250000), Decimal("0.32")),
              (Decimal(500000), Decimal("0.34")), (Decimal(750000), Decimal("0.37")), (Decimal(1000000), Decimal("0.39")), (None, Decimal("0.40"))]

CLASS_A = {"spouse", "domestic_partner", "child", "stepchild", "grandchild", "parent", "grandparent"}
CLASS_C = {"sibling", "in_law"}


def q(v: Decimal) -> Decimal:
    return v.quantize(Decimal("0.01"), rounding=ROUND_HALF_UP)


def tentative_tax(x: Decimal) -> Decimal:
    tax, lo = ZERO, ZERO
    for hi, r in RATE_TABLE:
        if x <= lo:
            break
        top = x if hi is None else min(x, hi)
        tax += (top - lo) * r
        lo = hi if hi is not None else lo
    return tax


class M(BaseModel):
    model_config = ConfigDict(extra="ignore")


class Person(M):
    id: str
    name: str
    is_us_citizen: bool = True
    domicile: str = "NJ"
    age: Optional[int] = None


class Relation(M):
    from_id: str
    to_id: str
    kind: str


class Owner(M):
    type: Literal["person", "entity"]
    id: str
    fraction: Decimal


class Beneficiary(M):
    tier: Literal["primary", "contingent", "remainder"] = "primary"
    type: Literal["person", "entity", "class", "estate", "charity"]
    id: Optional[str] = None
    label: str = ""
    share: Decimal
    per_stirpes: bool = False


class Asset(M):
    id: str
    label: str
    kind: str
    value: Decimal
    basis: Decimal = ZERO
    debt: Decimal = ZERO
    title_form: Literal["sole", "jtwros", "tenancy_by_entirety", "tenants_in_common", "community", "trust", "entity"] = "sole"
    owners: list[Owner] = []
    beneficiaries: list[Beneficiary] = []
    life_insurance: Optional[dict[str, Any]] = None
    retirement: bool = False
    liquid: bool = True
    will_specific_gift_to: Optional[str] = None


class Trust(M):
    id: str
    name: str
    settlor_ids: list[str] = []
    revocable: bool = True
    beneficiaries: list[Beneficiary] = []
    age_schedule: list[dict[str, Any]] = []
    marital: bool = False


class Will(M):
    person_id: str
    residuary: list[Beneficiary] = []
    pour_over_trust_id: Optional[str] = None
    executed: bool = True


class EstateInput(M):
    year: int
    death_order: list[str]
    simultaneous: bool = False
    persons: list[Person]
    relations: list[Relation] = []
    assets: list[Asset]
    trusts: list[Trust] = []
    wills: list[Will] = []
    debts: dict[str, Decimal] = {}
    funeral: Decimal = Decimal(15000)
    admin_pct: Decimal = Decimal("0.03")
    lifetime_taxable_gifts: dict[str, Decimal] = {}
    exemption: Decimal = Decimal(15000000)
    elect_portability: bool = True
    nj_inheritance: bool = True
    nj_class_c: list = [["25000", "0"], ["1100000", "0.11"], ["1400000", "0.13"], ["1700000", "0.14"], [None, "0.16"]]
    nj_class_d: list = [["700000", "0.15"], [None, "0.16"]]


def _bracket(amount: Decimal, table: list) -> Decimal:
    tax, lo = ZERO, ZERO
    for hi, r in table:
        h = Decimal(str(hi)) if hi is not None else None
        if amount <= lo:
            break
        top = amount if h is None else min(amount, h)
        tax += (top - lo) * Decimal(str(r))
        if h is None:
            break
        lo = h
    return tax


class Estate:
    def __init__(self, e: EstateInput):
        self.e = e
        self.people = {p.id: p for p in e.persons}
        self.alive = {p.id: True for p in e.persons}
        self.unresolved: list[dict[str, Any]] = []
        self.flows: list[dict[str, Any]] = []
        self.received: dict[str, Decimal] = defaultdict(lambda: ZERO)
        self.trust_assets: dict[str, Decimal] = defaultdict(lambda: ZERO)
        self.holdings: dict[str, dict[str, Decimal]] = defaultdict(lambda: defaultdict(lambda: ZERO))  # person -> asset_id -> value
        self.dsue = ZERO
        self.trusts = {t.id: t for t in e.trusts}
        self.wills = {w.person_id: w for w in e.wills}
        rel = defaultdict(dict)
        for r in e.relations:
            rel[r.from_id][r.to_id] = r.kind
        self.rel = rel

    def relation(self, decedent: str, beneficiary: str) -> str:
        return self.rel.get(decedent, {}).get(beneficiary) or self.rel.get(beneficiary, {}).get(decedent, "other")

    def spouse_of(self, pid: str) -> Optional[str]:
        for other, kind in self.rel.get(pid, {}).items():
            if kind in ("spouse", "domestic_partner"):
                return other
        for other, m in self.rel.items():
            if m.get(pid) in ("spouse", "domestic_partner"):
                return other
        return None

    def check(self) -> None:
        for a in self.e.assets:
            for tier in ("primary", "contingent"):
                shares = [b.share for b in a.beneficiaries if b.tier == tier]
                if shares and abs(sum(shares) - 1) > Decimal("0.0001"):
                    self.unresolved.append({"code": "beneficiary_shares", "asset": a.id, "message": f"{a.label}: {tier} beneficiary shares total {sum(shares)}."})
            for b in a.beneficiaries:
                p = self.people.get(b.id or "")
                if p and p.age is not None and p.age < 18 and b.type == "person":
                    self.unresolved.append({"code": "minor_beneficiary", "asset": a.id, "message": f"{a.label}: minor {p.name} named directly; consider a trust or UTMA custodian."})
            if a.will_specific_gift_to and a.title_form in ("jtwros", "tenancy_by_entirety") or (a.will_specific_gift_to and a.beneficiaries):
                self.unresolved.append({"code": "title_vs_will", "asset": a.id, "message": f"{a.label}: will gift conflicts with title/beneficiary designation; title/contract controls."})
            if a.title_form == "trust" and not any(o.type == "entity" for o in a.owners):
                self.unresolved.append({"code": "trust_title_missing", "asset": a.id, "message": f"{a.label}: titled to a trust but no trust owner recorded."})
        for t in self.e.trusts:
            funded = any(o.type == "entity" and o.id == t.id for a in self.e.assets for o in a.owners)
            poured = any(w.pour_over_trust_id == t.id for w in self.e.wills)
            if not funded and not poured:
                self.unresolved.append({"code": "unfunded_trust", "trust": t.id, "message": f"{t.name} holds no recorded assets and no will pours over to it."})
            elif not funded:
                self.unresolved.append({"code": "trust_funded_by_probate", "trust": t.id, "message": f"{t.name} is funded only through probate via a pour-over will."})
        for pid in self.e.death_order:
            if pid not in self.wills:
                self.unresolved.append({"code": "no_will", "person": pid, "message": f"No executed will recorded for {self.people.get(pid, Person(id=pid, name=pid)).name}; intestacy is not modeled."})

    def give(self, decedent: str, recipient: dict[str, Any], amount: Decimal, mechanism: str, asset: Asset | None, flows: list) -> None:
        amt = q(amount)
        if amt == 0:
            return
        rtype, rid = recipient["type"], recipient.get("id")
        flows.append({"decedent": decedent, "asset_id": asset.id if asset else None, "asset": asset.label if asset else "Residuary",
                      "recipient_type": rtype, "recipient_id": rid, "recipient": recipient.get("label") or (self.people[rid].name if rid in self.people else rid),
                      "amount": str(amt), "mechanism": mechanism})
        if rtype == "person" and rid:
            self.received[rid] += amt
            if self.alive.get(rid, True):
                self.holdings[rid][asset.id if asset else f"residuary:{decedent}"] += amt
        elif rtype == "entity" and rid in self.trusts:
            self.trust_assets[rid] += amt
        else:
            self.received[recipient.get("label") or rid or rtype] += amt

    def route_beneficiaries(self, decedent: str, bens: list[Beneficiary], amount: Decimal, asset: Asset, flows: list) -> bool:
        for tier in ("primary", "contingent"):
            live = [b for b in bens if b.tier == tier and (b.type != "person" or self.alive.get(b.id or "", False))]
            dead = [b for b in bens if b.tier == tier and b.type == "person" and not self.alive.get(b.id or "", True)]
            if not live:
                continue
            total = sum((b.share for b in live), ZERO)
            for b in live:
                self.give(decedent, {"type": b.type, "id": b.id, "label": b.label}, amount * b.share / total, f"beneficiary_designation:{tier}", asset, flows)
            if dead:
                if any(b.per_stirpes for b in dead):
                    self.unresolved.append({"code": "per_stirpes", "asset": asset.id, "message": f"{asset.label}: per stirpes share of a predeceased beneficiary needs descendant facts; reallocated pro rata to survivors."})
                else:
                    self.unresolved.append({"code": "lapsed_beneficiary", "asset": asset.id, "message": f"{asset.label}: a named beneficiary predeceased; share reallocated pro rata to surviving {tier} beneficiaries."})
            return True
        return False

    def die(self, pid: str) -> dict[str, Any]:
        e = self.e
        flows: list[dict[str, Any]] = []
        self.alive[pid] = False
        spouse = self.spouse_of(pid)
        spouse_alive = bool(spouse and self.alive.get(spouse))
        gross = ZERO
        probate = ZERO
        probate_liquid = ZERO
        marital = ZERO
        charitable = ZERO
        step_up: list[dict[str, Any]] = []
        nj_by_recipient: dict[str, Decimal] = defaultdict(lambda: ZERO)

        def credit(recipient_type: str, rid: Optional[str], amt: Decimal, insurance: bool = False):
            nonlocal marital, charitable
            if recipient_type == "person" and rid == spouse and spouse_alive and self.people.get(pid, Person(id=pid, name="")).is_us_citizen is not None:
                if self.people.get(spouse, Person(id=spouse, name="")).is_us_citizen:
                    marital += amt
                else:
                    self.unresolved.append({"code": "noncitizen_spouse", "message": "Transfers to a non-citizen spouse need a QDOT for the marital deduction."})
            elif recipient_type == "charity":
                charitable += amt
            if recipient_type == "person" and rid and not insurance:
                nj_by_recipient[rid] += amt

        # Previously inherited holdings are now part of this person's estate.
        inherited = sum(self.holdings.get(pid, {}).values(), ZERO)
        if inherited:
            gross += inherited
            probate += inherited
            probate_liquid += inherited
            flows.append({"decedent": pid, "asset": "Inherited assets", "amount": str(q(inherited)), "mechanism": "inherited_from_prior_death", "recipient": "probate estate"})

        for a in e.assets:
            frac = sum((o.fraction for o in a.owners if o.type == "person" and o.id == pid), ZERO)
            trust_owned = [o for o in a.owners if o.type == "entity" and o.id in self.trusts]
            net = a.value - a.debt
            if a.life_insurance:
                li = a.life_insurance
                if li.get("insured") != pid:
                    continue
                benefit = Decimal(str(li.get("death_benefit", a.value)))
                if li.get("owner") == pid:
                    gross += benefit
                if li.get("owner_type") == "trust":
                    self.unresolved.append({"code": "ilit_three_year", "asset": a.id, "message": f"{a.label}: confirm the ILIT acquired the policy more than three years before death (IRC 2035)."})
                if not self.route_beneficiaries(pid, a.beneficiaries, benefit, a, flows):
                    probate += benefit
                    probate_liquid += benefit
                    flows.append({"decedent": pid, "asset": a.label, "amount": str(q(benefit)), "mechanism": "insurance_to_estate", "recipient": "probate estate"})
                else:
                    for b in a.beneficiaries:
                        if b.type == "person" and self.alive.get(b.id or "", False) and b.tier == "primary":
                            credit("person", b.id, benefit * b.share, insurance=True)
                continue
            for t in trust_owned:
                tr = self.trusts[t.id]
                if pid in tr.settlor_ids and tr.revocable:
                    share = net * t.fraction
                    gross += share
                    flows.append({"decedent": pid, "asset_id": a.id, "asset": a.label, "recipient_type": "entity", "recipient_id": tr.id, "recipient": tr.name,
                                  "amount": str(q(share)), "mechanism": "revocable_trust"})
                    self.trust_assets[tr.id] += share
                    if tr.marital and spouse_alive:
                        marital += share
                    step_up.append({"asset": a.label, "new_basis": str(q(a.value * t.fraction)), "prior_basis": str(q(a.basis * t.fraction))})
            if frac == 0:
                continue
            share = net * frac
            if a.title_form in ("jtwros", "tenancy_by_entirety"):
                survivors = [o for o in a.owners if o.type == "person" and o.id != pid and self.alive.get(o.id, False)]
                spousal_joint = len(a.owners) == 2 and survivors and survivors[0].id == spouse
                included = net * Decimal("0.5") if spousal_joint else share
                gross += included
                if survivors:
                    for o in survivors:
                        self.give(pid, {"type": "person", "id": o.id}, share * (o.fraction / sum((s.fraction for s in survivors), ZERO)), "survivorship", a, flows)
                        credit("person", o.id, share * o.fraction / sum((s.fraction for s in survivors), ZERO))
                    step_up.append({"asset": a.label, "new_basis": str(q(a.basis * (1 - frac) + a.value * frac)) if spousal_joint else str(q(a.value)),
                                    "prior_basis": str(q(a.basis)), "note": "50% step-up for spousal joint tenancy" if spousal_joint else "Full inclusion presumed (IRC 2040(a)); contribution evidence may reduce"})
                    continue
            else:
                gross += share
            step_up.append({"asset": a.label, "new_basis": str(q(a.value * frac)), "prior_basis": str(q(a.basis * frac)),
                            "note": "No step-up for income in respect of a decedent" if a.retirement else "IRC 1014"})
            if a.beneficiaries and self.route_beneficiaries(pid, a.beneficiaries, share, a, flows):
                for b in a.beneficiaries:
                    if b.tier == "primary" and (b.type != "person" or self.alive.get(b.id or "", False)):
                        credit(b.type, b.id, share * b.share)
                if a.retirement:
                    self.unresolved.append({"code": "ird", "asset": a.id, "message": f"{a.label}: retirement assets carry income tax for beneficiaries (IRD); 10-year rule may apply."})
                continue
            probate += share
            if a.liquid:
                probate_liquid += share
            if a.will_specific_gift_to and a.will_specific_gift_to in self.people and self.alive.get(a.will_specific_gift_to):
                self.give(pid, {"type": "person", "id": a.will_specific_gift_to}, share, "will_specific_gift", a, flows)
                credit("person", a.will_specific_gift_to, share)
                probate -= share
                if a.liquid:
                    probate_liquid -= share

        debts = Decimal(str(e.debts.get(pid, 0)))
        admin = q(probate * e.admin_pct) + e.funeral
        obligations = debts + admin
        # Estate tax (federal) on the taxable estate.
        adjusted_gifts = Decimal(str(e.lifetime_taxable_gifts.get(pid, 0)))
        taxable = max(ZERO, gross - obligations - marital - charitable)
        applicable = e.exemption + self.dsue
        tax = max(ZERO, tentative_tax(taxable + adjusted_gifts) - tentative_tax(applicable))
        tax = q(tax)
        if spouse_alive and e.elect_portability:
            used = min(applicable, taxable + adjusted_gifts)
            self.dsue = max(ZERO, e.exemption - used)
        # NJ transfer inheritance tax by beneficiary class (Class A exempt; insurance to named beneficiaries exempt).
        nj_tax = ZERO
        nj_detail = []
        if e.nj_inheritance and self.people.get(pid, Person(id=pid, name="")).domicile == "NJ":
            for rid, amt in nj_by_recipient.items():
                kind = self.relation(pid, rid)
                cls = "A" if kind in CLASS_A else ("C" if kind in CLASS_C else "D")
                t = ZERO if cls == "A" else (_bracket(amt, e.nj_class_c) if cls == "C" else _bracket(amt, e.nj_class_d))
                if cls == "D" and amt < 500:
                    t = ZERO
                nj_tax += t
                nj_detail.append({"recipient_id": rid, "class": cls, "transfer": str(q(amt)), "tax": str(q(t))})
        # Residuary: probate net of obligations and taxes, to will beneficiaries or pour-over trust.
        residue = probate - obligations - tax - nj_tax
        liquidity_gap = max(ZERO, obligations + tax + nj_tax - probate_liquid)
        will = self.wills.get(pid)
        if residue > 0:
            if will and will.pour_over_trust_id and will.pour_over_trust_id in self.trusts:
                t = self.trusts[will.pour_over_trust_id]
                self.give(pid, {"type": "entity", "id": t.id, "label": t.name}, residue, "pour_over_will", None, flows)
            elif will and will.residuary:
                tmp = Asset(id=f"residuary:{pid}", label="Residuary estate", kind="residuary", value=residue)
                if not self.route_beneficiaries(pid, will.residuary, residue, tmp, flows):
                    self.unresolved.append({"code": "residuary_lapse", "person": pid, "message": "All residuary beneficiaries predeceased; intestacy not modeled."})
            elif spouse_alive:
                self.give(pid, {"type": "person", "id": spouse}, residue, "intestacy_assumption", None, flows)
                self.unresolved.append({"code": "intestacy_assumed", "person": pid, "message": "No residuary clause: residue shown passing to the surviving spouse under an intestacy assumption (N.J.S.A. 3B:5-3); confirm with counsel."})
            else:
                self.unresolved.append({"code": "residue_unassigned", "person": pid, "message": f"Residue of {q(residue)} has no recorded recipient."})
        if liquidity_gap > 0:
            self.unresolved.append({"code": "liquidity_gap", "person": pid, "message": f"Liquid probate assets fall short of obligations and taxes by {q(liquidity_gap)}."})
        self.holdings.pop(pid, None)
        return {
            "decedent": pid, "name": self.people.get(pid, Person(id=pid, name=pid)).name,
            "gross_estate": str(q(gross)), "probate_estate": str(q(probate)), "debts": str(q(debts)), "administration": str(q(admin)),
            "marital_deduction": str(q(marital)), "charitable_deduction": str(q(charitable)), "taxable_estate": str(q(taxable)),
            "adjusted_taxable_gifts": str(q(adjusted_gifts)), "applicable_exclusion": str(q(applicable)), "federal_estate_tax": str(tax),
            "nj_inheritance_tax": str(q(nj_tax)), "nj_detail": nj_detail, "liquid_probate": str(q(probate_liquid)),
            "liquidity_gap": str(q(liquidity_gap)), "dsue_carried": str(q(self.dsue)), "basis_adjustments": step_up, "flows": flows,
        }


def run(inp: dict[str, Any]) -> dict[str, Any]:
    e = EstateInput.model_validate(inp)
    est = Estate(e)
    est.check()
    deaths = []
    if e.simultaneous:
        for pid in e.death_order:
            est.alive[pid] = False
        est.unresolved.append({"code": "simultaneous_death", "message": "Simultaneous death: each decedent treated as surviving the other for their own property (N.J.S.A. 3B:5-1 120-hour rule)."})
    for pid in e.death_order:
        deaths.append(est.die(pid))
    trusts = []
    for t in e.trusts:
        amt = est.trust_assets.get(t.id, ZERO)
        dist = []
        for b in t.beneficiaries:
            share = amt * b.share
            sched = [{"age": s.get("age"), "fraction": s.get("fraction"), "amount": str(q(share * Decimal(str(s.get("fraction", 0)))))} for s in t.age_schedule]
            dist.append({"beneficiary_id": b.id, "label": b.label or (est.people[b.id].name if b.id in est.people else b.id), "share": str(b.share),
                         "amount": str(q(share)), "age_schedule": sched})
        trusts.append({"trust_id": t.id, "name": t.name, "funded_amount": str(q(amt)), "distributions": dist})
    beneficiaries = sorted(({"recipient": k if k not in est.people else est.people[k].name, "recipient_id": k, "amount": str(q(v))}
                            for k, v in est.received.items()), key=lambda x: -Decimal(x["amount"]))
    total_tax = sum((Decimal(d["federal_estate_tax"]) + Decimal(d["nj_inheritance_tax"]) for d in deaths), ZERO)
    return {
        "engine_version": ENGINE_VERSION,
        "summary": {"total_transfer_taxes": str(q(total_tax)), "deaths": len(deaths), "unresolved_count": len(est.unresolved),
                    "note": "Workpaper for counsel review. Does not determine enforceability or construe documents."},
        "deaths": deaths, "trusts": trusts, "beneficiaries": beneficiaries, "unresolved": est.unresolved,
    }
