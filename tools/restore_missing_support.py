#!/usr/bin/env python3
"""Restore missing app PHP files from agent transcript Write/StrReplace ops."""
from __future__ import annotations

import json
import re
from pathlib import Path

ROOT = Path(r"C:\Pelayaran")
TRANSCRIPTS = Path(r"C:\Users\denny\.cursor\projects\c-Pelayaran\agent-transcripts")

NEEDED = {
    "app/Support/ShippingLabelPdfData.php",
    "app/Support/TerimaBarangPdfData.php",
    "app/Support/TerimaBarangPdfFonts.php",
    "app/Support/TerimaBarangPdfImages.php",
    "app/Support/BarangShippingPricing.php",
    "app/Support/BarangCostPricing.php",
    "app/Support/CompanyRevenueSettings.php",
    "app/Support/SalesRevenueCalculator.php",
    "app/Support/ShipmentEarningsSettlement.php",
    "app/Support/BarangOriginalPackingList.php",
    "app/Support/UploadedImageCompressor.php",
    "app/Http/Controllers/ExportController.php",
}


def rel_path(path: str) -> str | None:
    path = path.replace("\\", "/")
    match = re.search(r"(app/[^'\"]+\.php)", path, re.IGNORECASE)
    return match.group(1) if match else None


def main() -> None:
    contents: dict[str, str] = {}

    for jsonl in sorted(TRANSCRIPTS.rglob("*.jsonl")):
        try:
            lines = jsonl.read_text(encoding="utf-8").splitlines()
        except OSError:
            continue
        for line in lines:
            if "app/" not in line:
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
                rel = rel_path(inp.get("path", ""))
                if rel is None or rel not in NEEDED:
                    continue
                if name == "Write":
                    contents[rel] = inp.get("contents", "")
                elif name == "StrReplace" and rel in contents:
                    old = inp.get("old_string", "")
                    new = inp.get("new_string", "")
                    if old in contents[rel]:
                        contents[rel] = contents[rel].replace(old, new, 1)

    for rel in sorted(NEEDED):
        if rel in contents:
            out = ROOT / rel
            out.parent.mkdir(parents=True, exist_ok=True)
            out.write_text(contents[rel], encoding="utf-8")
            print(f"RESTORED {rel} ({len(contents[rel])} bytes)")
        else:
            print(f"MISSING {rel}")

    print(f"\nTotal restored: {len(contents)}/{len(NEEDED)}")


if __name__ == "__main__":
    main()
