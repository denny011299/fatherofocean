import json
from pathlib import Path

TRANSCRIPTS = Path(r"C:\Users\denny\.cursor\projects\c-Pelayaran\agent-transcripts")
for jsonl in sorted(TRANSCRIPTS.rglob("*.jsonl")):
    for line in jsonl.read_text(encoding="utf-8", errors="replace").splitlines():
        if "omsetExcel" not in line or "ExportController" not in line:
            continue
        try:
            obj = json.loads(line)
        except json.JSONDecodeError:
            continue
        for part in obj.get("message", {}).get("content", []):
            if part.get("name") != "StrReplace":
                continue
            ns = part.get("input", {}).get("new_string", "")
            if "function omsetExcel" in ns and "OmsetExportDataBuilder" in ns:
                print("FROM", jsonl.name)
                print(ns[:2500])
