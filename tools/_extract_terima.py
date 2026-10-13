#!/usr/bin/env python3
from __future__ import annotations

import json
import re
from pathlib import Path

TRANSCRIPTS = Path(r"C:\Users\denny\.cursor\projects\c-Pelayaran\agent-transcripts")
TARGET = "app/Support/TerimaBarangPdfData.php"

best = ""
best_src = ""

for jsonl in sorted(TRANSCRIPTS.rglob("*.jsonl")):
    for line in jsonl.read_text(encoding="utf-8", errors="ignore").splitlines():
        if "TerimaBarangPdfData.php" not in line:
            continue
        try:
            obj = json.loads(line)
        except json.JSONDecodeError:
            continue
        for part in obj.get("message", {}).get("content", []):
            if part.get("type") != "tool_use" or part.get("name") != "Write":
                continue
            inp = part.get("input") or {}
            if "TerimaBarangPdfData.php" not in str(inp.get("path", "")):
                continue
            content = inp.get("contents", "")
            if len(content) > len(best):
                best = content
                best_src = str(jsonl)

print(f"best={len(best)} from {best_src}")
if best:
    Path(r"C:\Pelayaran\tools\_extracted_TerimaBarangPdfData.php").write_text(best, encoding="utf-8")
