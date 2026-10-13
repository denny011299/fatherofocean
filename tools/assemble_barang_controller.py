#!/usr/bin/env python3
"""Extract per-method bodies from transcript StrReplace chunks and assemble BarangController."""
from __future__ import annotations

import json
import re
from pathlib import Path

TRANSCRIPTS = Path(r"C:\Users\denny\.cursor\projects\c-Pelayaran\agent-transcripts")
OUT = Path(r"C:\Pelayaran\app\Http\Controllers\BarangController.php")
EXTRACT_DIR = Path(r"C:\Pelayaran\tools\_methods")


def extract_methods(source: str) -> dict[str, str]:
    found: dict[str, str] = {}
    pattern = re.compile(
        r"(?P<decl>(?:(?:public|private|protected)\s+)?function\s+(\w+)\s*\([^)]*\)(?:\s*:\s*[^{]+)?\s*\{)",
        re.MULTILINE,
    )
    for match in pattern.finditer(source):
        name = match.group(2)
        start = match.start()
        brace = 0
        i = match.end() - 1
        while i < len(source):
            ch = source[i]
            if ch == "{":
                brace += 1
            elif ch == "}":
                brace -= 1
                if brace == 0:
                    body = source[start : i + 1]
                    if name not in found or len(body) > len(found[name]):
                        found[name] = body
                    break
            i += 1
    return found


def collect_all_methods() -> dict[str, tuple[str, int, str]]:
    best: dict[str, tuple[str, int, str]] = {}
    for jsonl in TRANSCRIPTS.rglob("*.jsonl"):
        for line in jsonl.read_text(encoding="utf-8", errors="replace").splitlines():
            if "BarangController.php" not in line:
                continue
            try:
                obj = json.loads(line)
            except json.JSONDecodeError:
                continue
            for part in obj.get("message", {}).get("content", []):
                if not isinstance(part, dict) or part.get("type") != "tool_use":
                    continue
                inp = part.get("input")
                if not isinstance(inp, dict):
                    continue
                if "BarangController" not in str(inp.get("path", "")):
                    continue
                for field in ("new_string", "old_string", "contents"):
                    text = inp.get(field, "")
                    if not text or "function " not in text:
                        continue
                    for name, body in extract_methods(text).items():
                        if name not in best or len(body) > best[name][1]:
                            best[name] = (body, len(body), f"{jsonl.name}:{field}")
    return best


HEADER = """<?php

declare(strict_types=1);

namespace App\\Http\\Controllers;

use App\\Exports\\BarangListProductsExport;
use App\\Exports\\BarangProductsExport;
use App\\Models\\ActivityLog;
use App\\Models\\Barang;
use App\\Models\\BarangAdditionalPrice;
use App\\Models\\BarangDimension;
use App\\Models\\BarangProduct;
use App\\Models\\City;
use App\\Models\\Invoice;
use App\\Models\\InvoiceItem;
use App\\Models\\Marking;
use App\\Models\\MasterBranch;
use App\\Models\\MasterCbmType;
use App\\Models\\MasterClassification;
use App\\Models\\MasterCountUnit;
use App\\Models\\MasterDepartment;
use App\\Models\\MasterGudangChina;
use App\\Models\\MasterPacking;
use App\\Models\\MasterPackagingType;
use App\\Models\\SystemSetting;
use App\\Models\\User;
use App\\Services\\BarangTrackingService;
use App\\Support\\BarangEditRequestService;
use App\\Support\\BarangOriginalPackingList;
use App\\Support\\BarangShippingPricing;
use App\\Support\\DailyRate;
use App\\Support\\MarkingBarangPricing;
use App\\Support\\ShippingLabelPdfData;
use App\\Support\\TerimaBarangPdfData;
use App\\Support\\UploadedImageCompressor;
use Symfony\\Component\\HttpFoundation\\Response;
use Barryvdh\\DomPDF\\Facade\\Pdf;
use Illuminate\\Database\\Eloquent\\Builder;
use Illuminate\\Database\\Eloquent\\Collection as EloquentCollection;
use Illuminate\\Http\\JsonResponse;
use Illuminate\\Http\\Request;
use Illuminate\\Support\\Collection;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Facades\\Storage;
use Illuminate\\View\\View;
use Maatwebsite\\Excel\\Facades\\Excel;
use Symfony\\Component\\HttpFoundation\\BinaryFileResponse;
use Symfony\\Component\\HttpFoundation\\StreamedResponse;

final class BarangController extends Controller
{
"""

# Preferred method order (public API first, then private helpers)
ORDER = [
    "index", "create", "edit", "data", "store", "show", "update",
    "executeBarangUpdate", "destroy", "updateDimensions", "lookupIndex",
    "downloadInvoice", "downloadOriginalPackingList", "exportTerimaBarangPdf",
    "exportShippingLabelPdf", "exportProductsExcel", "exportListProductsExcel",
    "uploadProductDimensionPhoto", "import",
    # private helpers - alphabetical after public
]

SKIP = {"__construct", "shouldSubmitEditRequest", "reject", "approve"}


def main() -> None:
    methods = collect_all_methods()
    EXTRACT_DIR.mkdir(parents=True, exist_ok=True)

    ordered_names = [n for n in ORDER if n in methods and n not in SKIP]
    remaining = sorted(n for n in methods if n not in ordered_names and n not in SKIP)
    all_names = ordered_names + remaining

    parts = [HEADER]
    for name in all_names:
        body, size, src = methods[name]
        parts.append("    " + body.strip() + "\n\n")
        (EXTRACT_DIR / f"{name}.php").write_text(body, encoding="utf-8")

    parts.append("}\n")
    content = "".join(parts)
    OUT.write_text(content, encoding="utf-8")

    print(f"Wrote {OUT} ({len(content)} bytes, {len(all_names)} methods)")
    required = [
        "edit", "create", "prepareBarangUpdate", "submitBarangEditRequest",
        "executePreparedBarangUpdate", "MarkingBarangPricing", "requiresApproval",
    ]
    for r in required:
        print(f"  {r}: {r in content}")


if __name__ == "__main__":
    main()
