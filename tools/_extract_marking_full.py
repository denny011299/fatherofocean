#!/usr/bin/env python3
from __future__ import annotations

import json
from pathlib import Path

TRANSCRIPTS = Path(r"C:\Users\denny\.cursor\projects\c-Pelayaran\agent-transcripts")
TARGET = "Marking.php"

best = ""
best_src = ""

for jsonl in sorted(TRANSCRIPTS.rglob("*.jsonl")):
    for line in jsonl.read_text(encoding="utf-8", errors="ignore").splitlines():
        if TARGET not in line:
            continue
        try:
            obj = json.loads(line)
        except json.JSONDecodeError:
            continue
        for part in obj.get("message", {}).get("content", []):
            if part.get("type") != "tool_use" or part.get("name") != "Write":
                continue
            inp = part.get("input") or {}
            if not str(inp.get("path", "")).endswith(TARGET):
                continue
            content = inp.get("contents", "")
            if len(content) > len(best):
                best = content
                best_src = str(jsonl)

print(f"best={len(best)} from {best_src}")
if best:
    Path(r"C:\Pelayaran\tools\_extracted_Marking_full.php").write_text(best, encoding="utf-8")
    print("markers:", {m: m in best for m in [
        "visibleToUser", "restrictBarangsQuery", "whereBarangMarkingValue",
        "lastShippingAtSubquery", "schemaColumnCache"
    ]})
