from __future__ import annotations

import copy

from legacy_runners.planning import ss
from legacy_runners.planning.projection import run_deterministic, run_montecarlo, run_stress

ZERO_RET = {"classes": {"cash": {"mean": 0.0, "sd": 0.0}, "us_equity": {"mean": 0.0, "sd": 0.0}}, "correlation": {}}

BASE = {
    "start_year": 2026, "end_year": 2030, "inflation": 0.0, "state": "NONE",
    "people": [{"id": "a", "birth_year": 1960, "retire_year": 2020, "death_age": 100}],
    "accounts": [{"id": "cash", "bucket": "cash", "balance": 100000, "allocation": {"cash": 1.0}}],
    "expenses": {"essential": 10000},
    "returns": ZERO_RET,
    "strategy": {"min_cash": 0},
}


def test_zero_return_projection_conserves_cash():
    res = run_deterministic(BASE)
    years = res["years"]
    assert [round(y["ending_cash"]) for y in years] == [90000, 80000, 70000, 60000, 50000]
    assert all(y["total_tax"] == 0 for y in years)
    assert res["summary"]["first_shortfall_year"] is None


def test_shortfall_detected_when_cash_runs_out():
    data = copy.deepcopy(BASE)
    data["expenses"]["essential"] = 30000
    res = run_deterministic(data)
    assert res["summary"]["first_shortfall_year"] == 2029


def test_bucket_reconciliation_every_year():
    data = copy.deepcopy(BASE)
    data["accounts"].append({"id": "ira", "bucket": "pretax", "owner": "a", "balance": 300000, "allocation": {"us_equity": 1.0}})
    data["accounts"].append({"id": "brk", "bucket": "taxable", "balance": 200000, "basis": 150000, "allocation": {"us_equity": 1.0}})
    res = run_deterministic(data)
    assert not [i for i in res["issues"] if i["code"] == "conservation"]


def test_rmd_starts_at_statutory_age():
    data = copy.deepcopy(BASE)
    data["people"][0]["birth_year"] = 1955  # RMD age 73 -> 2028
    data["accounts"].append({"id": "ira", "bucket": "pretax", "owner": "a", "balance": 265000, "allocation": {"cash": 1.0}})
    res = run_deterministic(data)
    by_year = {y["year"]: y["rmd"] for y in res["years"]}
    assert by_year[2027] == 0
    assert by_year[2028] == round(265000 / 26.5, 2)


def test_claim_factors():
    assert abs(ss.claim_factor(1960, 62 * 12) - 0.70) < 1e-9
    assert abs(ss.claim_factor(1960, 67 * 12) - 1.0) < 1e-9
    assert abs(ss.claim_factor(1960, 70 * 12) - 1.24) < 1e-9
    assert abs(ss.spousal_factor(1960, 62 * 12) - 0.325) < 1e-9


def test_survivor_takes_larger_benefit():
    data = copy.deepcopy(BASE)
    data["people"] = [
        {"id": "a", "birth_year": 1956, "retire_year": 2020, "death_age": 71, "ss_pia": 3000, "ss_claim_age": 67},
        {"id": "b", "birth_year": 1956, "retire_year": 2020, "death_age": 100, "ss_pia": 1000, "ss_claim_age": 67},
    ]
    res = run_deterministic(data)
    y = {r["year"]: r for r in res["years"]}
    # 2026: both alive and claimed (age 70; FRA 66+4m -> delayed credits). 2027: 'a' has died, survivor keeps the larger benefit.
    assert y[2027]["social_security"] >= 3000 * 12
    assert y[2027]["social_security"] < y[2026]["social_security"]


def test_montecarlo_seed_reproducible():
    data = copy.deepcopy(BASE)
    data["returns"] = {"classes": {"cash": {"mean": 0.03, "sd": 0.01}, "us_equity": {"mean": 0.07, "sd": 0.16}}, "correlation": {}}
    data["accounts"].append({"id": "brk", "bucket": "taxable", "balance": 200000, "allocation": {"us_equity": 1.0}})
    data["mc"] = {"paths": 200, "seed": 42}
    a, b = run_montecarlo(data), run_montecarlo(data)
    assert a["summary"] == b["summary"] and a["years"] == b["years"]
    data["mc"]["seed"] = 43
    c = run_montecarlo(data)
    assert c["years"] != a["years"]


def test_stress_scenarios_reduce_wealth():
    data = copy.deepcopy(BASE)
    data["inflation"] = 0.02
    data["returns"] = {"classes": {"cash": {"mean": 0.03, "sd": 0.01}, "us_equity": {"mean": 0.07, "sd": 0.16}}, "correlation": {}}
    data["accounts"].append({"id": "brk", "bucket": "taxable", "balance": 500000, "allocation": {"us_equity": 1.0}})
    res = run_stress(data)
    sc = {s["scenario"]: s for s in res["summary"]["scenarios"]}
    assert sc["early_losses"]["delta_vs_base"] < 0
    assert sc["high_inflation"]["delta_vs_base"] < 0


def test_plan_comparison_differs_only_by_explicit_change():
    a = copy.deepcopy(BASE)
    b = copy.deepcopy(BASE)
    b["expenses"]["essential"] = 12000
    ra, rb = run_deterministic(a), run_deterministic(b)
    assert ra["input_hash"] != rb["input_hash"]
    assert round(ra["years"][0]["ending_cash"] - rb["years"][0]["ending_cash"]) == 2000
