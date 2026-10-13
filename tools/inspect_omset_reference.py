#!/usr/bin/env python3
"""Inspect reference omset xlsx for formulas, values, and styles."""
import re
import zipfile
import xml.etree.ElementTree as ET
from collections import defaultdict

path = r"c:\Users\denny\Downloads\simulasi export omset (1).xlsx"
NS = "http://schemas.openxmlformats.org/spreadsheetml/2006/main"
ns = {"m": NS}


def col_row(ref: str):
    m = re.match(r"([A-Z]+)([0-9]+)", ref)
    return m.group(1), int(m.group(2))


def col_index(col: str) -> int:
    n = 0
    for ch in col:
        n = n * 26 + (ord(ch) - 64)
    return n


with zipfile.ZipFile(path) as z:
    sst = []
    if "xl/sharedStrings.xml" in z.namelist():
        root = ET.fromstring(z.read("xl/sharedStrings.xml"))
        for si in root.findall("m:si", ns):
            texts = []
            for t in si.iter(f"{{{NS}}}t"):
                texts.append(t.text or "")
            sst.append("".join(texts))

    styles_xml = ET.fromstring(z.read("xl/styles.xml"))
    num_fmts = {}
    for nf in styles_xml.findall(".//m:numFmts/m:numFmt", ns):
        num_fmts[nf.get("numFmtId")] = nf.get("formatCode")

    fonts = []
    for f in styles_xml.findall(".//m:fonts/m:font", ns):
        fonts.append(ET.tostring(f, encoding="unicode"))

    fills = []
    for f in styles_xml.findall(".//m:fills/m:fill", ns):
        fills.append(ET.tostring(f, encoding="unicode"))

    borders = []
    for b in styles_xml.findall(".//m:borders/m:border", ns):
        borders.append(ET.tostring(b, encoding="unicode"))

    xfs = []
    for xf in styles_xml.findall(".//m:cellXfs/m:xf", ns):
        xfs.append(
            {
                "numFmtId": xf.get("numFmtId"),
                "fontId": xf.get("fontId"),
                "fillId": xf.get("fillId"),
                "borderId": xf.get("borderId"),
                "applyNumberFormat": xf.get("applyNumberFormat"),
            }
        )

    print("=== NUM FORMATS ===")
    for k, v in sorted(num_fmts.items(), key=lambda x: int(x[0])):
        print(f"  {k}: {v}")
    print(f"cellXfs count: {len(xfs)}")

    sheet = ET.fromstring(z.read("xl/worksheets/sheet1.xml"))
    rows = sheet.findall(".//m:sheetData/m:row", ns)
    dim = sheet.find("m:dimension", ns)
    print(f"dimension: {dim.get('ref') if dim is not None else None}")
    print(f"rows in xml: {len(rows)}")

    cells_by_row: dict[int, dict[str, dict]] = defaultdict(dict)
    for row in rows:
        for c in row.findall("m:c", ns):
            ref = c.get("r")
            col, r = col_row(ref)
            f_el = c.find("m:f", ns)
            v_el = c.find("m:v", ns)
            t = c.get("t")
            style = c.get("s")
            val = None
            if v_el is not None:
                val = v_el.text
                if t == "s":
                    val = sst[int(val)]
            formula = f_el.text if f_el is not None else None
            cells_by_row[r][col] = {"v": val, "f": formula, "s": style, "t": t}

    all_cols = sorted({col for r in cells_by_row for col in cells_by_row[r]}, key=col_index)
    max_row = max(cells_by_row) if cells_by_row else 0
    print(f"cols ({len(all_cols)}): {all_cols}")
    print(f"max_row: {max_row}")

    print("\n=== ROWS 1-6 (all columns) ===")
    for r in range(1, min(7, max_row + 1)):
        print(f"--- row {r} ---")
        for col in all_cols:
            if col in cells_by_row.get(r, {}):
                d = cells_by_row[r][col]
                xf = xfs[int(d["s"])] if d["s"] is not None else None
                nf = xf["numFmtId"] if xf else None
                fmt = num_fmts.get(nf, nf)
                print(f"  {col}{r}: v={d['v']!r} f={d['f']!r} s={d['s']} fmt={fmt}")

    print("\n=== SAMPLE DATA ROWS (first 3 data rows after header) ===")
    for r in range(7, min(10, max_row + 1)):
        print(f"--- row {r} ---")
        for col in all_cols:
            if col in cells_by_row.get(r, {}):
                d = cells_by_row[r][col]
                print(f"  {col}{r}: v={d['v']!r} f={d['f']!r}")

    print("\n=== ALL FORMULAS ===")
    for r in sorted(cells_by_row):
        for col in all_cols:
            d = cells_by_row[r].get(col)
            if d and d["f"]:
                print(f"{col}{r}: {d['f']}")

    print("\n=== MERGES ===")
    merges = sheet.find("m:mergeCells", ns)
    if merges is not None:
        for mc in merges.findall("m:mergeCell", ns):
            print(" ", mc.get("ref"))

    print("\n=== COL WIDTHS ===")
    cols_el = sheet.find("m:cols", ns)
    if cols_el is not None:
        for col_el in cols_el.findall("m:col", ns):
            print(" ", col_el.attrib)
