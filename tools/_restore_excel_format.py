import json
from pathlib import Path

jsonl = Path(r"C:\Users\denny\.cursor\projects\c-Pelayaran\agent-transcripts\46ca22b5-06bd-460f-a6dc-0f2d83227006\subagents\7e0e8d20-7cf4-453b-96d6-13e0cf05738e.jsonl")
for line in jsonl.read_text(encoding="utf-8").splitlines():
    if "ExcelDecimalFormat.php" not in line:
        continue
    obj = json.loads(line)
    for part in obj.get("message", {}).get("content", []):
        if part.get("name") == "Write" and "ExcelDecimalFormat" in str(part.get("input", {}).get("path", "")):
            body = part["input"]["contents"]
            # get latest write - keep overwriting
            latest = body
if 'latest' in dir():
    Path(r"C:\Pelayaran\app\Support\ExcelDecimalFormat.php").write_text(latest, encoding="utf-8")
    print(len(latest))
