#!/usr/bin/env python3
"""Replay Write/StrReplace ops from agent transcripts to restore app/ PHP files."""
from __future__ import annotations

import json
import re
from pathlib import Path

ROOT = Path(r"C:\Pelayaran")
TRANSCRIPTS = Path(r"C:\Users\denny\.cursor\projects\c-Pelayaran\agent-transcripts")
TARGET_FILES = {
    "app/Exports/BarangListProductsExport.php",
    "app/Support/ExcelDecimalFormat.php",
    "app/helpers.php",
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
                if rel is None or rel not in TARGET_FILES:
                    continue
                if name == "Write":
                    contents[rel] = inp.get("contents", "")
                    order.append((str(jsonl), rel, "Write"))
                elif name == "StrReplace" and rel in contents:
                    old = inp.get("old_string", "")
                    new = inp.get("new_string", "")
                    if old in contents[rel]:
                        contents[rel] = contents[rel].replace(old, new, 1)
                        order.append((str(jsonl), rel, "StrReplace OK"))
                    else:
                        order.append((str(jsonl), rel, "StrReplace FAILED"))

    for rel, body in contents.items():
        out = ROOT / rel
        out.parent.mkdir(parents=True, exist_ok=True)
        out.write_text(body, encoding="utf-8")
        print(f"Wrote {rel} ({len(body)} bytes)")

    print(f"\nOps logged: {len(order)}")
    for entry in order[-20:]:
        print(" ", entry)


if __name__ == "__main__":
    main()
