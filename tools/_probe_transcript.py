import json
from pathlib import Path

jsonl = Path(r"C:\Users\denny\.cursor\projects\c-Pelayaran\agent-transcripts\171a31bc-c4c0-440a-9109-f2ff0da38a5e\171a31bc-c4c0-440a-9109-f2ff0da38a5e.jsonl")
count = 0
for line in jsonl.read_text(encoding="utf-8").splitlines():
    if "ShippingLabelPdfData.php" not in line:
        continue
    obj = json.loads(line)
    for part in obj.get("message", {}).get("content", []):
        if part.get("name") != "Write":
            continue
        inp = part.get("input", {})
        if "ShippingLabelPdfData" in str(inp.get("path", "")):
            body = inp.get("contents", "")
            print("FOUND Write", len(body), "bytes")
            count += 1
        if part.get("name") == "StrReplace" and "ShippingLabelPdfData" in str(inp.get("path", "")):
            print("FOUND StrReplace")
            count += 1
print("count", count)
