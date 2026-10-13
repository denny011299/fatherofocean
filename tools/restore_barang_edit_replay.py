#!/usr/bin/env python3
"""Restore barang edit PHP files from agent transcript Write + StrReplace ops."""
from __future__ import annotations

import json
import re
from pathlib import Path

ROOT = Path(r"C:\Pelayaran")
TRANSCRIPTS = Path(r"C:\Users\denny\.cursor\projects\c-Pelayaran\agent-transcripts")
TARGETS = {
    "app/Support/BarangEditRequestService.php",
    "app/Http/Controllers/BarangController.php",
    "app/Http/Controllers/BarangEditRequestController.php",
    "app/Models/Barang.php",
    "app/Models/BarangEditRequest.php",
    "app/Models/BarangProduct.php",
    "app/Support/BarangUpdateExecutor.php",
}


def rel_path(path: str) -> str | None:
    path = path.replace("\\", "/")
    m = re.search(r"(app/[^'\"]+\.php)", path)
    return m.group(1) if m else None


def iter_ops():
    for jsonl in sorted(TRANSCRIPTS.rglob("*.jsonl")):
        try:
            lines = jsonl.read_text(encoding="utf-8").splitlines()
        except OSError:
            continue
        for line in lines:
            if "app/" not in line and "app\\" not in line:
                continue
            try:
                obj = json.loads(line)
            except json.JSONDecodeError:
                continue
            for part in obj.get("message", {}).get("content", []):
                if part.get("type") != "tool_use":
                    continue
                name = part.get("name", "")
                inp = part.get("input")
                if not isinstance(inp, dict):
                    continue
                rel = rel_path(inp.get("path", ""))
                if rel is None or rel not in TARGETS:
                    continue
                yield jsonl.name, name, rel, inp


def main() -> None:
    contents: dict[str, str] = {}
    log: list[str] = []

    for src, name, rel, inp in iter_ops():
        if name in ("Write", "WriteFile"):
            contents[rel] = inp.get("contents", "")
            log.append(f"{src} {name} {rel} ({len(contents[rel])} bytes)")
        elif name == "StrReplace":
            if rel not in contents:
                log.append(f"{src} StrReplace SKIP {rel}")
                continue
            old = inp.get("old_string", "")
            new = inp.get("new_string", "")
            if old in contents[rel]:
                contents[rel] = contents[rel].replace(old, new, 1)
                log.append(f"{src} StrReplace OK {rel}")
            else:
                log.append(f"{src} StrReplace FAILED {rel}")

    for rel in sorted(TARGETS):
        if rel in contents:
            out = ROOT / rel
            out.parent.mkdir(parents=True, exist_ok=True)
            out.write_text(contents[rel], encoding="utf-8")
            print(f"Wrote {rel} ({len(contents[rel])} bytes)")
        else:
            print(f"MISSING {rel}")

    print(f"\nOps: {len(log)}")
    for line in log:
        print(" ", line)


if __name__ == "__main__":
    main()
