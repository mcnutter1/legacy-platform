from __future__ import annotations

import json
import subprocess
import sys


def call(job_type: str, payload: dict, job_id: str = "01JOB") -> dict:
    env = {"PATH": "/usr/bin:/bin", "LEGACY_JOB_DIR": ""}
    p = subprocess.run([sys.executable, "-I", "-m", "legacy_runners", job_type], input=json.dumps(
        {"schema_version": 1, "job_id": job_id, "job_type": job_type, "input": payload}).encode(), capture_output=True, env=env, timeout=60)
    return json.loads(p.stdout)


def test_envelope_round_trip():
    out = call("selfcheck", {})
    assert out["ok"] is True and out["job_id"] == "01JOB"
    assert {p["jurisdiction"] for p in out["result"]["packs"]} == {"US", "NJ"}


def test_unknown_job_rejected():
    out = call("shell.exec", {})
    assert out["ok"] is False and out["error"]["code"] == "unknown_job"


def test_invalid_input_is_structured():
    out = call("tax.compute", {"tax_year": "x"})
    assert out["ok"] is False and out["error"]["code"] == "invalid_input"
