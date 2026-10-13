#!/usr/bin/env python3
"""Replay ALL transcript ops for a PHP file."""
from __future__ import annotations

import json
import re
import sys
from dataclasses import dataclass
from pathlib import Path

TRANSCRIPTS = Path(r"C:\Users\denny\.cursor\projects\c-Pelayaran\agent-transcripts")
ROOT = Path(r"C:\Pelayaran")


def rel_path(path: str) -> str | None:
    path = path.replace("\\", "/")
    m = re.search(r"(app/[^'\"]+\.php)", path, re.I)
    return m.group(1) if m else None


@dataclass
class Op:
    order: int
    kind: str
    content: str | None = None
    old: str | None = None
    new: str | None = None


def replay(target_rel: str) -> tuple[str | None, int, int]:
    ops: list[Op] = []
    order = 0
    for jsonl in sorted(TRANSCRIPTS.rglob("*.jsonl")):
        for line in jsonl.read_text(encoding="utf-8", errors="ignore").splitlines():
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
                inp = part.get("input") or {}
                if rel_path(str(inp.get("path", ""))) != target_rel:
                    continue
                order += 1
                if name == "Write":
                    ops.append(Op(order, "write", content=inp.get("contents", "")))
                elif name == "StrReplace":
                    ops.append(Op(order, "replace", old=inp.get("old_string", ""), new=inp.get("new_string", "")))

    content: str | None = None
    applied = skipped = 0
    for op in ops:
        if op.kind == "write":
            content = op.content or ""
            applied += 1
        elif op.kind == "replace" and content is not None and op.old:
            if op.old in content:
                content = content.replace(op.old, op.new or "", 1)
                applied += 1
            else:
                skipped += 1
    return content, applied, skipped


if __name__ == "__main__":
    target = sys.argv[1] if len(sys.argv) > 1 else "app/Support/TerimaBarangPdfData.php"
    content, applied, skipped = replay(target)
    print(f"{target}: applied={applied} skipped={skipped} len={len(content or '')}")
    if content and len(content) > 1000:
        out = ROOT / target
        out.write_text(content, encoding="utf-8")
        print(f"Wrote {out}")
