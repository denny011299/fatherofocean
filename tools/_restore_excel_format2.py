import json, re
from pathlib import Path

TRANSCRIPTS = Path(r"C:\Users\denny\.cursor\projects\c-Pelayaran\agent-transcripts")
TARGET = "app/Support/ExcelDecimalFormat.php"
body = None
for jsonl in sorted(TRANSCRIPTS.rglob("*.jsonl")):
    for line in jsonl.read_text(encoding="utf-8", errors="replace").splitlines():
        if "ExcelDecimalFormat.php" not in line:
            continue
        try:
            obj = json.loads(line)
        except json.JSONDecodeError:
            continue
        for part in obj.get("message", {}).get("content", []):
            if part.get("name") == "Write":
                inp = part.get("input", {})
                if not isinstance(inp, dict):
                    continue
                p = inp.get("path", "").replace("\\", "/")
                if "ExcelDecimalFormat.php" in p:
                    body = inp.get("contents", "")
            elif part.get("name") == "StrReplace":
                inp = part.get("input", {})
                if not isinstance(inp, dict) or body is None:
                    continue
                if "ExcelDecimalFormat.php" in str(inp.get("path", "")):
                    old, new = inp.get("old_string", ""), inp.get("new_string", "")
                    if old in body:
                        body = body.replace(old, new, 1)

if body:
    Path(r"C:\Pelayaran\app\Support\ExcelDecimalFormat.php").write_text(body, encoding="utf-8")
    print("COUNT" in body, "RUPIAH_WHOLE" in body, len(body))
