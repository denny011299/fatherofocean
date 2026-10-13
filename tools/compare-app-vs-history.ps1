# Compare app/ files on disk vs Cursor history — flag truncated/incomplete files.
param(
    [switch]$Restore,
    [switch]$WhatIf
)

$ErrorActionPreference = 'Stop'
$ProjectRoot = 'C:\Pelayaran'
$HistoryRoot = "$env:APPDATA\Cursor\User\History"
$AppDir = Join-Path $ProjectRoot 'app'
$Cutoff = [DateTimeOffset]::Parse('2026-06-20T00:00:00+07:00').ToUnixTimeMilliseconds()
$MinRatio = 0.85  # flag if disk file < 85% of history size

$plan = @{}
Get-ChildItem $HistoryRoot -Recurse -Filter 'entries.json' | ForEach-Object {
    try {
        $json = Get-Content $_.FullName -Raw | ConvertFrom-Json
        if ($json.resource -notmatch 'file:///c%3A/Pelayaran/app/') { return }
        $relPath = [System.Uri]::UnescapeDataString($json.resource) `
            -replace '^file:///c:/Pelayaran/app/', '' -replace '/', '\'
        $candidates = @()
        foreach ($entry in @($json.entries)) {
            if ($entry.timestamp -ge $Cutoff) { continue }
            $histFile = Join-Path $_.DirectoryName $entry.id
            if (-not (Test-Path $histFile)) { continue }
            $candidates += [PSCustomObject]@{
                File = $histFile; Size = (Get-Item $histFile).Length; Timestamp = $entry.timestamp
            }
        }
        if (-not $candidates) { return }
        $pick = $candidates | Sort-Object Size -Descending | Select-Object -First 1
        $plan[$relPath] = $pick
    } catch {}
}

$truncated = @()
$missing = @()
$restored = @()

foreach ($kv in ($plan.GetEnumerator() | Sort-Object Key)) {
    $rel = $kv.Key
    $hist = $kv.Value
    $dest = Join-Path $AppDir $rel
    if (-not (Test-Path $dest)) {
        $missing += [PSCustomObject]@{ Rel = $rel; HistSize = $hist.Size; DiskSize = 0; Source = $hist.File }
        if ($Restore -and -not $WhatIf) {
            $parent = Split-Path $dest -Parent
            if (-not (Test-Path $parent)) { New-Item -ItemType Directory -Path $parent -Force | Out-Null }
            Copy-Item $hist.File $dest -Force
            $restored += $rel
        }
        continue
    }
    $diskSize = (Get-Item $dest).Length
    if ($diskSize -lt ($hist.Size * $MinRatio)) {
        $truncated += [PSCustomObject]@{
            Rel = $rel; HistSize = $hist.Size; DiskSize = $diskSize
            Ratio = [math]::Round($diskSize / $hist.Size * 100, 1); Source = $hist.File
        }
        if ($Restore -and -not $WhatIf) {
            Copy-Item $hist.File $dest -Force
            $restored += $rel
        }
    }
}

Write-Host "=== History vs Disk Comparison ==="
Write-Host "History indexed: $($plan.Count)"
Write-Host "Missing on disk: $($missing.Count)"
Write-Host "Truncated (<$($MinRatio*100)% of history): $($truncated.Count)"
if ($Restore) { Write-Host "Restored: $($restored.Count)" }

if ($missing.Count -gt 0) {
    Write-Host "`n--- MISSING ---"
    $missing | ForEach-Object { Write-Host ("  {0} (history {1}b)" -f $_.Rel, $_.HistSize) }
}
if ($truncated.Count -gt 0) {
    Write-Host "`n--- TRUNCATED ---"
    $truncated | ForEach-Object { Write-Host ("  {0}: disk={1}b history={2}b ({3}%)" -f $_.Rel, $_.DiskSize, $_.HistSize, $_.Ratio) }
}
if ($restored.Count -gt 0) {
    Write-Host "`n--- RESTORED ---"
    $restored | ForEach-Object { Write-Host "  $_" }
}
