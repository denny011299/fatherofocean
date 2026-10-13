import json
import re
from pathlib import Path

TRANSCRIPTS = Path(r"C:\Users\denny\.cursor\projects\c-Pelayaran\agent-transcripts")
target = "BarangController.php"
writes = []

for jsonl in TRANSCRIPTS.rglob("*.jsonl"):
    for line in jsonl.read_text(encoding="utf-8").splitlines():
        if target not in line:
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
            if target not in path:
                continue
            if name in ("Write", "WriteFile"):
                c = inp.get("contents", "")
                writes.append((jsonl.name, len(c), "shouldSubmit" in c, "submitBarangEditRequest" in c, "prepareBarangUpdate" in c))

print("Writes to BarangController:")
for w in sorted(writes, key=lambda x: x[1], reverse=True):
    print(w)
