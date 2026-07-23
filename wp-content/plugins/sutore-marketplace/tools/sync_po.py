#!/usr/bin/env python3
"""Regenerate complete tr_TR.po from code msgids + translation sources."""
from __future__ import annotations

import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
TOOLS = ROOT / "tools"
PO = ROOT / "languages" / "sutore-marketplace-tr_TR.po"
MERGED = TOOLS / "_i18n_map_merged.json"
MANUAL = TOOLS / "_manual_tr.json"
OVERRIDES = TOOLS / "_po_overrides.json"

SKIP_PARTS = (
    "tr-districts-data",
    "/tools/",
    "node_modules",
    "pre-information-en.php",
    "distance-sales-en.php",
)


def po_escape(s: str) -> str:
    return (
        s.replace("\\", "\\\\")
        .replace('"', '\\"')
        .replace("\n", "\\n")
        .replace("\t", "\\t")
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
    if not path.is_file():
        return {}
    text = path.read_text(encoding="utf-8")
    entries: dict[str, str] = {}
    for block in re.split(r"\n\n+", text):
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


def extract_msgids() -> set[str]:
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
    for path in list(ROOT.rglob("*.php")) + list(ROOT.rglob("*.js")):
        rel = path.relative_to(ROOT).as_posix()
        if should_skip(rel):
            continue
        text = path.read_text(encoding="utf-8", errors="replace")
        for rx in patterns:
            for m in rx.finditer(text):
                if rx.pattern.startswith("_n"):
                    for g in (1, 2):
                        found.add(m.group(g).replace("\\'", "'"))
                else:
                    found.add(
                        m.group(1).replace("\\'", "'").replace('\\"', '"')
                    )
    found.discard("")
    return found


def load_en_to_tr() -> dict[str, str]:
    merged: dict[str, str] = {}
    if MERGED.is_file():
        tr_to_en: dict[str, str] = json.loads(MERGED.read_text(encoding="utf-8"))
        for tr, en in tr_to_en.items():
            if en and en not in merged:
                merged[en] = tr
    if MANUAL.is_file():
        manual: dict[str, str] = json.loads(MANUAL.read_text(encoding="utf-8"))
        merged.update(manual)
    if OVERRIDES.is_file():
        overrides: dict[str, str] = json.loads(OVERRIDES.read_text(encoding="utf-8"))
        merged.update(overrides)
    return merged


def compile_mo(po_path: Path, mo_path: Path) -> None:
    import struct

    entries: list[tuple[bytes, bytes]] = []
    text = po_path.read_text(encoding="utf-8")
    for block in re.split(r"\n\n+", text):
        mid = re.search(r'^msgid "(.*)"\s*$', block, re.M)
        mstr = re.search(r'^msgstr "(.*)"\s*$', block, re.M)
        if not mid or not mstr:
            continue
        msgid = unescape_po(mid.group(1)).encode("utf-8")
        msgstr = unescape_po(mstr.group(1)).encode("utf-8")
        entries.append((msgid, msgstr))

    header = next((e for e in entries if e[0] == b""), (b"", b""))
    rest = sorted([e for e in entries if e[0] != b""], key=lambda e: e[0])
    ordered = ([header] if header[0] == b"" else []) + rest

    kcount = len(ordered)
    key_table_offset = 28
    val_table_offset = key_table_offset + 8 * kcount
    strings_offset = val_table_offset + 8 * kcount

    key_blob = bytearray()
    val_blob = bytearray()
    key_meta: list[tuple[int, int]] = []
    val_meta: list[tuple[int, int]] = []

    for k, v in ordered:
        key_meta.append((len(k), strings_offset + len(key_blob)))
        key_blob += k + b"\0"

    val_base = strings_offset + len(key_blob)
    for k, v in ordered:
        val_meta.append((len(v), val_base + len(val_blob)))
        val_blob += v + b"\0"

    out = bytearray()
    out += struct.pack(
        "<Iiiiiii",
        0x950412DE,
        0,
        kcount,
        key_table_offset,
        val_table_offset,
        0,
        0,
    )
    for length, offset in key_meta:
        out += struct.pack("<II", length, offset)
    for length, offset in val_meta:
        out += struct.pack("<II", length, offset)
    out += key_blob
    out += val_blob
    mo_path.write_bytes(bytes(out))


def main() -> None:
    msgids = extract_msgids()
    existing = parse_po(PO)
    en_to_tr = load_en_to_tr()

    translations: dict[str, str] = {}
    still_missing: list[str] = []

    for en in sorted(msgids):
        if en in existing and existing[en] and existing[en] != en:
            translations[en] = existing[en]
        elif en in en_to_tr:
            translations[en] = en_to_tr[en]
        elif en in existing:
            translations[en] = existing[en]
        else:
            still_missing.append(en)
            translations[en] = en  # placeholder

    # Manual overrides for identity / technical strings
    manual: dict[str, str] = {
        "ID": "ID",
        "asking": "asking",
        "%s TL": "%s TL",
    }
    for en, tr in manual.items():
        if en in translations:
            translations[en] = tr

    lines = [
        'msgid ""',
        'msgstr ""',
        '"Project-Id-Version: Sutore Marketplace\\n"',
        '"Language: tr_TR\\n"',
        '"MIME-Version: 1.0\\n"',
        '"Content-Type: text/plain; charset=UTF-8\\n"',
        '"Content-Transfer-Encoding: 8bit\\n"',
        '"X-Domain: sutore-marketplace\\n"',
        "",
    ]

    for en in sorted(translations.keys()):
        tr = translations[en]
        if tr == en:
            continue
        lines.append(f'msgid "{po_escape(en)}"')
        lines.append(f'msgstr "{po_escape(tr)}"')
        lines.append("")

    PO.parent.mkdir(exist_ok=True)
    PO.write_text("\n".join(lines), encoding="utf-8")
    compile_mo(PO, PO.with_suffix(".mo"))

    out_missing = TOOLS / "_still_missing_tr.json"
    out_missing.write_text(
        json.dumps(still_missing, ensure_ascii=False, indent=2),
        encoding="utf-8",
    )

    print(f"msgids in code: {len(msgids)}")
    print(f"written PO entries (en!=tr): {sum(1 for e,t in translations.items() if e!=t)}")
    print(f"still missing TR: {len(still_missing)}")
    print(f"wrote {PO}")
    print(f"wrote {PO.with_suffix('.mo')}")


if __name__ == "__main__":
    main()
