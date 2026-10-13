import json
from pathlib import Path

jsonl = Path(r"C:\Users\denny\.cursor\projects\c-Pelayaran\agent-transcripts\171a31bc-c4c0-440a-9109-f2ff0da38a5e\171a31bc-c4c0-440a-9109-f2ff0da38a5e.jsonl")
for line in jsonl.read_text(encoding="utf-8").splitlines():
    if "SalesIncentive" not in line:
        continue
    obj = json.loads(line)
    for part in obj.get("message", {}).get("content", []):
        if part.get("name") not in ("ApplyPatch", "apply_patch"):
            continue
        inp = part.get("input", "")
        patch = inp if isinstance(inp, str) else inp.get("patch", "") if isinstance(inp, dict) else ""
        if "SalesIncentive" in patch:
            print(patch)
