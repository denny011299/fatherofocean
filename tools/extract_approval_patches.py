import json
from pathlib import Path

TRANSCRIPTS = Path(r"C:\Users\denny\.cursor\projects\c-Pelayaran\agent-transcripts")
keywords = ["requiresApproval", "shouldSubmitEditRequest", "saved_directly", "photo_path", "APPROVAL_REQUIRED"]

for jsonl in TRANSCRIPTS.rglob("*.jsonl"):
    for line in jsonl.read_text(encoding="utf-8").splitlines():
        if not any(k in line for k in keywords):
            continue
        try:
            obj = json.loads(line)
        except json.JSONDecodeError:
            continue
        for part in obj.get("message", {}).get("content", []):
            if part.get("type") != "tool_use" or part.get("name") != "StrReplace":
                continue
            inp = part.get("input")
            if not isinstance(inp, dict):
                continue
            path = inp.get("path", "")
            if "BarangEditRequestService" not in path and "BarangController" not in path:
                continue
            old = inp.get("old_string", "")
            new = inp.get("new_string", "")
            if not any(k in old + new for k in keywords):
                continue
            print("===", jsonl.name, path.split("\\")[-1], "===")
            print("OLD:\n", old[:1500])
            print("NEW:\n", new[:2500])
            print()
