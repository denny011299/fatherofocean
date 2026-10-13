#!/usr/bin/env python3
"""Replay Write/StrReplace ops from agent transcripts to restore barang edit PHP files."""
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
}


def rel_path(path: str) -> str | None:
    path = path.replace("\\", "/")
    m = re.search(r"(app/[^'\"]+\.php)", path)
    return m.group(1) if m else None


def main() -> None:
    contents: dict[str, str] = {}
    order: list[tuple[str, str, str]] = []

    for jsonl in sorted(TRANSCRIPTS.rglob("*.jsonl")):
        try:
            lines = jsonl.read_text(encoding="utf-8").splitlines()
        except OSError:
            continue
        for line in lines:
            if "app/" not in line:
                continue
            try:
                obj = json.loads(line)
            except json.JSONDecodeError:
                continue
            for part in obj.get("message", {}).get("content", []):
                if part.get("type") != "tool_use":
                    continue
                name = part.get("name", "")
                raw_inp = part.get("input")
                if not isinstance(raw_inp, dict):
                    continue
                inp = raw_inp
                path = inp.get("path", "")
                rel = rel_path(path)
                if rel is None or rel not in TARGETS:
                    continue
                if name in ("Write", "WriteFile"):
                    contents[rel] = inp.get("contents", "")
                    order.append((str(jsonl), rel, name))
                elif name == "StrReplace" and rel in contents:
                    old = inp.get("old_string", "")
                    new = inp.get("new_string", "")
                    if old in contents[rel]:
                        contents[rel] = contents[rel].replace(old, new, 1)
                        order.append((str(jsonl), rel, "StrReplace OK"))
                    else:
                        order.append((str(jsonl), rel, "StrReplace FAILED"))

    for rel in sorted(TARGETS):
        if rel in contents:
            out = ROOT / rel
            out.parent.mkdir(parents=True, exist_ok=True)
            out.write_text(contents[rel], encoding="utf-8")
            print(f"Wrote {rel} ({len(contents[rel])} bytes)")
        else:
            print(f"MISSING {rel}")

    print(f"\nTotal ops: {len(order)}")
    for entry in order[-15:]:
        print(" ", entry)


if __name__ == "__main__":
    main()
