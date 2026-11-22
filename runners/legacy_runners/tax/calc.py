"""Calculation graph with line-level trace. Every node records rule, inputs, value and explanation."""
from __future__ import annotations

from decimal import ROUND_HALF_UP, Decimal
from typing import Any, Iterable

ZERO = Decimal("0")
CENT = Decimal("0.01")
ONE = Decimal("1")


def D(v: Any) -> Decimal:
    if v is None or v == "":
        return ZERO
    if isinstance(v, Decimal):
        return v
    if isinstance(v, float):
        return Decimal(repr(v))
    return Decimal(str(v).replace(",", "").replace("$", "").strip() or "0")


def whole(v: Decimal) -> Decimal:
    """IRS/NJ forms round to whole dollars (half up)."""
    return v.quantize(ONE, rounding=ROUND_HALF_UP)


def cents(v: Decimal) -> Decimal:
    return v.quantize(CENT, rounding=ROUND_HALF_UP)


def pos(v: Decimal) -> Decimal:
    return v if v > 0 else ZERO


def bracket_tax(amount: Decimal, brackets: list, rounding: bool = True) -> Decimal:
    """Progressive tax over [[upper_or_null, rate], ...]."""
    tax = ZERO
    lower = ZERO
    for upper, rate in brackets:
        hi = D(upper) if upper is not None else None
        if amount <= lower:
            break
        top = amount if hi is None else min(amount, hi)
        tax += (top - lower) * D(rate)
        if hi is None or amount <= hi:
            break
        lower = hi
    return cents(tax) if rounding else tax


def marginal_rate(amount: Decimal, brackets: list) -> Decimal:
    for upper, rate in brackets:
        if upper is None or amount <= D(upper):
            return D(rate)
    return D(brackets[-1][1])


class Calc:
    def __init__(self, jurisdiction: str):
        self.jurisdiction = jurisdiction
        self.values: dict[str, Decimal] = {}
        self.trace: list[dict[str, Any]] = []
        self.lines: list[dict[str, Any]] = []
        self.issues: list[dict[str, Any]] = []
        self.unsupported: list[dict[str, Any]] = []

    def set(self, node: str, value: Decimal, rule: str | None = None, inputs: Iterable[str] = (), note: str | None = None,
            facts: Iterable[str] = (), line: tuple[str, str, str] | None = None, round_whole: bool = True) -> Decimal:
        v = whole(value) if round_whole else cents(value)
        self.values[node] = v
        entry = {"node": node, "value": str(v), "rule": rule, "inputs": {i: str(self.values.get(i, ZERO)) for i in inputs}}
        if note:
            entry["note"] = note
        facts = [f for f in facts if f]
        if facts:
            entry["facts"] = sorted(set(facts))[:200]
        self.trace.append(entry)
        if line:
            form, ln, label = line
            self.lines.append({"jurisdiction": self.jurisdiction, "form": form, "line": ln, "label": label, "value": str(v), "node": node, "rule": rule})
        return v

    def get(self, node: str) -> Decimal:
        return self.values.get(node, ZERO)

    def issue(self, code: str, message: str, severity: str = "warning", **ctx: Any) -> None:
        self.issues.append({"jurisdiction": self.jurisdiction, "code": code, "severity": severity, "message": message, **ctx})

    def block(self, code: str, message: str, **ctx: Any) -> None:
        self.unsupported.append({"jurisdiction": self.jurisdiction, "code": code, "message": message, **ctx})
