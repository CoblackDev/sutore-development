#!/usr/bin/env python3
"""Apply TR→EN msgid map across plugin and generate tr_TR.po."""
from __future__ import annotations

import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
TOOLS = ROOT / "tools"


def load_map() -> dict[str, str]:
    merged: dict[str, str] = {}
    for i in range(4):
        path = TOOLS / f"_map_{i}.json"
        data = json.loads(path.read_text(encoding="utf-8"))
        if not isinstance(data, dict):
            raise SystemExit(f"{path} is not an object")
        merged.update(data)

    extract = json.loads((TOOLS / "_i18n_extract.json").read_text(encoding="utf-8"))
    missing = [k for k in extract if k not in merged]
    if missing:
        raise SystemExit(f"Missing {len(missing)} keys, e.g. {missing[:5]}")

    # Identity-only keys still ok; detect map bugs where EN empty
    empty = [k for k, v in merged.items() if not str(v).strip()]
    if empty:
        raise SystemExit(f"Empty translations: {empty[:5]}")

    return merged


def php_escape(s: str) -> str:
    return s.replace("\\", "\\\\").replace("'", "\\'")


def po_escape(s: str) -> str:
    return (
        s.replace("\\", "\\\\")
        .replace('"', '\\"')
        .replace("\n", "\\n")
        .replace("\t", "\\t")
    )


def replace_in_text(text: str, mapping: dict[str, str]) -> tuple[str, int]:
    """Replace msgids inside gettext calls and JS t() fallbacks."""
    count = 0

    # Sort longest first to avoid partial overlaps
    items = sorted(mapping.items(), key=lambda kv: len(kv[0]), reverse=True)

    def sub_sq(match: re.Match[str], group_tr: int) -> str:
        nonlocal count
        tr = match.group(group_tr)
        # unescape for lookup
        key = tr.replace("\\'", "'").replace("\\\\", "\\")
        if key not in mapping:
            return match.group(0)
        en = php_escape(mapping[key])
        count += 1
        return match.group(0).replace(f"'{tr}'", f"'{en}'", 1)

    # __( 'tr', 'sutore-marketplace' ) style — single-quoted msgid
    gettext_re = re.compile(
        r"((?:__|_e|esc_html__|esc_attr__|_x)\(\s*)'((?:\\'|[^'])*)'(\s*,\s*'sutore-marketplace')"
    )

    def gt_repl(m: re.Match[str]) -> str:
        nonlocal count
        key = m.group(2).replace("\\'", "'")
        if key not in mapping:
            return m.group(0)
        en = php_escape(mapping[key])
        if mapping[key] == key:
            return m.group(0)
        count += 1
        return f"{m.group(1)}'{en}'{m.group(3)}"

    text2 = gettext_re.sub(gt_repl, text)

    # _n( 'singular', 'plural', … ) — both strings may be Turkish; domain may follow later
    n_re = re.compile(
        r"((_n|_nx)\(\s*)'((?:\\'|[^'])*)'(\s*,\s*)'((?:\\'|[^'])*)'"
    )

    def n_repl(m: re.Match[str]) -> str:
        nonlocal count
        k1 = m.group(3).replace("\\'", "'")
        k2 = m.group(5).replace("\\'", "'")
        e1 = php_escape(mapping.get(k1, k1))
        e2 = php_escape(mapping.get(k2, k2))
        changed = False
        if k1 in mapping and mapping[k1] != k1:
            changed = True
        if k2 in mapping and mapping[k2] != k2:
            changed = True
        if not changed:
            return m.group(0)
        count += 1
        return f"{m.group(1)}'{e1}'{m.group(4)}'{e2}'"

    text2 = n_re.sub(n_repl, text2)

    # JS: t('key', 'Turkish fallback') or t("key", "…")
    js_t = re.compile(
        r"(t\(\s*(['\"])[^'\"]+\2\s*,\s*)'((?:\\'|[^'])*)'"
    )

    def js_repl(m: re.Match[str]) -> str:
        nonlocal count
        key = m.group(3).replace("\\'", "'")
        if key not in mapping or mapping[key] == key:
            return m.group(0)
        en = php_escape(mapping[key])
        count += 1
        return f"{m.group(1)}'{en}'"

    text2 = js_t.sub(js_repl, text2)

    return text2, count


def write_po(mapping: dict[str, str]) -> None:
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
    # msgid = English, msgstr = Turkish original
    # Only when they differ
    for tr, en in sorted(mapping.items(), key=lambda kv: kv[1]):
        if en == tr:
            continue
        lines.append(f'msgid "{po_escape(en)}"')
        lines.append(f'msgstr "{po_escape(tr)}"')
        lines.append("")

    lang = ROOT / "languages"
    lang.mkdir(exist_ok=True)
    po_path = lang / "sutore-marketplace-tr_TR.po"
    po_path.write_text("\n".join(lines), encoding="utf-8")
    print(f"wrote {po_path} ({sum(1 for tr,en in mapping.items() if tr!=en)} entries)")


def compile_mo(po_path: Path, mo_path: Path) -> None:
    """Minimal .mo writer for simple msgid/msgstr pairs (no plurals/contexts)."""
    entries: list[tuple[bytes, bytes]] = []
    text = po_path.read_text(encoding="utf-8")
    # parse simple pairs
    blocks = re.split(r"\n\n+", text)
    for block in blocks:
        mid = re.search(r'^msgid "(.*)"\s*$', block, re.M)
        mstr = re.search(r'^msgstr "(.*)"\s*$', block, re.M)
        if not mid or not mstr:
            continue
        msgid = bytes(mid.group(1), "utf-8").decode("unicode_escape").encode("utf-8") if False else _unescape_po(mid.group(1)).encode("utf-8")
        msgstr = _unescape_po(mstr.group(1)).encode("utf-8")
        if msgid == b"":
            # header
            entries.insert(0, (msgid, msgstr))
        else:
            entries.append((msgid, msgstr))

    # Sort by msgid for binary search
    # Keep header first
    header = entries[0] if entries and entries[0][0] == b"" else (b"", b"")
    rest = [e for e in entries if e[0] != b""]
    rest.sort(key=lambda e: e[0])
    ordered = [header] + rest if header[0] == b"" else rest

    # Build MO (GNU)
    keys = b""
    values = b""
    key_offsets: list[tuple[int, int]] = []
    val_offsets: list[tuple[int, int]] = []
    for k, v in ordered:
        key_offsets.append((len(keys), len(k)))
        keys += k + b"\0"
        val_offsets.append((len(values), len(v)))
        values += v + b"\0"

    kcount = len(ordered)
    header_size = 28
    # table of key offsets then value offsets: each entry 8 bytes (len, offset)
    o_keys = header_size
    o_vals = o_keys + kcount * 8
    o_data = o_vals + kcount * 8

    import struct

    out = bytearray()
    out += struct.pack("<Iiiiiii", 0x950412DE, 0, kcount, o_keys, o_vals, 0, o_data)
    # Actually original format: magic, revision, nstrings, orig_tab_offset, trans_tab_offset, hash_size, hash_offset
    # Fix: revision=0
    out = bytearray()
    key_table_offset = 28
    val_table_offset = key_table_offset + 8 * kcount
    strings_offset = val_table_offset + 8 * kcount

    # rebuild with correct absolute offsets
    key_blob = bytearray()
    val_blob = bytearray()
    key_meta: list[tuple[int, int]] = []
    val_meta: list[tuple[int, int]] = []
    for k, v in ordered:
        key_meta.append((len(k), strings_offset + len(key_blob)))
        key_blob += k + b"\0"
        # values come after all keys
    val_base = strings_offset + len(key_blob)
    for k, v in ordered:
        val_meta.append((len(v), val_base + len(val_blob)))
        val_blob += v + b"\0"

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
    print(f"wrote {mo_path}")


def _unescape_po(s: str) -> str:
    out = []
    i = 0
    while i < len(s):
        if s[i] == "\\" and i + 1 < len(s):
            n = s[i + 1]
            if n == "n":
                out.append("\n")
            elif n == "t":
                out.append("\t")
            elif n == '"':
                out.append('"')
            elif n == "\\":
                out.append("\\")
            else:
                out.append(n)
            i += 2
        else:
            out.append(s[i])
            i += 1
    return "".join(out)


def main() -> None:
    mapping = load_map()
    (TOOLS / "_i18n_map_merged.json").write_text(
        json.dumps(mapping, ensure_ascii=False, indent=2), encoding="utf-8"
    )
    print(f"merged map keys={len(mapping)}")

    total = 0
    files = 0
    for path in list(ROOT.rglob("*.php")) + list(ROOT.rglob("*.js")):
        rel = path.relative_to(ROOT).as_posix()
        if "tr-districts-data" in rel or "/tools/" in f"/{rel}" or "node_modules" in rel:
            continue
        if path.suffix == ".js" and "assets" not in rel and "fulfillment" not in rel:
            # only asset JS
            if not rel.startswith("assets/"):
                continue
        original = path.read_text(encoding="utf-8")
        updated, n = replace_in_text(original, mapping)
        if n:
            path.write_text(updated, encoding="utf-8", newline="\n")
            total += n
            files += 1
            print(f"  {rel}: {n}")

    print(f"updated {files} files, {total} replacements")
    write_po(mapping)
    compile_mo(
        ROOT / "languages" / "sutore-marketplace-tr_TR.po",
        ROOT / "languages" / "sutore-marketplace-tr_TR.mo",
    )


if __name__ == "__main__":
    main()
