#!/usr/bin/env python3
import json
from pathlib import Path

TRANSCRIPT = Path(
    r"C:\Users\denny\.cursor\projects\c-Pelayaran\agent-transcripts"
    r"\171a31bc-c4c0-440a-9109-f2ff0da38a5e\171a31bc-c4c0-440a-9109-f2ff0da38a5e.jsonl"
)
text = TRANSCRIPT.read_text(encoding="utf-8", errors="replace")

patterns = [
    "persistRelations",
    "deferAfterUpdate",
    "isEmptyProductInputRow",
    "eligibleForDeliveryTrip",
    "itemsSummary",
    "syncEtdTracking",
    "applyProductCalculations",
    "productPayloadFromInputRow",
    "filterByPricingItemId",
    "awaitingContainerLoad",
    "isContainerLoadable",
    "getApprovalStatusBadgeAttribute",
    "shipmentStatusLabel",
    "normalizeShipmentStatus",
    "function updateBarang",
    "function insertBarang",
    "public const STATUS_",
    "public const APPROVAL_",
    "public const SHIPMENT_STATUSES",
]

for pat in patterns:
    idx = text.find(pat)
    if idx < 0:
        continue
    snippet = text[idx : idx + 2000]
    print(f"\n{'=' * 60}\n{pat}\n{'=' * 60}")
    print(snippet.replace("\\n", "\n")[:1800])
