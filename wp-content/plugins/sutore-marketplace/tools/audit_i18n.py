#!/usr/bin/env python3
"""Audit i18n: extract msgids from code, compare with .po, find hardcoded strings."""
from __future__ import annotations

import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
TOOLS = ROOT / "tools"
PO = ROOT / "languages" / "sutore-marketplace-tr_TR.po"

SKIP_PARTS = (
    "tr-districts-data",
    "/tools/",
    "node_modules",
    "pre-information-en.php",
    "distance-sales-en.php",
)


def unescape_po(s: str) -> str:
    out: list[str] = []
    i = 0
    while i < len(s):
        if s[i] == "\\" and i + 1 < len(s):
            n = s[i + 1]
            mapping = {"n": "\n", "t": "\t", '"': '"', "\\": "\\"}
            out.append(mapping.get(n, n))
            i += 2
        else:
            out.append(s[i])
            i += 1
    return "".join(out)


def parse_po(path: Path) -> dict[str, str]:
    text = path.read_text(encoding="utf-8")
    entries: dict[str, str] = {}
    blocks = re.split(r"\n\n+", text)
    for block in blocks:
        mid = re.search(r'^msgid "(.*)"\s*$', block, re.M)
        mstr = re.search(r'^msgstr "(.*)"\s*$', block, re.M)
        if not mid or not mstr:
            continue
        msgid = unescape_po(mid.group(1))
        msgstr = unescape_po(mstr.group(1))
        if msgid:
            entries[msgid] = msgstr
    return entries


def should_skip(rel: str) -> bool:
    return any(p in rel for p in SKIP_PARTS)


def extract_from_file(path: Path) -> set[str]:
    text = path.read_text(encoding="utf-8", errors="replace")
    found: set[str] = set()

    patterns = [
        re.compile(
            r"(?:__|_e|esc_html__|esc_attr__|_x)\(\s*'((?:\\'|[^'])*)'\s*,\s*'sutore-marketplace'"
        ),
        re.compile(
            r'(?:__|_e|esc_html__|esc_attr__|_x)\(\s*"((?:\\"|[^"])*)"\s*,\s*"sutore-marketplace"'
        ),
        re.compile(r"_n\(\s*'((?:\\'|[^'])*)'\s*,\s*'((?:\\'|[^'])*)'"),
        re.compile(r"t\(\s*'[^']+'\s*,\s*'((?:\\'|[^'])*)'"),
        re.compile(r't\(\s*"[^"]+"\s*,\s*"((?:\\"|[^"])*)"'),
    ]

    for rx in patterns:
        for m in rx.finditer(text):
            if rx.pattern.startswith("_n"):
                for g in (1, 2):
                    s = m.group(g).replace("\\'", "'")
                    found.add(s)
            else:
                s = m.group(1).replace("\\'", "'").replace('\\"', '"')
                found.add(s)

    return found


def find_hardcoded_php(path: Path) -> list[str]:
    text = path.read_text(encoding="utf-8", errors="replace")
    issues: list[str] = []
    # WP_Error messages without __
    for m in re.finditer(
        r"new\s+WP_Error\([^,]+,\s*'((?:\\'|[^'])*)'",
        text,
    ):
        s = m.group(1).replace("\\'", "'")
        if len(s) > 3 and not s.startswith("http"):
            issues.append(f"WP_Error: {s[:80]}")
    # throw new Exception
    for m in re.finditer(r"throw new \w+Exception\(\s*'((?:\\'|[^'])*)'", text):
        s = m.group(1).replace("\\'", "'")
        if len(s) > 3:
            issues.append(f"Exception: {s[:80]}")
    return issues


def find_hardcoded_js(path: Path) -> list[str]:
    text = path.read_text(encoding="utf-8", errors="replace")
    issues: list[str] = []
    for m in re.finditer(
        r"(?:alert|\.text|innerHTML|textContent)\(\s*['\"]([A-Za-z][^'\"]{4,})['\"]",
        text,
    ):
        s = m.group(1)
        ctx = text[max(0, m.start() - 30) : m.start()]
        if "t(" in ctx or s in ("true", "false", "GET", "POST", "PUT", "DELETE"):
            continue
        issues.append(s[:80])
    # i18n fallbacks like `i18n.foo || 'English'`
    for m in re.finditer(r"\|\|\s*['\"]([A-Za-z][^'\"]{4,})['\"]", text):
        s = m.group(1)
        if "Loading" in s or "Error" in s or "Save" in s or "Cancel" in s:
            issues.append(f"fallback: {s[:80]}")
    return issues


def main() -> None:
    po_entries = parse_po(PO)
    po_msgids = set(po_entries.keys())

    extracted: set[str] = set()
    turkish_msgids: list[tuple[str, str]] = []
    hard_php: list[tuple[str, str]] = []
    hard_js: list[tuple[str, str]] = []

    for path in list(ROOT.rglob("*.php")) + list(ROOT.rglob("*.js")):
        rel = path.relative_to(ROOT).as_posix()
        if should_skip(rel):
            continue
        for s in extract_from_file(path):
            extracted.add(s)
            if any(c in s for c in "çğıöşüÇĞİÖŞÜ"):
                turkish_msgids.append((rel, s))
        if path.suffix == ".php" and "tools/" not in rel:
            for issue in find_hardcoded_php(path):
                hard_php.append((rel, issue))
        if path.suffix == ".js" and ("assets/" in rel or "fulfillment/" in rel):
            for issue in find_hardcoded_js(path):
                hard_js.append((rel, issue))

    missing_in_po = sorted(extracted - po_msgids)
    untranslated = sorted(k for k, v in po_entries.items() if k and v == k)
    empty_tr = sorted(k for k, v in po_entries.items() if k and not v.strip())

    print(f"Extracted msgids from code: {len(extracted)}")
    print(f"PO entries: {len(po_msgids)}")
    print(f"Missing in PO: {len(missing_in_po)}")
    print(f"Untranslated (msgstr==msgid): {len(untranslated)}")
    print(f"Empty msgstr: {len(empty_tr)}")
    print(f"Turkish msgids in code: {len(turkish_msgids)}")
    print(f"Hardcoded PHP issues: {len(hard_php)}")
    print(f"Hardcoded JS issues: {len(hard_js)}")

    if missing_in_po:
        out = TOOLS / "_missing_in_po.json"
        import json

        out.write_text(
            json.dumps(missing_in_po, ensure_ascii=False, indent=2),
            encoding="utf-8",
        )
        print(f"\nWrote {len(missing_in_po)} missing keys to {out}")

    if untranslated:
        print("\n--- UNTRANSLATED (msgstr == msgid) ---")
        for s in untranslated[:40]:
            print(repr(s))
        if len(untranslated) > 40:
            print(f"... and {len(untranslated) - 40} more")

    if turkish_msgids:
        print("\n--- TURKISH MSGIDS ---")
        for rel, s in turkish_msgids:
            print(f"  {rel}: {repr(s[:100])}")

    if hard_php:
        print("\n--- HARDCODED PHP (sample) ---")
        for rel, s in hard_php[:30]:
            print(f"  {rel}: {s}")

    if hard_js:
        print("\n--- HARDCODED JS ---")
        for rel, s in hard_js:
            print(f"  {rel}: {s}")


if __name__ == "__main__":
    main()
