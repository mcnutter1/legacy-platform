"""Versioned tax rule packs shipped with the runner. Content hashes bind every run to the exact rules used."""
from __future__ import annotations

import hashlib
import json
from decimal import Decimal
from functools import lru_cache
from importlib import resources
from typing import Any


class PackError(Exception):
    pass


@lru_cache(maxsize=16)
def _load(name: str) -> tuple[dict[str, Any], str]:
    raw = resources.files(__package__).joinpath(name).read_text(encoding="utf-8")
    data = json.loads(raw)
    canonical = json.dumps(data, sort_keys=True, separators=(",", ":"))
    return data, hashlib.sha256(canonical.encode()).hexdigest()


def _files() -> list[str]:
    return sorted(p.name for p in resources.files(__package__).iterdir() if p.name.endswith(".json"))


def catalog() -> list[dict[str, Any]]:
    out = []
    for f in _files():
        data, digest = _load(f)
        out.append({
            "jurisdiction": data["jurisdiction"], "tax_year": data["tax_year"], "version": data["version"],
            "legislation_status": data["legislation_status"], "hash": digest, "release_note": data.get("release_note"),
            "rules": [{"id": k, "authority": v.get("authority"), "locator": v.get("locator")} for k, v in data["rules"].items()],
        })
    return out


class Pack:
    def __init__(self, jurisdiction: str, year: int, version: str | None = None, expected_hash: str | None = None, approved: bool = False):
        matches = []
        for f in _files():
            data, digest = _load(f)
            if data["jurisdiction"] == jurisdiction and int(data["tax_year"]) == int(year) and (version is None or data["version"] == version):
                matches.append((data, digest))
        if not matches:
            raise PackError(f"No {jurisdiction} rule pack for {year}" + (f" version {version}" if version else ""))
        self.data, self.hash = sorted(matches, key=lambda m: m[0]["version"])[-1]
        if expected_hash and expected_hash != self.hash:
            raise PackError(f"{jurisdiction} {year} pack hash mismatch; release metadata is stale")
        self.jurisdiction = jurisdiction
        self.year = int(year)
        self.version = self.data["version"]
        self.approved = approved
        self.legislation_status = self.data["legislation_status"]

    def rule(self, rule_id: str) -> dict[str, Any]:
        try:
            return self.data["rules"][rule_id]
        except KeyError as e:
            raise PackError(f"{self.jurisdiction} {self.year} pack missing rule {rule_id}") from e

    def v(self, rule_id: str, *path: str, status: str | None = None) -> Any:
        val = self.rule(rule_id)["value"]
        for p in path:
            if isinstance(val, dict):
                if p in val:
                    val = val[p]
                elif status is not None and status in val:
                    val = val[status]
                elif "default" in val:
                    val = val["default"]
                else:
                    raise PackError(f"{rule_id} has no value for {p}")
            else:
                break
        return val

    def by_status(self, rule_id: str, status: str, *path: str) -> Any:
        """Look up a value keyed by filing status with sensible fallbacks (qss→mfj, married→mfj)."""
        val = self.rule(rule_id)["value"]
        for p in path:
            val = val[p]
        if not isinstance(val, dict):
            return val
        for key in (status, "mfj" if status == "qss" else None, "default"):
            if key and key in val:
                return val[key]
        raise PackError(f"{rule_id} has no value for status {status}")

    def d(self, rule_id: str, status: str | None = None, *path: str) -> Decimal:
        val = self.by_status(rule_id, status, *path) if status else self.v(rule_id, *path)
        return Decimal(str(val))

    def ref(self) -> dict[str, Any]:
        return {"jurisdiction": self.jurisdiction, "year": self.year, "version": self.version, "hash": self.hash,
                "approved": self.approved, "legislation_status": self.legislation_status}
