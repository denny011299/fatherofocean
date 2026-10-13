#!/usr/bin/env python3
import json
from pathlib import Path

TRANSCRIPT = Path(
    r"C:\Users\denny\.cursor\projects\c-Pelayaran\agent-transcripts"
    r"\171a31bc-c4c0-440a-9109-f2ff0da38a5e\171a31bc-c4c0-440a-9109-f2ff0da38a5e.jsonl"
)
content = Path(r"C:\Pelayaran\app\Models\Barang.reconstructed.php").read_text(encoding="utf-8")
failed = []
for line in TRANSCRIPT.read_text(encoding="utf-8", errors="replace").splitlines():
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
        old = inp.get("old_string", "")
        if old and old not in content:
            failed.append(old[:120].replace("\n", " "))

print(f"Still missing {len(failed)} patches")
for f in failed[:25]:
    print("-", f)
