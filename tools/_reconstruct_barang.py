#!/usr/bin/env python3
"""Replay Write/StrReplace ops on Barang.php from all transcripts."""
import json
from pathlib import Path

ROOT = Path(r"C:\Pelayaran\app\Models\Barang.php")
TRANSCRIPTS = Path(r"C:\Users\denny\.cursor\projects\c-Pelayaran\agent-transcripts")

content = None
ops = []

for jsonl in sorted(TRANSCRIPTS.rglob("*.jsonl")):
    for line in jsonl.read_text(encoding="utf-8", errors="replace").splitlines():
        if "app/Models/Barang.php" not in line:
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
            if isinstance(inp, str):
                continue
            if not isinstance(inp, dict):
                continue
            path = str(inp.get("path", "")).replace("\\", "/")
            if not path.endswith("app/Models/Barang.php"):
                continue
            if name == "Write":
                content = inp.get("contents", content)
                ops.append((jsonl.name, "Write", len(content or "")))
            elif name == "StrReplace" and content is not None:
                old = inp.get("old_string", "")
                new = inp.get("new_string", "")
                if old and old in content:
                    content = content.replace(old, new, 1)
                    ops.append((jsonl.name, "StrReplace OK", len(content)))
                elif old:
                    ops.append((jsonl.name, "StrReplace FAILED", len(old)))

print(f"Ops: {len(ops)}")
if content:
    print(f"Final size: {len(content)} bytes, {content.count(chr(10))+1} lines")
    out = Path(r"C:\Pelayaran\app\Models\Barang.reconstructed.php")
    out.write_text(content, encoding="utf-8")
    print(f"Wrote {out}")
    print("Last 20 ops:")
    for op in ops[-20:]:
        print(" ", op)
else:
    print("No Write found; only StrReplace from existing base")
    content = ROOT.read_text(encoding="utf-8")
    # retry only 171a transcript with current base
    t171 = TRANSCRIPTS / "171a31bc-c4c0-440a-9109-f2ff0da38a5e" / "171a31bc-c4c0-440a-9109-f2ff0da38a5e.jsonl"
    ok = fail = 0
    for line in t171.read_text(encoding="utf-8", errors="replace").splitlines():
        try:
            obj = json.loads(line)
        except json.JSONDecodeError:
            continue
        for part in obj.get("message", {}).get("content", []):
            if part.get("name") != "StrReplace":
                continue
            inp = part.get("input")
            if not isinstance(inp, dict):
                continue
            path = str(inp.get("path", "")).replace("\\", "/")
            if not path.endswith("app/Models/Barang.php"):
                continue
            old, new = inp.get("old_string", ""), inp.get("new_string", "")
            if old and old in content:
                content = content.replace(old, new, 1)
                ok += 1
            elif old:
                fail += 1
    print(f"171a replay: {ok} ok, {fail} failed, size {len(content)}")
    out = Path(r"C:\Pelayaran\app\Models\Barang.reconstructed.php")
    out.write_text(content, encoding="utf-8")
    print(f"Wrote {out}")
