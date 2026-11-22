"""Deterministic document extraction from job-local PDFs/text. Untrusted content is treated strictly as data."""
from __future__ import annotations

import os
import re
from decimal import Decimal
from typing import Any

from .imports import parse_date, parse_decimal

MAX_PAGES = 400
MONEY = r"\(?-?\$?\s?[\d,]+\.\d{2}\)?"

PAYSTUB_FIELDS: dict[str, list[str]] = {
    "gross": [r"gross\s*(?:pay|earnings|wages)", r"total\s*gross"],
    "net": [r"net\s*pay", r"net\s*amount", r"take\s*home"],
    "federal_withholding": [r"federal\s*(?:income\s*)?(?:tax|w/?h|withholding)", r"fed(?:eral)?\s*income\s*tax", r"fitw"],
    "social_security": [r"social\s*security(?:\s*tax)?", r"oasdi", r"fica\s*-?\s*ss"],
    "medicare": [r"medicare(?:\s*tax)?", r"fica\s*-?\s*med"],
    "nj_withholding": [r"nj\s*(?:state\s*)?(?:income\s*)?(?:tax|withholding|w/?h)", r"new\s*jersey\s*(?:state\s*)?(?:income\s*)?tax", r"state\s*income\s*tax"],
    "nj_sdi": [r"nj\s*sdi", r"nj\s*disability"],
    "nj_fli": [r"nj\s*fli", r"family\s*leave"],
    "nj_ui": [r"nj\s*(?:sui|ui|unemployment)", r"nj\s*ui/wf/swf"],
    "pretax_401k": [r"401\s*\(?k\)?(?!\s*roth)", r"pre-?tax\s*401"],
    "roth_401k": [r"roth\s*401\s*\(?k\)?", r"401\s*\(?k\)?\s*roth"],
    "hsa": [r"\bhsa\b"],
    "fsa": [r"\bfsa\b", r"flexible\s*spending"],
    "medical_premium": [r"medical(?:\s*premium)?", r"health\s*insurance"],
    "dental": [r"dental"],
    "vision": [r"vision"],
    "espp": [r"espp", r"stock\s*purchase"],
    "rsu_income": [r"rsu", r"restricted\s*stock"],
    "bonus": [r"bonus"],
}

W2_BOXES = {
    "box1": r"(?:^|\s)1\s+wages,?\s*tips,?\s*other\s*comp(?:ensation)?",
    "box2": r"(?:^|\s)2\s+federal\s*income\s*tax\s*withheld",
    "box3": r"(?:^|\s)3\s+social\s*security\s*wages",
    "box4": r"(?:^|\s)4\s+social\s*security\s*tax\s*withheld",
    "box5": r"(?:^|\s)5\s+medicare\s*wages\s*and\s*tips",
    "box6": r"(?:^|\s)6\s+medicare\s*tax\s*withheld",
    "box16": r"16\s+state\s*wages",
    "box17": r"17\s+state\s*income\s*tax",
}


def _text(path: str) -> tuple[list[str], dict[str, Any]]:
    jobdir = os.environ.get("LEGACY_JOB_DIR", "")
    real = os.path.realpath(path)
    if not jobdir or not real.startswith(os.path.realpath(jobdir) + os.sep):
        raise ValueError("file path outside job directory")
    meta: dict[str, Any] = {}
    with open(real, "rb") as fh:
        head = fh.read(5)
    if head == b"%PDF-":
        from pypdf import PdfReader

        reader = PdfReader(real, strict=False)
        if reader.is_encrypted:
            raise ValueError("encrypted PDF")
        n = len(reader.pages)
        if n > MAX_PAGES:
            raise ValueError("too many pages")
        meta["page_count"] = n
        pages = []
        for p in reader.pages:
            try:
                pages.append(p.extract_text() or "")
            except Exception:  # noqa: BLE001
                pages.append("")
        return pages, meta
    with open(real, "rb") as fh:
        data = fh.read(5 * 1024 * 1024)
    try:
        return [data.decode("utf-8")], meta
    except UnicodeDecodeError:
        return [""], meta


def _amounts(line: str) -> list[Decimal]:
    out = []
    for m in re.findall(MONEY, line):
        v = parse_decimal(m)
        if v is not None:
            out.append(v)
    return out


def paystub(pages: list[str]) -> dict[str, Any]:
    fields: dict[str, Any] = {}
    for pno, text in enumerate(pages, start=1):
        for raw in text.splitlines():
            line = raw.strip()
            low = line.lower()
            for key, pats in PAYSTUB_FIELDS.items():
                if key in fields:
                    continue
                if any(re.search(p, low) for p in pats):
                    amts = _amounts(line)
                    if not amts:
                        continue
                    cur = amts[0] if len(amts) >= 1 else None
                    ytd = amts[-1] if len(amts) >= 2 else None
                    conf = 0.9 if len(amts) == 2 else (0.6 if len(amts) == 1 else 0.5)
                    fields[key] = {"current": str(abs(cur)) if cur is not None else None, "ytd": str(abs(ytd)) if ytd is not None else None,
                                   "confidence": conf, "page": pno, "quote": line[:160]}
        for label, key in ((r"pay\s*date|check\s*date|advice\s*date", "pay_date"), (r"period\s*(?:begin|start)", "period_start"), (r"period\s*(?:end|ending)", "period_end")):
            if key in fields:
                continue
            m = re.search(rf"(?:{label})\s*:?\s*([0-9]{{1,2}}/[0-9]{{1,2}}/[0-9]{{2,4}}|[0-9]{{4}}-[0-9]{{2}}-[0-9]{{2}})", text, re.I)
            if m:
                d = parse_date(m.group(1))
                if d:
                    fields[key] = {"value": d.isoformat(), "confidence": 0.85, "page": pno, "quote": m.group(0)[:120]}
        if "employer" not in fields:
            first = next((ln.strip() for ln in text.splitlines() if ln.strip()), "")
            if first:
                fields["employer"] = {"value": first[:120], "confidence": 0.3, "page": pno, "quote": first[:120]}
    checks = []
    def val(k: str, part: str = "current") -> Decimal | None:
        f = fields.get(k)
        return Decimal(f[part]) if f and f.get(part) else None
    gross, net = val("gross"), val("net")
    if gross is not None and net is not None:
        deductions = sum((val(k) or Decimal(0)) for k in PAYSTUB_FIELDS if k not in ("gross", "net", "bonus", "rsu_income", "espp"))
        diff = gross - deductions - net
        checks.append({"check": "net_reconciles", "passed": abs(diff) <= Decimal("1.00"), "difference": str(diff),
                       "message": "Gross less extracted deductions equals net" if abs(diff) <= 1 else "Unextracted deductions or misread amounts; review."})
        if net > gross:
            checks.append({"check": "net_le_gross", "passed": False, "message": "Net pay exceeds gross pay."})
    for k in ("gross", "federal_withholding", "social_security", "medicare"):
        c, y = val(k), val(k, "ytd")
        if c is not None and y is not None and y < c:
            checks.append({"check": f"{k}_ytd_progression", "passed": False, "message": f"YTD {k} is less than the current amount."})
    ss, g = val("social_security"), gross
    if ss is not None and g is not None and g > 0 and not (Decimal("0.05") <= ss / g <= Decimal("0.07")):
        checks.append({"check": "ss_rate", "passed": False, "message": "Social Security is not ~6.2% of gross; pre-tax benefits or wage-base cap may explain it."})
    return {"schema": "paystub", "schema_version": 1, "fields": fields, "checks": checks}


def w2(pages: list[str]) -> dict[str, Any]:
    text = "\n".join(pages)
    fields: dict[str, Any] = {}
    for key, pat in W2_BOXES.items():
        m = re.search(pat + r"[^\d\(\-]{0,40}(" + MONEY + ")", text, re.I)
        if m:
            v = parse_decimal(m.group(1))
            fields[key] = {"value": str(v), "confidence": 0.7, "page": 1, "quote": m.group(0)[:160]}
    for code, v in re.findall(r"12[a-d]?\s+([A-Z]{1,2})\s+(" + MONEY + ")", text):
        fields.setdefault("box12", {"value": {}, "confidence": 0.5, "page": 1, "quote": "Box 12"})["value"][code] = str(parse_decimal(v))
    checks = []
    if "box1" in fields and "box3" in fields and Decimal(fields["box1"]["value"]) > Decimal(fields["box3"]["value"]) * 2:
        checks.append({"check": "box1_vs_box3", "passed": False, "message": "Box 1 is much larger than box 3; verify."})
    return {"schema": "w2", "schema_version": 1, "fields": fields, "checks": checks}


def run(inp: dict[str, Any]) -> dict[str, Any]:
    pages, meta = _text(inp["file_path"])
    chars = sum(len(p.strip()) for p in pages)
    schema = inp.get("schema", "paystub")
    if chars < 40:
        return {"status": "needs_ocr", "schema": schema, "meta": meta, "fields": {}, "checks": [],
                "message": "No text layer found; route to the configured OCR adapter or manual entry."}
    result = paystub(pages) if schema == "paystub" else w2(pages) if schema == "w2" else {"schema": schema, "fields": {}, "checks": []}
    # Untrusted text never becomes an instruction; only matched numeric/date fields leave the runner.
    result.update({"status": "extracted", "meta": meta, "text_chars": chars,
                   "low_confidence": [k for k, v in result["fields"].items() if isinstance(v, dict) and v.get("confidence", 1) < 0.7]})
    return result
