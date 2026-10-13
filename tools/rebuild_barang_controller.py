import json
import re
from pathlib import Path

TRANSCRIPTS = Path(r"C:\Users\denny\.cursor\projects\c-Pelayaran\agent-transcripts")
REL = "app/Http/Controllers/BarangController.php"

def rel_path(path):
    path = path.replace("\\", "/")
    m = re.search(r"(app/[^'\"]+\.php)", path)
    return m.group(1) if m else None

contents = {}
ok = fail = skip = 0
for jsonl in sorted(TRANSCRIPTS.rglob("*.jsonl")):
    for line in jsonl.read_text(encoding="utf-8").splitlines():
        if "BarangController.php" not in line:
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
            if rel_path(inp.get("path", "")) != REL:
                continue
            if name in ("Write", "WriteFile"):
                contents[REL] = inp.get("contents", "")
            elif name == "StrReplace":
                if REL not in contents:
                    skip += 1
                    continue
                old = inp.get("old_string", "")
                new = inp.get("new_string", "")
                if old in contents[REL]:
                    contents[REL] = contents[REL].replace(old, new, 1)
                    ok += 1
                else:
                    fail += 1

print(f"ok={ok} fail={fail} skip={skip} final_len={len(contents.get(REL, ''))}")
if REL in contents:
    c = contents[REL]
    for needle in ["shouldSubmitEditRequest", "submitBarangEditRequest", "prepareBarangUpdate", "saved_directly", "requiresApproval"]:
        print(needle, needle in c)
    Path(r"C:\Pelayaran\app\Http\Controllers\BarangController.php").write_text(c, encoding="utf-8")
    print("written")
