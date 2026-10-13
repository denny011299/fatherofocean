#!/usr/bin/env python3
"""Replay ALL transcript ops for MarkingController.php in chronological order."""
from __future__ import annotations

import json
from pathlib import Path

TRANSCRIPTS = Path(r"C:\Users\denny\.cursor\projects\c-Pelayaran\agent-transcripts")
OUT = Path(r"C:\Pelayaran\tools\_reconstructed_MarkingController.php")


def rel_path(path: str) -> str | None:
    path = path.replace("\\", "/")
    return "app/Http/Controllers/MarkingController.php" if "MarkingController.php" in path else None


ops: list[tuple[int, str, str, str | None]] = []
order = 0

for jsonl in sorted(TRANSCRIPTS.rglob("*.jsonl")):
    for line in jsonl.read_text(encoding="utf-8", errors="ignore").splitlines():
        if "MarkingController.php" not in line:
            continue
        try:
            obj = json.loads(line)
        except json.JSONDecodeError:
            continue
        for part in obj.get("message", {}).get("content", []):
            if part.get("type") != "tool_use":
                continue
            inp = part.get("input") or {}
            if rel_path(str(inp.get("path", ""))) is None:
                continue
            order += 1
            name = part.get("name", "")
            if name == "Write":
                ops.append((order, "write", inp.get("contents", ""), None))
            elif name == "StrReplace":
                ops.append(
                    (
                        order,
                        "replace",
                        inp.get("old_string", ""),
                        inp.get("new_string", ""),
                    )
                )

print(f"Collected {len(ops)} ops")

content: str | None = None
applied = 0
skipped = 0
for op_order, kind, old, new in ops:
    if kind == "write":
        content = old
        applied += 1
    elif kind == "replace" and content is not None and old:
        if old in content:
            content = content.replace(old, new or "", 1)
            applied += 1
        else:
            skipped += 1
            print(f"  SKIP op {op_order}")

print(f"Applied {applied}, skipped {skipped}, final len={len(content or '')}")
if content:
    markers = [
        "function template",
        "function pricings",
        "function customerUsers",
        "function export",
        "markingPayloadForClient",
        "normalizePricingPayload",
        "dedupePricingRows",
        "serializeMarkingRow",
    ]
    for marker in markers:
        print(f"  {marker}: {marker in content}")
    OUT.write_text(content, encoding="utf-8")
    print(f"Wrote {OUT}")
