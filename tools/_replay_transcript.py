#!/usr/bin/env python3
"""Replay Write + StrReplace ops chronologically for a PHP file."""
from __future__ import annotations

import json
import re
import sys
from pathlib import Path

TRANSCRIPTS = Path(r"C:\Users\denny\.cursor\projects\c-Pelayaran\agent-transcripts")
ROOT = Path(r"C:\Pelayaran")


def rel_path(path: str) -> str | None:
    path = path.replace("\\", "/")
    match = re.search(r"(app/[^'\"]+\.php)", path, re.IGNORECASE)
    return match.group(1) if match else None


def replay(target_rel: str) -> str | None:
    content: str | None = None
    ops = 0

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
                inp = part.get("input")
                if not isinstance(inp, dict):
                    continue
                rel = rel_path(inp.get("path", ""))
                if rel != target_rel:
                    continue
                if name == "Write":
                    content = inp.get("contents", "")
                    ops += 1
                elif name == "StrReplace" and content is not None:
                    old = inp.get("old_string", "")
                    new = inp.get("new_string", "")
                    if old and old in content:
                        content = content.replace(old, new, 1)
                        ops += 1

    print(f"{target_rel}: {ops} ops, len={len(content or '')}")
    return content


def main() -> None:
    targets = sys.argv[1:] or [
        "app/Models/Marking.php",
        "app/Support/TerimaBarangPdfData.php",
        "app/Support/MarkingPricingResolver.php",
        "app/Models/Invoice.php",
    ]
    for target in targets:
        content = replay(target)
        if content:
            out = ROOT / target
            out.parent.mkdir(parents=True, exist_ok=True)
            out.write_text(content, encoding="utf-8")
            print(f"  -> wrote {out}")


if __name__ == "__main__":
    main()
