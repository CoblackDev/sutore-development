#!/usr/bin/env python3
"""Flag likely hardcoded UI Turkish outside gettext (heuristic)."""
from __future__ import annotations

import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SKIP = ("tr-districts", "/tools/", "languages/", "Contracts/Templates", "_i18n", "_map_", "_chunk_")
TR = re.compile(r"[çğıöşüÇĞİÖŞÜ]")
# string literals
LIT = re.compile(r"(['\"])((?:(?!\1).)*[çğıöşüÇĞİÖŞÜ](?:(?!\1).)*)\1")
GETTEXT = re.compile(r"(?:__|_e|esc_html__|esc_attr__|_x|_n)\(")


def main() -> None:
    hits = []
    for path in list(ROOT.rglob("*.php")) + list(ROOT.rglob("*.js")):
        rel = path.relative_to(ROOT).as_posix()
        if any(s in rel for s in SKIP):
            continue
        text = path.read_text(encoding="utf-8")
        for i, line in enumerate(text.splitlines(), 1):
            if not TR.search(line):
                continue
            if GETTEXT.search(line):
                continue
            # skip comments
            stripped = line.strip()
            if stripped.startswith("//") or stripped.startswith("*") or stripped.startswith("#"):
                continue
            if LIT.search(line) or "Sözleş" in line or "Yurtiçi" in line:
                hits.append(f"{rel}:{i}: {stripped[:120]}")
    print(f"hits={len(hits)}")
    for h in hits[:60]:
        print(h)


if __name__ == "__main__":
    main()
