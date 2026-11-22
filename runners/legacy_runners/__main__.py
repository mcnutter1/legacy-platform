"""CLI dispatch: `python -I -m legacy_runners <job_type>` reads one JSON request from stdin, writes one JSON result."""
from __future__ import annotations

import json
import os
import sys
import time
import traceback
from typing import Any, Callable

from pydantic import BaseModel, ValidationError

from . import ENGINE_VERSION

MAX_STDIN = 32 * 1024 * 1024
SCHEMA_VERSION = 1


class Envelope(BaseModel):
    schema_version: int
    job_id: str
    job_type: str
    input: dict[str, Any]


class RunnerError(Exception):
    def __init__(self, code: str, message: str, retryable: bool = False):
        super().__init__(message)
        self.code = code
        self.retryable = retryable


def _limits() -> None:
    try:
        import resource

        mem = int(os.environ.get("LEGACY_RUNNER_MEMORY_MB", "768")) * 1024 * 1024
        cpu = int(os.environ.get("LEGACY_RUNNER_CPU_SECONDS", "60"))
        for lim, val in ((resource.RLIMIT_AS, mem), (resource.RLIMIT_CPU, cpu), (resource.RLIMIT_FSIZE, 256 * 1024 * 1024)):
            try:
                soft, hard = resource.getrlimit(lim)
                target = val if hard == resource.RLIM_INFINITY else min(val, hard)
                resource.setrlimit(lim, (target, hard))
            except (ValueError, OSError):
                pass
    except ImportError:  # pragma: no cover - non-POSIX
        pass


def _handlers() -> dict[str, Callable[[dict[str, Any]], dict[str, Any]]]:
    from .tax import engine as tax_engine
    from .tax import strategies, rental
    from .planning import projection
    from . import debt, estate, imports, equity, documents

    return {
        "tax.compute": tax_engine.run,
        "tax.strategies": strategies.run,
        "rental.classify": rental.run,
        "plan.project": projection.run_deterministic,
        "plan.montecarlo": projection.run_montecarlo,
        "plan.stress": projection.run_stress,
        "debt.amortize": debt.run_amortize,
        "debt.compare": debt.run_compare,
        "estate.distribute": estate.run,
        "import.normalize": imports.run,
        "equity.model": equity.run,
        "document.extract": documents.run,
        "selfcheck": selfcheck,
    }


def selfcheck(_: dict[str, Any]) -> dict[str, Any]:
    from .tax.packs import catalog
    import numpy

    return {"engine": ENGINE_VERSION, "numpy": numpy.__version__, "packs": catalog()}


def main(argv: list[str]) -> int:
    started = time.monotonic()
    job_id = ""
    try:
        _limits()
        if len(argv) != 2:
            raise RunnerError("usage", "expected exactly one job type argument")
        job_type = argv[1]
        handlers = _handlers()
        if job_type not in handlers:
            raise RunnerError("unknown_job", f"job type not allowlisted: {job_type}")
        raw = sys.stdin.buffer.read(MAX_STDIN + 1)
        if len(raw) > MAX_STDIN:
            raise RunnerError("input_too_large", "request exceeds limit")
        env = Envelope.model_validate_json(raw)
        job_id = env.job_id
        if env.schema_version != SCHEMA_VERSION:
            raise RunnerError("schema_version", f"unsupported schema version {env.schema_version}")
        if env.job_type != job_type:
            raise RunnerError("job_mismatch", "envelope job type does not match argument")
        result = handlers[job_type](env.input)
        out = {
            "ok": True, "schema_version": SCHEMA_VERSION, "job_id": job_id, "job_type": job_type,
            "engine": {"name": "legacy_runners", "version": ENGINE_VERSION},
            "result": result, "metrics": {"runner_s": round(time.monotonic() - started, 4)},
        }
        sys.stdout.write(json.dumps(out, default=str, separators=(",", ":")))
        return 0
    except ValidationError as e:
        errs = [{"loc": ".".join(str(p) for p in x["loc"]), "msg": x["msg"]} for x in e.errors()[:20]]
        return _fail(job_id, "invalid_input", json.dumps(errs), False)
    except RunnerError as e:
        return _fail(job_id, e.code, str(e), e.retryable)
    except MemoryError:
        return _fail(job_id, "memory_limit", "runner exceeded memory limit", False)
    except Exception as e:  # noqa: BLE001
        traceback.print_exc(file=sys.stderr)
        return _fail(job_id, "internal", type(e).__name__ + ": " + str(e)[:300], False)


def _fail(job_id: str, code: str, message: str, retryable: bool) -> int:
    sys.stdout.write(json.dumps({"ok": False, "schema_version": SCHEMA_VERSION, "job_id": job_id,
                                 "error": {"code": code, "message": message, "retryable": retryable}}))
    return 2


if __name__ == "__main__":
    sys.exit(main(sys.argv))
