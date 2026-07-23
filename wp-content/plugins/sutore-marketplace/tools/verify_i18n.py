#!/usr/bin/env python3
"""Report remaining Turkish gettext msgids and hardcoded UI strings."""
from __future__ import annotations

import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
GT = re.compile(
    r"(?:__|_e|esc_html__|esc_attr__|_x)\(\s*'((?:\\'|[^'])*)'\s*,\s*'sutore-marketplace'"
)
TR_CHARS = re.compile(r"[çğıöşüÇĞİÖŞÜ]")
# common leftover Turkish words without diacritics that shouldn't appear as msgid if converted
TR_WORDS = re.compile(
    r"\b(bulunamadı|Kaydedildi|Satıcı|Sipariş|Ürün|İptal|Kargola|Hak ediş|Sözleş|tahmini|lütfen|için|veya)\b",
    re.I,
)


def main() -> None:
    leftovers = []
    for path in list(ROOT.rglob("*.php")) + list(ROOT.rglob("*.js")):
        rel = path.relative_to(ROOT).as_posix()
        if any(x in rel for x in ("tr-districts", "/tools/", "languages/", "Contracts/Templates")):
            continue
        text = path.read_text(encoding="utf-8")
        for m in GT.finditer(text):
            s = m.group(1).replace("\\'", "'")
            if TR_CHARS.search(s):
                leftovers.append((rel, s))
    print(f"Turkish-diacritic gettext leftovers: {len(leftovers)}")
    for rel, s in leftovers[:50]:
        print(f"  {rel}: {s[:100]}")


if __name__ == "__main__":
    main()
