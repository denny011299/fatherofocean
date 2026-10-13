import json
from pathlib import Path

jsonl = Path(r"C:\Users\denny\.cursor\projects\c-Pelayaran\agent-transcripts\63478112-d4a6-45d3-a967-059bb4780f9b\63478112-d4a6-45d3-a967-059bb4780f9b.jsonl")
for line in jsonl.read_text(encoding="utf-8").splitlines():
    if "OmsetExport.php" not in line:
        continue
    obj = json.loads(line)
    for part in obj.get("message", {}).get("content", []):
        if part.get("name") == "Write" and "OmsetExport.php" in str(part.get("input", {}).get("path", "")):
            body = part["input"]["contents"]
            Path(r"C:\Pelayaran\app\Exports\OmsetExport.php").write_text(body, encoding="utf-8")
            print(f"Wrote OmsetExport.php ({len(body)} bytes)")
