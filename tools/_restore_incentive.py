import json, re
from pathlib import Path

ROOT = Path(r"C:\Pelayaran")
TRANSCRIPTS = Path(r"C:\Users\denny\.cursor\projects\c-Pelayaran\agent-transcripts")
TARGETS = {
    "app/Support/SalesIncentiveService.php",
    "app/Http/Controllers/SalesIncentiveController.php",
    "app/Models/SalesIncentiveLine.php",
    "app/Models/SalesIncentiveTier.php",
}

def rel_path(path: str) -> str | None:
    path = path.replace("\\", "/")
    m = re.search(r"(app/[^'\"]+\.php)", path, re.I)
    return m.group(1) if m else None

contents = {}
for jsonl in sorted(TRANSCRIPTS.rglob("*.jsonl")):
    for line in jsonl.read_text(encoding="utf-8", errors="replace").splitlines():
        if "app\\" not in line and "app/" not in line:
            continue
        try:
            obj = json.loads(line)
        except json.JSONDecodeError:
            continue
        for part in obj.get("message", {}).get("content", []):
            if part.get("type") != "tool_use":
                continue
            inp = part.get("input", {})
            if not isinstance(inp, dict):
                continue
            rel = rel_path(inp.get("path", ""))
            if rel not in TARGETS:
                continue
            if part.get("name") == "Write":
                contents[rel] = inp.get("contents", "")
            elif part.get("name") == "StrReplace" and rel in contents:
                old, new = inp.get("old_string", ""), inp.get("new_string", "")
                if old in contents[rel]:
                    contents[rel] = contents[rel].replace(old, new, 1)

for rel in sorted(TARGETS):
    if rel in contents:
        out = ROOT / rel
        out.parent.mkdir(parents=True, exist_ok=True)
        out.write_text(contents[rel], encoding="utf-8")
        print(f"Wrote {rel} ({len(contents[rel])} bytes)")
    else:
        print(f"MISSING {rel}")
