#!/usr/bin/env python3
"""Restore app PHP files from agent transcripts (Write + StrReplace in order)."""
from __future__ import annotations

import json
import re
from pathlib import Path

ROOT = Path(r"C:\Pelayaran")
TRANSCRIPTS = Path(r"C:\Users\denny\.cursor\projects\c-Pelayaran\agent-transcripts")

TARGET_PREFIX = "app/"


def rel_path(path: str) -> str | None:
    path = path.replace("\\", "/")
    match = re.search(r"(app/[^'\"]+\.php)", path, re.IGNORECASE)
    return match.group(1) if match else None


def main() -> None:
    contents: dict[str, str] = {}
    ops: list[tuple[str, str, str]] = []

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
                if rel is None:
                    continue
                src = jsonl.name
                if name == "Write":
                    contents[rel] = inp.get("contents", "")
                    ops.append((src, rel, f"Write {len(contents[rel])}b"))
                elif name == "StrReplace" and rel in contents:
                    old = inp.get("old_string", "")
                    new = inp.get("new_string", "")
                    if old in contents[rel]:
                        contents[rel] = contents[rel].replace(old, new, 1)
                        ops.append((src, rel, "StrReplace OK"))
                    else:
                        ops.append((src, rel, "StrReplace FAILED"))

    for rel, body in sorted(contents.items()):
        out = ROOT / rel
        out.parent.mkdir(parents=True, exist_ok=True)
        out.write_text(body, encoding="utf-8")
        print(f"Wrote {rel} ({len(body)} bytes)")

    print(f"\nTotal files: {len(contents)}")
    for rel in [
        "app/Support/ShippingLabelPdfData.php",
        "app/Support/BarangShippingPricing.php",
        "app/Http/Controllers/ExportController.php",
    ]:
        rel_ops = [o for o in ops if o[1] == rel]
        print(f"\n{rel}: {len(rel_ops)} ops")
        for o in rel_ops[-5:]:
            print(" ", o)


if __name__ == "__main__":
    main()
