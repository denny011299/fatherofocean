#!/usr/bin/env python3
"""Restore fullest PHP files from agent transcript Write/StrReplace ops."""
from __future__ import annotations

import json
import re
from pathlib import Path

ROOT = Path(r"C:\Pelayaran")
TRANSCRIPTS = Path(r"C:\Users\denny\.cursor\projects\c-Pelayaran\agent-transcripts")

TARGETS = {
    "app/Models/Marking.php",
    "app/Support/TerimaBarangPdfData.php",
    "app/Support/MarkingPricingResolver.php",
    "app/Support/Locales.php",
    "app/Models/Invoice.php",
}


def rel_path(path: str) -> str | None:
    path = path.replace("\\", "/")
    match = re.search(r"(app/[^'\"]+\.php)", path, re.IGNORECASE)
    return match.group(1) if match else None


def main() -> None:
    contents: dict[str, str] = {}
    scores: dict[str, int] = {}

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
                if rel is None or rel not in TARGETS:
                    continue
                if name == "Write":
                    content = inp.get("contents", "")
                    score = len(content)
                    if score >= scores.get(rel, 0):
                        contents[rel] = content
                        scores[rel] = score
                elif name == "StrReplace" and rel in contents:
                    old = inp.get("old_string", "")
                    new = inp.get("new_string", "")
                    if old and old in contents[rel]:
                        contents[rel] = contents[rel].replace(old, new, 1)
                        scores[rel] = len(contents[rel])

    for rel in sorted(TARGETS):
        if rel in contents:
            out = ROOT / rel
            out.parent.mkdir(parents=True, exist_ok=True)
            out.write_text(contents[rel], encoding="utf-8")
            print(f"RESTORED {rel} ({len(contents[rel])} bytes)")
        else:
            print(f"MISSING {rel}")


if __name__ == "__main__":
    main()
