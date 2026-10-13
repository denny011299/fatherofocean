import json, re
from pathlib import Path

ROOT = Path(r"C:\Pelayaran")
TRANSCRIPTS = Path(r"C:\Users\denny\.cursor\projects\c-Pelayaran\agent-transcripts")
TARGET = "app/Http/Controllers/SalesIncentiveController.php"

def rel_path(path: str) -> str | None:
    path = path.replace("\\", "/")
    m = re.search(r"(app/[^'\"]+\.php)", path, re.I)
    return m.group(1) if m else None

contents = {}
for jsonl in sorted(TRANSCRIPTS.rglob("*.jsonl")):
    for line in jsonl.read_text(encoding="utf-8", errors="replace").splitlines():
        if TARGET.replace("/", "\\") not in line and TARGET not in line:
            continue
        try:
            obj = json.loads(line)
        except json.JSONDecodeError:
            continue
        for part in obj.get("message", {}).get("content", []):
            if part.get("type") != "tool_use":
                continue
            inp = part.get("input", {})
            rel = rel_path(inp.get("path", ""))
            if rel != TARGET:
                continue
            if part.get("name") == "Write":
                contents[rel] = inp.get("contents", "")
            elif part.get("name") == "StrReplace" and rel in contents:
                old, new = inp.get("old_string", ""), inp.get("new_string", "")
                if old in contents[rel]:
                    contents[rel] = contents[rel].replace(old, new, 1)

if TARGET in contents:
    out = ROOT / TARGET
    out.write_text(contents[TARGET], encoding="utf-8")
    print(f"Wrote {TARGET} ({len(contents[TARGET])} bytes)")
else:
    print("NOT FOUND")
