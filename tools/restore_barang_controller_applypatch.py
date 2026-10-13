#!/usr/bin/env python3
"""Replay Write/StrReplace/ApplyPatch ops from agent transcripts for BarangController."""
from __future__ import annotations

import json
import re
from pathlib import Path

ROOT = Path(r"C:\Pelayaran")
TRANSCRIPTS = Path(r"C:\Users\denny\.cursor\projects\c-Pelayaran\agent-transcripts")
REL = "app/Http/Controllers/BarangController.php"
TARGET_PATH = ROOT / REL


def rel_path(path: str) -> str | None:
    path = path.replace("\\", "/")
    m = re.search(r"(app/Http/Controllers/BarangController\.php)", path)
    return m.group(1) if m else None


def apply_unified_diff(content: str, patch: str) -> str | None:
    """Apply a minimal unified diff from ApplyPatch input."""
    lines = patch.splitlines()
    file_marker = None
    for line in lines:
        if line.startswith("*** Update File:") or line.startswith("*** Add File:"):
            file_marker = line
            break
    if file_marker is None:
        return None

    hunks = re.split(r"^@@\s*$", patch, flags=re.MULTILINE)
    if len(hunks) <= 1:
        return None

    result_lines = content.splitlines(keepends=True)
    if not result_lines and content == "":
        result_lines = []

    for hunk_body in hunks[1:]:
        hunk_lines = hunk_body.splitlines()
        if not hunk_lines:
            continue

        old_chunk: list[str] = []
        new_chunk: list[str] = []
        for hl in hunk_lines:
            if hl.startswith("***"):
                break
            if hl.startswith("-"):
                old_chunk.append(hl[1:] + "\n")
            elif hl.startswith("+"):
                new_chunk.append(hl[1:] + "\n")
            elif hl.startswith(" "):
                old_chunk.append(hl[1:] + "\n")
                new_chunk.append(hl[1:] + "\n")

        if not old_chunk:
            continue

        old_text = "".join(old_chunk)
        full_text = "".join(result_lines)
        idx = full_text.find(old_text)
        if idx == -1:
            return None
        new_text = full_text[:idx] + "".join(new_chunk) + full_text[idx + len(old_text) :]
        result_lines = new_text.splitlines(keepends=True)

    return "".join(result_lines)


def iter_ops():
    for jsonl in sorted(TRANSCRIPTS.rglob("*.jsonl")):
        try:
            lines = jsonl.read_text(encoding="utf-8", errors="replace").splitlines()
        except OSError:
            continue
        for line in lines:
            if "BarangController" not in line:
                continue
            try:
                obj = json.loads(line)
            except json.JSONDecodeError:
                continue
            msg = obj.get("message", {})
            content = msg.get("content", [])
            if isinstance(content, str):
                continue
            for part in content:
                if not isinstance(part, dict) or part.get("type") != "tool_use":
                    continue
                name = part.get("name", "")
                inp = part.get("input")
                if not isinstance(inp, dict):
                    continue
                rel = rel_path(str(inp.get("path", "")))
                if rel != REL:
                    if name == "ApplyPatch" and REL.replace("/", "\\") in str(inp):
                        rel = REL
                    elif name == "ApplyPatch":
                        patch = str(inp)
                        if "BarangController.php" in patch:
                            rel = REL
                        else:
                            continue
                    else:
                        continue
                yield jsonl.name, name, inp


def main() -> None:
    contents: str | None = None
    log: list[str] = []
    ok = fail = 0

    for src, name, inp in iter_ops():
        if name in ("Write", "WriteFile"):
            contents = inp.get("contents", "")
            log.append(f"{src} {name} ({len(contents)} bytes)")
            ok += 1
        elif name == "StrReplace" and contents is not None:
            old = inp.get("old_string", "")
            new = inp.get("new_string", "")
            if old in contents:
                contents = contents.replace(old, new, 1)
                log.append(f"{src} StrReplace OK")
                ok += 1
            else:
                log.append(f"{src} StrReplace FAILED")
                fail += 1
        elif name == "ApplyPatch" and contents is not None:
            patch = inp if isinstance(inp, str) else str(inp)
            applied = apply_unified_diff(contents, patch)
            if applied is not None:
                contents = applied
                log.append(f"{src} ApplyPatch OK")
                ok += 1
            else:
                log.append(f"{src} ApplyPatch FAILED")
                fail += 1
        elif name in ("StrReplace", "ApplyPatch"):
            log.append(f"{src} {name} SKIP (no base)")

    if contents is None:
        print("No base Write found for BarangController")
        return

    TARGET_PATH.parent.mkdir(parents=True, exist_ok=True)
    TARGET_PATH.write_text(contents, encoding="utf-8")

    needles = [
        "prepareBarangUpdate",
        "submitBarangEditRequest",
        "executePreparedBarangUpdate",
        "executeBarangUpdate",
        "function edit",
        "function create",
        "MarkingBarangPricing",
        "requiresApproval",
        "saved_directly",
    ]
    print(f"Wrote {REL} ({len(contents)} bytes)")
    print(f"ok={ok} fail={fail}")
    for n in needles:
        print(f"  {n}: {n in contents}")
    print(f"\nLast 20 ops:")
    for line in log[-20:]:
        print(" ", line)


if __name__ == "__main__":
    main()
