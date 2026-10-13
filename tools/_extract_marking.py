#!/usr/bin/env python3
"""Extract fullest Marking.php from agent transcripts."""
from __future__ import annotations

import json
from pathlib import Path

TRANSCRIPTS = Path(r"C:\Users\denny\.cursor\projects\c-Pelayaran\agent-transcripts")
ROOT = Path(r"C:\Pelayaran")

NEEDED_MARKERS = [
    "visibleToUser",
    "restrictBarangsQuery",
    "whereBarangMarkingValue",
    "lastShippingAtSubquery",
]

best: str | None = None
best_score = 0

for jsonl in sorted(TRANSCRIPTS.rglob("*.jsonl")):
    try:
        lines = jsonl.read_text(encoding="utf-8").splitlines()
    except OSError:
        continue
    for line in lines:
        if "Marking.php" not in line:
            continue
        try:
            obj = json.loads(line)
        except json.JSONDecodeError:
            continue
        for part in obj.get("message", {}).get("content", []):
            if part.get("type") != "tool_use" or part.get("name") != "Write":
                continue
            inp = part.get("input") or {}
            path = str(inp.get("path", "")).replace("\\", "/")
            if not path.endswith("app/Models/Marking.php"):
                continue
            content = inp.get("contents", "")
            score = sum(1 for m in NEEDED_MARKERS if m in content) * 10000 + len(content)
            if score > best_score:
                best = content
                best_score = score
                print(f"candidate {jsonl.name}: score={score} len={len(content)}")

if best:
    out = ROOT / "tools" / "_extracted_Marking.php"
    out.write_text(best, encoding="utf-8")
    print(f"Wrote {out} ({len(best)} bytes, score={best_score})")
else:
    print("No Marking.php found")
