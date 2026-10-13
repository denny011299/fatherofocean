import json
from pathlib import Path

jsonl = Path(r"C:\Users\denny\.cursor\projects\c-Pelayaran\agent-transcripts\171a31bc-c4c0-440a-9109-f2ff0da38a5e\171a31bc-c4c0-440a-9109-f2ff0da38a5e.jsonl")
for line in jsonl.read_text(encoding="utf-8").splitlines():
    if "ExportController.php" not in line or "omsetData" not in line:
        continue
    obj = json.loads(line)
    for part in obj.get("message", {}).get("content", []):
        if part.get("name") == "StrReplace":
            inp = part.get("input", {})
            if "omsetData" in inp.get("new_string", ""):
                print(inp["new_string"][:3000])
                print("---")
