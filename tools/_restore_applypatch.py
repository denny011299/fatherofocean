import json
import re
from pathlib import Path

ROOT = Path(r"C:\Pelayaran")
TRANSCRIPTS = Path(r"C:\Users\denny\.cursor\projects\c-Pelayaran\agent-transcripts")

contents: dict[str, str] = {}

for jsonl in TRANSCRIPTS.rglob("*.jsonl"):
    for line in jsonl.read_text(encoding="utf-8", errors="replace").splitlines():
        if "ApplyPatch" not in line or "app/" not in line and "app\\" not in line:
            continue
        try:
            obj = json.loads(line)
        except json.JSONDecodeError:
            continue
        for part in obj.get("message", {}).get("content", []):
            if part.get("name") not in ("ApplyPatch", "apply_patch"):
                continue
            inp = part.get("input")
            patch = inp if isinstance(inp, str) else inp.get("patch", "") if isinstance(inp, dict) else ""
            if not patch:
                continue
            for block in re.finditer(
                r"\*\*\* Add File: ([^\n]+)\n\+([\s\S]*?)(?=\n\*\*\* |\Z)",
                patch,
            ):
                path = block.group(1).strip().replace("\\", "/")
                if "/app/" not in path and not path.startswith("app/"):
                    m = re.search(r"(app/.+\.php)", path)
                    if not m:
                        continue
                    path = m.group(1)
                body = "\n".join(
                    line[1:] if line.startswith("+") else line
                    for line in block.group(2).splitlines()
                )
                if body.strip():
                    contents[path.split("Pelayaran/")[-1] if "Pelayaran/" in path else path] = body + "\n"

for rel, body in sorted(contents.items()):
    rel = rel.replace("c:/Pelayaran/", "").replace("C:/Pelayaran/", "")
    if not rel.startswith("app/"):
        continue
    out = ROOT / rel
    if out.exists():
        continue
    out.parent.mkdir(parents=True, exist_ok=True)
    out.write_text(body, encoding="utf-8")
    print(f"Wrote {rel} ({len(body)} bytes)")

print(f"Total new files: {len(contents)}")
