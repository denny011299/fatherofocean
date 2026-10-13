import json
import re
from pathlib import Path

TRANSCRIPTS = Path(r"C:\Users\denny\.cursor\projects\c-Pelayaran\agent-transcripts")
TARGETS = {"app/Support/BarangEditRequestService.php"}


def rel_path(path: str) -> str | None:
    path = path.replace("\\", "/")
    m = re.search(r"(app/[^'\"]+\.php)", path)
    return m.group(1) if m else None


count = 0
for jsonl in TRANSCRIPTS.rglob("*.jsonl"):
    for line in jsonl.read_text(encoding="utf-8").splitlines():
        if "BarangEditRequestService" not in line:
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
                print("bad inp", type(inp), jsonl.name, name)
                continue
            rel = rel_path(inp.get("path", ""))
            if name in ("Write", "WriteFile") and rel:
                count += 1
                print("FOUND", jsonl.name, name, rel, len(inp.get("contents", "")))

print("total", count)
