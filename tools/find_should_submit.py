import json
import re
from pathlib import Path

TRANSCRIPTS = Path(r"C:\Users\denny\.cursor\projects\c-Pelayaran\agent-transcripts")
needle = "shouldSubmitEditRequest"

for jsonl in TRANSCRIPTS.rglob("*.jsonl"):
    for line in jsonl.read_text(encoding="utf-8").splitlines():
        if needle not in line:
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
            path = inp.get("path", "")
            if "Barang" not in path:
                continue
            if name in ("Write", "WriteFile"):
                print("WRITE", jsonl.name, path, len(inp.get("contents", "")))
            elif name == "StrReplace" and needle in (inp.get("new_string", "") + inp.get("old_string", "")):
                print("PATCH", jsonl.name, path)
                print(inp.get("new_string", "")[:2000])
                print("---")
