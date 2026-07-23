#!/usr/bin/env python3
"""Extract unique gettext msgids from sutore-marketplace."""
from __future__ import annotations

import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / "tools" / "_i18n_extract.json"

FUNCS = r"(?:__|_e|esc_html__|esc_attr__|_n|_nx|_x)"
# Single-quoted msgid (simplest / most common)
RE_SQ = re.compile(
    FUNCS + r"\(\s*'((?:\\'|[^'])*)'\s*,\s*'sutore-marketplace'",
    re.MULTILINE,
)
RE_DQ = re.compile(
    FUNCS + r'\(\s*"((?:\\"|[^"])*)"\s*,\s*\'sutore-marketplace\'',
    re.MULTILINE,
)
# _n / _nx: first and second msgstr
RE_N_SQ = re.compile(
    r"(?:_n|_nx)\(\s*'((?:\\'|[^'])*)'\s*,\s*'((?:\\'|[^'])*)'\s*,",
    re.MULTILINE,
)


def unescape(s: str) -> str:
    return (
        s.replace(r"\'", "'")
        .replace(r'\"', '"')
        .replace(r"\n", "\n")
        .replace(r"\t", "\t")
        .replace(r"\\", "\\")
    )


def main() -> None:
    found: dict[str, int] = {}
    for path in list(ROOT.rglob("*.php")) + list(ROOT.rglob("*.js")):
        rel = path.relative_to(ROOT).as_posix()
        if "tr-districts-data" in rel or "node_modules" in rel or "/tools/" in f"/{rel}":
            continue
        try:
            text = path.read_text(encoding="utf-8")
        except OSError:
            continue
        for m in RE_SQ.finditer(text):
            s = unescape(m.group(1))
            found[s] = found.get(s, 0) + 1
        for m in RE_DQ.finditer(text):
            s = unescape(m.group(1))
            found[s] = found.get(s, 0) + 1
        for m in RE_N_SQ.finditer(text):
            for g in (1, 2):
                s = unescape(m.group(g))
                found[s] = found.get(s, 0) + 1

    ordered = dict(sorted(found.items(), key=lambda kv: (-kv[1], kv[0])))
    OUT.write_text(json.dumps(ordered, ensure_ascii=False, indent=2), encoding="utf-8")
    print(f"unique={len(ordered)} occurrences={sum(ordered.values())} -> {OUT}")


if __name__ == "__main__":
    main()
