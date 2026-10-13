#!/usr/bin/env python3
"""Full extraction of simulasi export omset reference xlsx."""
import re
import zipfile
import xml.etree.ElementTree as ET
from collections import defaultdict

path = r"c:\Users\denny\Downloads\simulasi export omset (1).xlsx"
NS = "http://schemas.openxmlformats.org/spreadsheetml/2006/main"
ns = {"m": NS}


def col_index(col: str) -> int:
    n = 0
    for ch in col:
        n = n * 26 + (ord(ch) - 64)
    return n


with zipfile.ZipFile(path) as z:
    sst = []
    root = ET.fromstring(z.read("xl/sharedStrings.xml"))
    for si in root.findall("m:si", ns):
        sst.append("".join((t.text or "") for t in si.iter(f"{{{NS}}}t")))

    styles_xml = ET.fromstring(z.read("xl/styles.xml"))
    num_fmts = {"0": "General"}
    for nf in styles_xml.findall(".//m:numFmts/m:numFmt", ns):
        num_fmts[nf.get("numFmtId")] = nf.get("formatCode")

    fonts = []
    for f in styles_xml.findall(".//m:fonts/m:font", ns):
        bold = f.find("m:b", ns) is not None
        sz = f.find("m:sz", ns)
        color = f.find("m:color", ns)
        fonts.append({
            "bold": bold,
            "size": sz.get("val") if sz is not None else None,
            "color": color.get("rgb") if color is not None and color.get("rgb") else color.get("theme") if color is not None else None,
        })

    fills = []
    for f in styles_xml.findall(".//m:fills/m:fill", ns):
        fg = f.find(".//m:fgColor", ns)
        fills.append(fg.get("rgb") if fg is not None and fg.get("rgb") else fg.get("theme") if fg is not None else None)

    xfs = []
    for xf in styles_xml.findall(".//m:cellXfs/m:xf", ns):
        xfs.append({
            "numFmtId": xf.get("numFmtId"),
            "fontId": int(xf.get("fontId", 0)),
            "fillId": int(xf.get("fillId", 0)),
            "borderId": xf.get("borderId"),
            "applyNumberFormat": xf.get("applyNumberFormat"),
        })

    sheet = ET.fromstring(z.read("xl/worksheets/sheet1.xml"))
    rows_el = sheet.findall(".//m:sheetData/m:row", ns)
    print(f"ROWS: {len(rows_el)}")
    dim = sheet.find("m:dimension", ns)
    print(f"DIMENSION: {dim.get('ref') if dim is not None else None}")

    cells = defaultdict(dict)
    for row in rows_el:
        rn = int(row.get("r"))
        rh = row.get("ht")
        for c in row.findall("m:c", ns):
            ref = c.get("r")
            col = re.match(r"([A-Z]+)", ref).group(1)
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
            cells[rn][col] = {"v": val, "f": formula, "s": style, "ht": rh}

    all_cols = sorted({col for r in cells for col in cells[r]}, key=col_index)
    max_row = max(cells) if cells else 0
    print(f"COLS ({len(all_cols)}): {all_cols}")
    print(f"MAX_ROW: {max_row}")

    print("\n=== HEADER ROW 1 ===")
    for col in all_cols:
        if col in cells.get(1, {}):
            d = cells[1][col]
            xf = xfs[int(d["s"])] if d["s"] else {}
            print(f"  {col}1: {d['v']!r} s={d['s']} font={fonts[xf.get('fontId',0)]} fill={fills[xf.get('fillId',0)]}")

    print("\n=== ROW STRUCTURE (all rows) ===")
    for r in sorted(cells.keys()):
        row_cols = []
        for col in all_cols:
            if col not in cells[r]:
                continue
            d = cells[r][col]
            if d["v"] is not None or d["f"]:
                short = d["f"] if d["f"] else repr(d["v"])[:40]
                row_cols.append(f"{col}={short}")
        ht = next((cells[r][c].get("ht") for c in cells[r] if cells[r][c].get("ht")), None)
        print(f"R{r} ht={ht}: {' | '.join(row_cols[:12])}{'...' if len(row_cols)>12 else ''}")

    print("\n=== MERGES ===")
    merges = sheet.find("m:mergeCells", ns)
    if merges is not None:
        for mc in merges.findall("m:mergeCell", ns):
            print(f"  {mc.get('ref')}")

    print("\n=== COL WIDTHS / HIDDEN ===")
    cols_el = sheet.find("m:cols", ns)
    if cols_el is not None:
        for col_el in cols_el.findall("m:col", ns):
            print(f"  {col_el.attrib}")

    print("\n=== STYLE INDEX MAP (used in data) ===")
    used_styles = sorted({cells[r][c]["s"] for r in cells for c in cells[r] if cells[r][c]["s"]})
    for sid in used_styles:
        xf = xfs[int(sid)]
        nf = num_fmts.get(xf["numFmtId"], xf["numFmtId"])
        print(f"  s={sid}: font={fonts[xf['fontId']]} fill={fills[xf['fillId']]} numFmt={nf!r}")

    print("\n=== ROW 13-14-22 DETAIL (summary structure) ===")
    for r in [13, 14, 21, 22, 27, 28]:
        print(f"--- row {r} ---")
        for col in all_cols:
            if col in cells.get(r, {}):
                d = cells[r][col]
                print(f"  {col}: v={d['v']!r} f={d['f']!r} s={d['s']}")
