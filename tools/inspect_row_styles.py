#!/usr/bin/env python3
"""Per-column style map for reference rows 1-13."""
import re, zipfile, xml.etree.ElementTree as ET
from collections import defaultdict

path = r"c:\Users\denny\Downloads\simulasi export omset (1).xlsx"
NS = "http://schemas.openxmlformats.org/spreadsheetml/2006/main"
ns = {"m": NS}

with zipfile.ZipFile(path) as z:
    sst = []
    for si in ET.fromstring(z.read("xl/sharedStrings.xml")).findall("m:si", ns):
        sst.append("".join((t.text or "") for t in si.iter(f"{{{NS}}}t")))
    sheet = ET.fromstring(z.read("xl/worksheets/sheet1.xml"))
    for row in sheet.findall(".//m:sheetData/m:row", ns):
        rn = int(row.get("r"))
        if rn > 13:
            continue
        cols = []
        for c in row.findall("m:c", ns):
            ref = c.get("r")
            col = re.match(r"([A-Z]+)", ref).group(1)
            v = c.find("m:v", ns)
            f = c.find("m:f", ns)
            val = None
            if v is not None:
                val = v.text
                if c.get("t") == "s":
                    val = sst[int(val)]
            cols.append(f"{col}:s={c.get('s')} v={val!r} f={f.text if f is not None else None}")
        print(f"R{rn}: " + " | ".join(cols))
